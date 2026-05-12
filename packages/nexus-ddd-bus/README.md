# nexus-ddd-bus

Bus dispatch fabric for the Nexus DDD framework — sync command/query/event buses with the canonical 14-stage middleware pipeline, composite routing, and pluggable slot interfaces.

The package gives domain code a single seam (`CommandBus`, `QueryBus`, `EventBus`) and threads every dispatch through an ordered pipeline of validation, authorization, two-phase idempotency, OCC retry, handler invocation, event drain, metrics, logging, and tracing. Adopters plug in their own `Validator`, `AuthorizationDecider`, `IdempotencyStore`, `PrincipalProvider`, and `MetricsCollector`; the framework supplies defaults that no-op cleanly so an opinion-light bootstrap "just works".

## Quick start

```php
<?php
declare(strict_types=1);

use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Middleware\CanonicalPipelineAssembler;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusBuilder;
use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\Composite;
use Monadial\Nexus\Ddd\Bus\Routing\ExplicitOnly;

$builder = new BusBuilder()
    ->registerHandler(PlaceOrder::class, PlaceOrderHandler::class)
    ->registerHandler(CancelOrder::class, CancelOrderHandler::class)
    ->withCausationDepthCap(32)
    ->withRetryBudgetMs(5_000);

$routing = new Composite(
    [new ExplicitOnly()->explicit(PlaceOrder::class, 'orders')],
    defaultBusName: 'orders',
);

$result = $builder->build(Profile::Sync, hasValidator: true, hasDecider: true, $routing);

$assembler = new CanonicalPipelineAssembler(/* slot collaborators */);
$pipeline  = $assembler->assembleEnvelopePipeline($result, static fn($_): null => null);

$bus = new SyncCommandBus(
    registry: new BusRegistry(Profile::Sync, ['orders' => $bus], [], []),
    index: $result->index,
    pipeline: $pipeline,
    profile: Profile::Sync,
    clock: $clock,
);

$bus->dispatchCommand(new PlaceOrder(/* ... */));
```

## Architecture

### Canonical 14-stage pipeline

`PipelineStage` locks the order; `CanonicalPipelineAssembler` materializes one stack per registered handler at boot.

1. **Causation** — propagate `causationId` / `correlationId`, enforce the depth cap.
2. **OtelSpan** — open a span (no-op default; OpenTelemetry SDK adapter swaps in).
3. **LoggingStart** — INFO `ddd.command.dispatched` with structured context.
4. **MetricsStart** — emit `outcome=started`; stamp `MetricsTimingStamp`.
5. **Validation** — run the application `Validator`; lift non-empty `Violations` to `ValidationFailedException` (terminal).
6. **Authorization** — call `AuthorizationDecider::decide`; denial throws `AccessDeniedException` (terminal).
7. **IdempotencyReserve** *(outer)* — `IdempotencyStore::tryReserve`; runs OUTSIDE the OCC retry loop so retries reuse the token.
8. **OccRetry** — retry `OptimisticLockException` within the configured budget; under `Profile::Actor` a collision is a wiring fault and short-circuits.
9. **Handler** — locate + invoke the handler.
10. **IdempotencyCommit** *(inner)* — `IdempotencyStore::markCompleted`; runs INSIDE the handler TX, AFTER handler, BEFORE event drain.
11. **EventDrain** — `Outbox::flush()`; profile-aware semantics live in the outbox impl.
12. **MetricsEnd** — emit `outcome=succeeded` / classified failure tag; emit `ddd.command.duration_ms` histogram.
13. **LoggingEnd** — INFO `ddd.command.completed` / WARNING `ddd.command.failed`.
14. **SpanClose** — close OTel span (no-op default).

Stages 5 and 6 swap when a handler is annotated `#[Authorize(before: 'validation')]` (panel H4); the flip is baked at boot per handler.

### Profile-aware behavior

`Profile::Sync | Async | Actor` is locked at composition-root time. The pipeline observes it via `IdempotencyReserveMiddleware`, `IdempotencyCommitMiddleware`, and `OccRetryMiddleware`:

- **Sync** — single-threaded request path. Idempotency middleware self-disables (no redelivery surface); OCC retries within the budget.
- **Async** — out-of-process consumer. Idempotency reserve + commit gate redelivery; OCC retries within the budget.
- **Actor** — single-writer actor system. Idempotency reserve + commit active; OCC collision is treated as a wiring fault (`ActorWriterInvariantViolation`).

### Two-phase idempotency

Reserve runs OUTSIDE the OCC retry loop so retries share one token; Commit runs INSIDE the handler TX so the dedup row lands or rolls back atomically with the handler's writes. The `IdempotencyKeyResolver` picks the key from three sources (see Attributes below).

### Boot orchestration

`BusBuilder` accumulates handler registrations, connection bindings, custom middleware splices, and depth/budget caps. `build()` runs reflection once, then four validators in order: missing-validator → missing-decider → in-process-same-DB → composite routing conflict. The output is a `BusBuildResult` carrying the immutable `HandlerAttributeIndex`, the handler map, and adopter middleware splices. `CanonicalPipelineAssembler::assembleEnvelopePipeline()` consumes the result and produces a ready-to-use `PerHandlerPipeline`.

## Slot interfaces

| Slot                  | Default impl                  | What adopters wire                                  |
|-----------------------|-------------------------------|-----------------------------------------------------|
| `Validator`           | none — required when any handler uses `#[Validate]` | Symfony Validator, custom rule engine, etc.    |
| `AuthorizationDecider`| none — required when any handler uses `#[Authorize]`| Symfony Security voter, Casbin, custom RBAC.   |
| `PrincipalProvider`   | `NoPrincipalProvider`         | Symfony `TokenStorageInterface`, JWT decoder.       |
| `IdempotencyStore`    | `InMemoryIdempotencyStore` (tests only) | Doctrine-DBAL adapter (TODO), Redis adapter (TODO). |
| `MetricsCollector`    | `NoOpMetricsCollector`        | Prometheus, StatsD, OpenTelemetry adapters.         |
| `SleepStrategy`       | `BlockingSleepStrategy`       | Swoole sleep, fiber suspend under Async/Actor.      |
| `PayloadRedactor`     | reflection-based default      | Replace only if custom redaction policy is required.|

## Attributes

| Attribute              | Target      | Effect                                                                                       |
|------------------------|-------------|----------------------------------------------------------------------------------------------|
| `#[Validate(groups:)]` | Method      | Runs the handler's parameter through `Validator` with the named groups.                      |
| `#[Authorize(policy, subject?, before?)]` | Method | Calls `AuthorizationDecider::decide($policy, $subject, $ctx)`. `before: 'validation'` flips slots 5 and 6. |
| `#[OnBus(name:)]`      | Class (msg) | Routing hint: pin a message to the named bus when no explicit DSL entry exists.              |
| `#[IdempotencyKey(field:)]` | Class (msg) | Names the message property whose value is the idempotency key.                          |
| `#[Idempotent(off?:)]` | Class       | Opt out of idempotency for a specific message class.                                         |
| `#[InProcess]`         | Method      | Event-handler runs in the source aggregate's transaction. Validated at boot.                 |
| `#[Handler]`           | Method      | Secondary discoverability shortcut for multi-method services.                                |
| `#[Sensitive]`         | Property    | Redacts the property from logged payloads at DEBUG.                                          |

### `#[Authorize]` shapes

```php
// Callable form (preferred — bus stays ignorant of property names)
#[Authorize(policy: 'order.cancel', subject: 'App\\Subjects\\OrderSubject::resolve')]
public function __invoke(CancelOrder $cmd): void { /* ... */ }

// Shortcut form (names a property on the message)
#[Authorize(policy: 'order.cancel', subject: 'orderId')]
public function __invoke(CancelOrder $cmd): void { /* ... */ }
```

### `#[Sensitive]` payload redaction

```php
readonly class PlaceOrder
{
    public function __construct(
        public string $orderId,
        #[Sensitive] public string $cardToken,
    ) {}
}
```

`LoggingStartMiddleware` replaces `cardToken` with `'[REDACTED]'` in DEBUG payload logs. Payload-at-DEBUG is OFF by default; opt in by constructing `LoggingStartMiddleware` with `logPayloadAtDebug: true`.

### `#[IdempotencyKey]` resolution order

`IdempotencyKeyResolver` walks three tiers (umbrella spec §13.2.1):

1. `#[IdempotencyKey(field: 'x')]` — read the named property's value.
2. `MessageMetadata::$headers['nexus.idempotency-key']` if present.
3. Fall back to the framework-assigned `messageId.value()`.

Adapter packages (`nexus-ddd-symfony`, etc.) populate the header from an `X-Nexus-Idempotency-Key` HTTP header at the request boundary.

## Sync-confirmation cookbook (umbrella spec §8.6.1)

The typical HTTP pattern: controller calls `$bus->tryDispatch($cmd)` → on `Either::right` returns 200/202 → on `Either::left` maps the exception to an HTTP error.

```php
public function placeOrder(Request $req): Response
{
    $cmd = new PlaceOrder(orderId: $req->get('orderId') /* ... */);

    return $this->bus->tryDispatch($cmd)->fold(
        onLeft: fn(\Throwable $e) => $this->errorResponse($e),
        onRight: fn(Accepted $_) => new Response('OK', 200),
    );
}
```

The same pattern applies to `$queryBus->tryAsk($q)` and `$eventBus->tryPublish($e)`.

## Handlers may read MessageContext (umbrella spec §7.3)

Handlers can inspect the in-flight `MessageContext` (causation/correlation ids, headers, stamps) via `MessageContextStack::current()`. The context is immutable — handlers MUST NOT mutate it.

```php
final class PlaceOrderHandler
{
    public function __construct(private readonly MessageContextStack $contextStack) {}

    public function __invoke(PlaceOrder $cmd): void
    {
        $ctx = $this->contextStack->current()->getOrCall(
            static fn() => throw new \LogicException('Context required'),
        );

        $causationId = $ctx->metadata->causationId;       // Option<MessageId>
        $correlationId = $ctx->metadata->correlationId;   // Option<MessageId>
        // ... use them in the handler
    }
}
```

## BusInvariantException propagation

Boot-time misconfiguration extends `BusBootException` AND implements `BusInvariantException`:

- `MissingValidatorException`
- `MissingAuthorizationDeciderException`
- `BusNameNotRegisteredException`
- `BusNotAvailableInProfileException`
- `DuplicateRoutingException`
- `CommandReturnTypeException`

`tryDispatch` / `tryAsk` / `tryPublish` PROPAGATE these — they are not lifted to `Either::left`. Adopters wrap composition-root code in `try/catch (BusBootException)` to log/exit on misconfiguration. Controllers wrapping `tryDispatch` only ever see `Either::left` for domain failures (`ValidationFailedException`, `AccessDeniedException`, `RetryBudgetExhaustedException`, etc.).

```php
// Composition root
try {
    $result = $builder->build($profile, $hasValidator, $hasDecider, $routing);
} catch (BusBootException $e) {
    $this->logger->critical('Bus misconfiguration', ['error' => $e->getMessage()]);
    exit(1);
}
```

## Compile cache deploy story (H14)

For production boot, pre-compile routing to an opcache-friendly PHP snapshot:

```bash
bin/console ddd:routes:compile var/cache/ddd-routes.php
# add --overwrite to replace an existing file
```

At application boot:

```php
// Production
$result = $builder->loadCompiledFrom('var/cache/ddd-routes.php');

// Development (re-runs reflection on every boot)
$result = $builder->build($profile, $hasValidator, $hasDecider, $routing);
```

The snapshot includes a `sourceHash` field for adopter-side drift detection: compare hashes pre/post deploy to confirm the live builder matches what was compiled. Custom middleware registrations are NOT serialized — wire them at runtime via `BusBuilder::withMiddleware()` before calling `loadCompiledFrom()`.

## Configuration via builder

```php
$builder
    ->withCausationDepthCap(32)              // saga chains > 32 frames throw CausationDepthExceededException
    ->withRetryBudgetMs(5_000)               // OCC retry budget per dispatch (wall-clock ms)
    ->withMiddleware($mw, before: PipelineStage::Handler);  // splice adopter/package middleware (panel H13)
```

`withMiddleware` accepts a `Middleware` impl + optional `PipelineStage` insertion point. `$before === null` appends after `SpanClose`. Adopter middleware is preserved in registration order when multiple registrations share the same `$before`.

## Observability — metric + log discipline

All bus events emit canonical metric names locked by `MetricOutcome`:

| Metric                          | Type      | Tags                                |
|---------------------------------|-----------|-------------------------------------|
| `ddd.command.count`             | counter   | `outcome`, `type`                   |
| `ddd.command.duration_ms`       | histogram | `type`                              |
| `ddd.command.retry_exhausted`   | counter   | `type`                              |

`MetricOutcome` cases: `started`, `succeeded`, `validation_failed`, `access_denied`, `idempotent_short_circuit`, `occ_retry_exhausted`, `terminal_failure`.

Structured PSR-3 logs use consistent fields: `messageId`, `messageType`, `causationId`, `correlationId`. Exception messages on failure are truncated at 1024 bytes (`LoggingEndMiddleware::EXCEPTION_MESSAGE_MAX_LENGTH`) to prevent unbounded user-data from polluting log sinks.

## Known limitations + cross-package implications

Per umbrella spec §25.6:

- **Causation-chain integrity across writer-id changes** — when the actor system's writer-id rotates, causation-chain references can dangle. Aggregate-side concern; surfaces in the bus via `causationId` lookup.
- **Snapshot-vs-event-store transactional divergence** — bus middleware can succeed while aggregate persistence rolls back. Adopters must ensure the aggregate's TX wraps the dispatch path (`nexus-ddd-aggregate` ships the unit-of-work hook).
- **`InProcessSameDbBootValidator` is BOOT-TIME only** (panel H1) — runtime introspection is deferred to the aggregate package. If adopters mutate connection bindings at runtime (e.g., env-var swap on deploy), validation does not re-run. Standard "restart workers on deploy" discipline applies.

## Out-of-scope deferrals

This package ships the sync profile only. The async/actor profiles plus production adapters live in follow-up packages:

- `AsyncCommandBus` / `AsyncEventBus` — `nexus-ddd-async` (P3)
- `ActorCommandBus` — `nexus-ddd-actor` (P4)
- `OutboxEventBus` — `nexus-ddd-outbox` (P3)
- DB-backed `IdempotencyStore` impl — `nexus-ddd-bus-idempotency-doctrine`
- Symfony bundle integration — `nexus-ddd-symfony` (P4)
- OpenTelemetry SDK adapter — `nexus-ddd-otel-adapter`
- `SELECT ... FOR UPDATE SKIP LOCKED` outbox locking discipline — `nexus-ddd-outbox`
- PM compensation + `CommandEmissionFailed` system event — `nexus-ddd-process-manager` (P2)
- `bin/ddd` shell shim — `nexus-ddd-cli` (TBD)
- Cooperative `SleepStrategy` for async/actor profiles — adapter packages

## Spec references

- v6 §7 — Messaging Layer (envelope, metadata, marker interfaces)
- v6 §8 — Bus Fabric (canonical pipeline, routing, profile)
- v6 §13 — Idempotency (two-phase contract, key resolution)
- v6 §23 — Observability (metric names, outcome tags)
- v6 §25.6 — Known Limitations

## License

MIT.
