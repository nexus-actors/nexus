# Nexus DDD Bus Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the `nexus-ddd-bus` package — the central dispatch fabric for the Nexus DDD framework. P0 scope: ship `SyncCommandBus` / `SyncQueryBus` / `SyncEventBus` plus the canonical 11-stage middleware pipeline, the routing fabric (`BusRegistry` + composite `RoutingStrategy`), the four pluggable slot interfaces (`Validator`, `AuthorizationDecider`, `IdempotencyStore`, `MetricsCollector`), all attributes adopters reach for at the application layer, the supporting exception hierarchy, the `bin/ddd routes show` CLI command, six new Psalm rules in `nexus-psalm`, and the architectural fitness tests. Async / Actor bus implementations and the Postgres-backed idempotency store are explicitly out of scope and ship in P3+P4 follow-up packages.

**Architecture:** A single canonical pipeline lives in `Bus/Middleware/MiddlewarePipeline`; concrete bus impls (`SyncCommandBus`, `SyncQueryBus`, `SyncEventBus`) are thin wrappers that build the pipeline from a list of registered middlewares and dispatch a single message. Routing is decoupled from dispatch: a `CommandRouter` (composed of `RoutingStrategy` impls) resolves a command class to a registered bus name; `BusRegistry` answers "name → impl" and validates the registered set against the active `Profile` at boot. The four slots — validation, authorization, idempotency, metrics — are interfaces only in this package; concrete adapters live in dedicated packages so this package never imports Symfony / Doctrine / Redis. The `nexus-ddd-aggregate` package contributes its own `OneAggregatePerCommandMiddleware` to the pipeline at runtime; this package ships only the `Middleware` interface and the canonical pipeline composition. The package depends on `nexus-ddd-messaging` (for the `CommandBus` / `QueryBus` / `EventBus` interfaces, `MessageMetadata`, `MessageContext`, `MessageContextStack`, `Outbox`, `MessageInbox`, `BackoffStrategy`, retry policies), `nexus-ddd-core` (exceptions, `Identifier`, `DomainEvent`), `psr/log`, `psr/event-dispatcher`. It explicitly does NOT depend on `nexus-ddd-aggregate` (this stays a one-way contribution from aggregate → bus, not bus → aggregate).

**Tech Stack:** PHP 8.5+, Psalm strict (level 1), PER-CS2.0 + Slevomat, fp4php/functional (`Option<T>`, `Either<L,R>`), psr/log (PSR-3), psr/event-dispatcher (PSR-14), psr/clock (PSR-20), psr/container (PSR-11). PHPUnit 13. All commands run via Docker (`docker compose exec -T php …`). No host PHP/composer/vendor invocations. GrumPHP pre-commit hooks run in Docker (PHP-CS-Fixer, PHPCS, Psalm, PHPUnit unit suite — all four MUST pass on every commit).

**Already shipped — re-used as-is, NOT redefined:**
- `Monadial\Nexus\Ddd\Messaging\Bus\CommandBus` — interface, single method `dispatchCommand(Command): void`
- `Monadial\Nexus\Ddd\Messaging\Bus\QueryBus` — interface, single method `dispatchQuery(Query): mixed` (template `Query<TResult>`)
- `Monadial\Nexus\Ddd\Messaging\Bus\EventBus` — interface, single method `publishEvent(DomainEvent): void`
- `Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedCommandBus` — extends `CommandBus`, adds `dispatchEnveloped(Envelope<Command>): void`
- `Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedQueryBus` — extends `QueryBus`
- `Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedEventBus` — extends `EventBus`
- `Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler` — marker interface
- `Monadial\Nexus\Ddd\Messaging\Handler\QueryHandler` — marker interface (`@template TResult`)
- `Monadial\Nexus\Ddd\Messaging\Handler\EventListener` — marker interface
- `Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator` — interface for "find the handler for this command class"
- `Monadial\Nexus\Ddd\Messaging\Resolution\QueryHandlerLocator` — same shape for queries
- `Monadial\Nexus\Ddd\Messaging\Resolution\EventListenerLocator` — same shape for events (multi-handler)
- `Monadial\Nexus\Ddd\Messaging\Identity\MessageId` — `final readonly class`, `MessageId::generate()` and `MessageId::fromString(string)`
- `Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata` — `final readonly class` with typed fields (`id`, `occurredAt`, `causationId: Option<MessageId>`, `correlationId: Option<MessageId>`, `conversationId: Option<MessageId>`, `schemaVersion`, `traceParent`, `traceState`, `expiresAt`, `vectorClock`). Factory `MessageMetadata::root(ClockInterface)`. Builder `forCausedMessage(MessageId, DateTimeImmutable): self`. **Critical: there is NO `headers()` method or arbitrary `array<string,mixed> $headers` field on `MessageMetadata`.** See "Spec/code drift" below for how this plan reconciles.
- `Monadial\Nexus\Ddd\Messaging\Context\MessageContext` — `final readonly class` (`metadata`, `stamps`); `stamp(class-string<S>): Option<S>`
- `Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack` — instance class; `current(): Option<MessageContext>`, `push(MessageContext)`, `pop()`, `within(ctx, cb)` helper. Constructor takes a `ContextStorage`. `MessageContextStack::default()` factory wires `StaticStackContextStorage`. **No singleton — DI-injected on every collaborator that needs it.**
- `Monadial\Nexus\Ddd\Messaging\Envelope\Envelope` — `final readonly class<T>`; carries `(T $message, MessageMetadata $metadata, array<class-string<Stamp>, Stamp> $stamps)`
- `Monadial\Nexus\Ddd\Messaging\Envelope\Stamp` — interface for transport / cross-cutting metadata extensions
- `Monadial\Nexus\Ddd\Messaging\Outbox\Outbox` — interface (`appendCommand(Command, Option<MessageId>)`, `appendEvent(DomainEvent, Option<MessageId>)`, `flush()`, `discard()`)
- `Monadial\Nexus\Ddd\Messaging\Inbox\MessageInbox` — interface (`tryReserve(handlerClass, MessageId): bool`, `markProcessed(...)`, `release(...)`)
- `Monadial\Nexus\Ddd\Messaging\Retry\BackoffStrategy` — interface for retry strategies
- `Monadial\Nexus\Ddd\Messaging\Retry\JitteredExponentialBackoff`, `ExponentialBackoff`, `LinearBackoff`, `FixedDelayBackoff`, `NoRetry`, `CustomBackoff` — concrete impls
- `Monadial\Nexus\Ddd\Messaging\Retry\RetryPolicy` / `RetryPolicyBuilder` — policy DSL
- `Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure` — marker interface
- `Monadial\Nexus\Ddd\Messaging\Exception\TransientFailure` — marker interface
- `Monadial\Nexus\Ddd\Messaging\Exception\HandlerNotFoundException`, `DuplicateCommandHandlerException`, `MessageDispatchException`, `MessageRejectedException`, `MessagingException` — exception hierarchy
- `Monadial\Nexus\Ddd\Messaging\Message\Command` / `Query` — marker interfaces (`Query<TResult>` is a template)
- `Monadial\Nexus\Ddd\Aggregate\Repository\AggregateRepository` (in PR #35 — open) — used at runtime by handlers, NOT imported by this package
- `Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException` — extends `DomainException`; raised by aggregate strategies, caught by the OCC retry middleware in this package
- `Monadial\Nexus\Ddd\Aggregate\Exception\AggregateAlreadyExistsException` — terminal (per `nexus-ddd-aggregate` PR #35); the OCC retry middleware in this package does NOT retry this — the supervisor classifies terminal failures by exception type. We don't import the class — middleware classifies by interface (`TerminalFailure` marker) only.
- `Monadial\Nexus\Ddd\Aggregate\Exception\MultiAggregateTransactionException` (in PR #35) — same reasoning; classified by `TerminalFailure` marker.

**Spec/code drift notes the plan reconciles:**

- v7 spec §13 references `MessageContext::headers()` as the home for `nexus.idempotency-key`, `nexus.principal`, `nexus.retry.attempt`, `nexus.replay`, `nexus.causation.depth`. The shipped `MessageMetadata` exposes typed fields plus a `Stamp` mechanism for long-tail metadata, NOT an arbitrary `headers` map. **Resolution:** introduce a `BusHeaders` value object (in this package) that is carried as a `Stamp<BusHeaders>` on the `Envelope`. The middleware reads/writes `BusHeaders` via the stamp slot. The spec's `$ctx->headers()->get('nexus.principal')` becomes `$envelope->stamp(BusHeaders::class)->flatMap(fn($h) => $h->get('nexus.principal'))`. Adapter packages map HTTP headers / CLI identity into `BusHeaders` at the boundary. Out-of-scope follow-up: a future spec revision may collapse `BusHeaders` into `MessageMetadata::headers()` directly; that's a spec change owned by the messaging package, not this one. Track as post-merge follow-up #1.

- v7 spec §8.6 says `tryDispatch(): Either<Throwable, Accepted>`, but the shipped `CommandBus` interface only has `dispatchCommand(): void`. **Resolution:** this package introduces `RichCommandBus extends CommandBus` (and analogous `RichQueryBus`, `RichEventBus`) that adds `tryDispatch()`. The concrete `SyncCommandBus` implements `RichCommandBus`. Domain code that injects the bare `CommandBus` keeps working with `dispatchCommand(): void`; tracing-aware callers inject `RichCommandBus`. Track as post-merge follow-up #2 (collapse rich variants into base interfaces in messaging).

- v7 spec §8.5.1 step 7 names "Idempotency check"; step 8 names "OCC retry (handler wrapper)"; the canonical order has the idempotency middleware *outside* the retry loop (so retries reuse the same reservation). The brief enumerated 11 stages: (1) causation, (2) OTel span, (3) logging-start, (4) metrics-start, (5) validation, (6) authorization, (7) idempotency, (8) OCC retry, (9) metrics-end, (10) logging-end, (11) span close. Plan locks this exact order, with (8) wrapping the handler invocation + the event drain.

- v7 §13.1 introduces `IdempotencyReservation` as a typed value object plus a two-phase `tryReserve / markCompleted / release` contract. The shipped `MessageInbox::tryReserve()` returns a plain `bool`. **Resolution:** the new `IdempotencyStore` is NOT a subtype of `MessageInbox` — it's a parallel slot. The pipeline calls both layers: `MessageInbox::tryReserve` (transport-level dedup, gated on `MessageId`) THEN `IdempotencyStore::tryReserve` (application-level dedup, gated on `IdempotencyKey`). They compose; they do not overlap.

- v7 §22 lists 6 new Psalm rules (`CommandHandlerReturnTypeRule`, `CommandReturnValueIgnoredRule`, `ValidatedCommandReadonlyRule`, `IdempotencyKeyFieldExistsRule`, `AuthorizeBeforeValidationRule`, `UnguardedExternalSideEffectRule`). The brief adds `MiddlewareOrderingRule` (mentioned in §8.5.1). Plan ships all 7 rules. The plan does NOT ship `RegisteredHandlerRule` or `AsyncHandlerSyncDispatchRule` (deferred to follow-up).

- v7 §11.2 references `InProcessSameDbMiddleware` as the runtime guard that asserts the active TX's connection matches the source aggregate's bound connection. Implementation honesty: at the middleware layer the bus does not know which aggregate the handler will load — the aggregate is a parameter to the handler, not the dispatcher. **Resolution:** ship a *best-effort* `InProcessSameDbMiddleware` that wraps `#[InProcess]`-attributed handlers and asserts (handler-class) → (registered same-DB binding) at registration time, and falls back to "Psalm is the primary fence" for runtime cases the middleware cannot introspect. If the heuristic is too lossy to be useful in P0, we ship the middleware as a thin no-op marker and document the deferral. The `InProcessConnectionMismatchException` class ships in either case so adapter packages can throw it.

- v7 §9.1.0.2 places `OneAggregatePerCommandMiddleware` in `nexus-ddd-aggregate` (already noted in the aggregate plan). This package ships only the `Middleware` interface; aggregate contributes its middleware via a DI tag at boot. The file `Middleware/OneAggregatePerCommandMiddleware.php` does NOT exist in this package.

**Post-merge spec follow-up tasks (track separately, NOT this PR):**

1. v8 spec — collapse `BusHeaders` into `MessageMetadata::headers(): array<string, scalar|Option>` (or document the stamp-based layering as the canonical shape).
2. v8 spec — collapse `RichCommandBus`/`RichQueryBus`/`RichEventBus` interfaces into the base `CommandBus`/`QueryBus`/`EventBus` (or document the rich variants as the canonical extension points).
3. v8 spec — clarify whether `Validator::validate()` returns `Violations` (no-throw, plan locks this per Q6) or whether it may throw a `ValidationFailedException` directly (some adapter packages may prefer the throw shape).
4. Future PR — `nexus-ddd-bus-idempotency-doctrine` (the Postgres-backed `IdempotencyStore` impl with daily range partitioning per §13.1).
5. Future PR — `nexus-ddd-async` package shipping `AsyncCommandBus`, `AsyncEventBus`, the outbox-relay machinery (§11.4), the consumer-side `MessageInbox` integration (§11.1.1), and the `bin/ddd consume` / `bin/ddd outbox relay` commands.
6. Future PR — `nexus-ddd-actor` package shipping `ActorCommandBus`, `ActorHost`, the actor-mailbox transport, and the host-aware OCC-retry behaviour validated under `Profile::Actor`.

---

## File Structure

```
packages/nexus-ddd-bus/
├── composer.json
├── psalm.xml
├── phpcs.xml
├── .gitignore
├── README.md                                          # phase 19
├── bin/
│   └── ddd                                            # phase 15 (entrypoint)
├── src/
│   ├── Bus/
│   │   ├── RichCommandBus.php                         # interface; extends messaging CommandBus + tryDispatch
│   │   ├── RichQueryBus.php                           # interface; extends messaging QueryBus + tryAsk
│   │   ├── RichEventBus.php                           # interface; extends messaging EventBus + tryPublish
│   │   ├── SyncCommandBus.php                         # concrete; implements RichCommandBus + EnvelopedCommandBus
│   │   ├── SyncQueryBus.php                           # concrete; implements RichQueryBus + EnvelopedQueryBus
│   │   └── SyncEventBus.php                           # concrete; implements RichEventBus + EnvelopedEventBus
│   ├── Middleware/
│   │   ├── Middleware.php                             # interface
│   │   ├── MiddlewarePipeline.php                     # composer of stages, stamped name → impl
│   │   ├── PipelineStage.php                          # enum of canonical stage names (used by MiddlewareOrderingRule)
│   │   ├── CausationPropagationMiddleware.php         # stage 1
│   │   ├── OpenTelemetrySpanMiddleware.php            # stage 2 (no-op default; activated when SDK present)
│   │   ├── LoggingStartMiddleware.php                 # stage 3
│   │   ├── MetricsStartMiddleware.php                 # stage 4
│   │   ├── ValidationMiddleware.php                   # stage 5 (consumes Validator)
│   │   ├── AuthorizationMiddleware.php                # stage 6 (consumes AuthorizationDecider)
│   │   ├── IdempotencyMiddleware.php                  # stage 7 (consumes IdempotencyStore + IdempotencyKeyResolver)
│   │   ├── OccRetryMiddleware.php                     # stage 8 (host-aware: Profile::Sync vs Profile::Actor)
│   │   ├── HandlerInvocationMiddleware.php            # stage 8 inner (the actual handler call)
│   │   ├── EventDrainMiddleware.php                   # stage 8 inner (CommandBus only — drain & write to outbox)
│   │   ├── MetricsEndMiddleware.php                   # stage 9
│   │   ├── LoggingEndMiddleware.php                   # stage 10
│   │   ├── SpanCloseMiddleware.php                    # stage 11
│   │   └── InProcessSameDbMiddleware.php              # phase 10c — best-effort runtime guard for #[InProcess]
│   ├── Routing/
│   │   ├── BusRegistry.php                            # name → bus impl; profile validation
│   │   ├── CommandRouter.php                          # composes RoutingStrategy impls
│   │   ├── RoutingStrategy.php                        # interface
│   │   ├── ExplicitOnly.php
│   │   ├── AttributeBased.php
│   │   ├── NamespacePattern.php
│   │   ├── Composite.php                              # walks sub-strategies in order; first match wins
│   │   └── RoutingResolution.php                      # value object: which strategy resolved + bus name
│   ├── Idempotency/
│   │   ├── IdempotencyStore.php                       # interface (two-phase reserve/commit/release)
│   │   ├── IdempotencyReservation.php                 # value object (handlerClass + idempotencyKey + impl-private payload)
│   │   ├── IdempotencyKey.php                         # final readonly class wrapping a string
│   │   ├── IdempotencyKeyResolver.php                 # reads #[IdempotencyKey] attr → BusHeaders → messageId
│   │   └── InMemoryIdempotencyStore.php               # tests-only impl
│   ├── Validation/
│   │   ├── Validator.php                              # interface (returns Violations, never throws)
│   │   ├── ValidationContext.php                      # final readonly class (groups, principal, headers)
│   │   ├── Violations.php                             # final readonly class (list<Violation>)
│   │   └── Violation.php                              # final readonly class (path, message, code)
│   ├── Authorization/
│   │   ├── AuthorizationDecider.php                   # interface (decide(); throws AccessDeniedException on fail)
│   │   ├── SubjectResolver.php                        # final class — resolves #[Authorize(subject:)] string|callable
│   │   └── AuthorizationContext.php                   # final readonly class (principal + headers + envelope)
│   ├── Metrics/
│   │   ├── MetricsCollector.php                       # interface
│   │   └── NoOpMetricsCollector.php                   # default impl
│   ├── Profile/
│   │   └── Profile.php                                # enum: Sync | Async | Actor
│   ├── Header/
│   │   ├── BusHeaders.php                             # final readonly class (per drift-resolution above) wraps array<string, scalar>
│   │   ├── BusHeadersStamp.php                        # final readonly class implements Stamp; carries BusHeaders on Envelope
│   │   └── HeaderKeys.php                             # final class with public const string CAUSATION_DEPTH = 'nexus.causation.depth'; etc.
│   ├── Attribute/
│   │   ├── CommandHandler.php                         # #[CommandHandler]  — attribute form (marker interface stays canonical)
│   │   ├── Validate.php                               # #[Validate(groups: array, opt: bool = false)]
│   │   ├── Authorize.php                              # #[Authorize(policy: string, subject: string|null, before: string|null)]
│   │   ├── OnBus.php                                  # #[OnBus(name: string)]
│   │   ├── IdempotencyKey.php                         # #[IdempotencyKey(field: string)]
│   │   ├── InProcess.php                              # #[InProcess]  — marker for in-tx event handlers
│   │   └── Idempotent.php                             # #[Idempotent(store: ?string, off: bool = false)]
│   ├── Marker/
│   │   └── Accepted.php                               # final readonly class (no fields, no factory) — typed marker for tryDispatch
│   ├── Exception/
│   │   ├── BusException.php                           # abstract; extends NexusDddException
│   │   ├── BusNotAvailableInProfileException.php
│   │   ├── BusNameNotRegisteredException.php
│   │   ├── DuplicateRoutingException.php
│   │   ├── CommandReturnTypeException.php             # boot-time: handler declared non-void return
│   │   ├── ValidationFailedException.php              # carries Violations
│   │   ├── MissingValidatorException.php              # boot: any #[Validate] handler but no Validator registered
│   │   ├── MissingAuthorizationDeciderException.php   # boot: any #[Authorize] handler but no decider registered
│   │   ├── AccessDeniedException.php                  # runtime: AuthorizationDecider rejection
│   │   ├── CausationDepthExceededException.php        # runtime: depth > cap (default 32)
│   │   ├── InProcessConnectionMismatchException.php   # runtime: best-effort middleware fence
│   │   └── ActorWriterInvariantViolation.php          # wraps OptimisticLockException under Profile::Actor
│   ├── Cli/
│   │   └── RoutesShowCommand.php                      # "bin/ddd routes show [<command-class>]"
│   └── Internal/
│       └── Pipeline/
│           └── PipelineContext.php                    # final class — short-lived context threaded through stages
└── tests/
    ├── Unit/
    │   ├── Bus/
    │   ├── Middleware/
    │   ├── Routing/
    │   ├── Idempotency/
    │   ├── Validation/
    │   ├── Authorization/
    │   ├── Metrics/
    │   ├── Profile/
    │   ├── Header/
    │   ├── Attribute/
    │   ├── Marker/
    │   ├── Exception/
    │   ├── Cli/
    │   ├── Smoke/                                     # full-pipeline end-to-end on SyncCommandBus
    │   └── Fitness/
    │       ├── PackageDependencyFitnessTest.php
    │       ├── ForbiddenImportsFitnessTest.php
    │       └── AbstractClassReadonlyOrFinalFitnessTest.php
    └── Support/                                       # test fixtures + recording fakes
        ├── RecordingMetricsCollector.php
        ├── RecordingValidator.php
        ├── RecordingAuthorizationDecider.php
        ├── RecordingMiddleware.php
        ├── Fixtures/
        │   ├── PlaceOrder.php                         # fixture command (readonly)
        │   ├── PlaceOrderHandler.php                  # fixture handler (implements CommandHandler)
        │   ├── CancelOrder.php                        # fixture for #[Authorize] tests
        │   └── …
        └── …
```

The Psalm rules ship in `packages/nexus-psalm/src/Hook/Bus/` (NOT in this package — they piggyback on the existing nexus-psalm plugin).

---

## Phase 0 — Branch cut

Already done — branch `feat/nexus-ddd-bus` is cut from `main` HEAD.

- [ ] **Step 1: Verify branch state**

```bash
git rev-parse --abbrev-ref HEAD
# Expect: feat/nexus-ddd-bus
git log --oneline -1
# Expect: 114d6495 ci(split): remove nexus meta-package from split (would overwrite monorepo)
```

If the branch is wrong or the working tree is dirty, stop and resolve before proceeding.

---

## Phase 1 — Package skeleton

**Files:**
- Create: `packages/nexus-ddd-bus/composer.json`
- Create: `packages/nexus-ddd-bus/psalm.xml`
- Create: `packages/nexus-ddd-bus/phpcs.xml`
- Create: `packages/nexus-ddd-bus/.gitignore`
- Modify: root `composer.json` (path repository entry; autoload)
- Modify: root `phpunit.xml` — add `packages/nexus-ddd-bus/tests/Unit` to the `unit` testsuite + `packages/nexus-ddd-bus/src` (and `tests/Support`) to the `<source>` whitelist (without this, `#[CoversClass]` triggers "is not a valid target for code coverage" warnings under `failOnWarning=true`)
- Modify: root `deptrac.yaml` — add a `DddBus` layer for `packages/nexus-ddd-bus/src/`; allowed dependencies: `DddCore`, `DddMessaging`. **Forbidden:** `DddAggregate`, `Persistence*`, `Symfony*`, `Doctrine*`.

- [ ] **Step 1: Write `packages/nexus-ddd-bus/composer.json`**

```json
{
  "name": "nexus-actors/ddd-bus",
  "description": "Nexus DDD Framework — bus dispatch fabric (sync command/query/event buses, canonical 11-stage middleware pipeline, composite routing, pluggable validation/authorization/idempotency/metrics slots).",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": ">=8.5",
    "fp4php/functional": "^6.0",
    "nexus-actors/ddd-core": "dev-main",
    "nexus-actors/ddd-messaging": "dev-main",
    "psr/clock": "^1.0",
    "psr/event-dispatcher": "^1.0",
    "psr/log": "^3.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^13.0"
  },
  "autoload": {
    "psr-4": { "Monadial\\Nexus\\Ddd\\Bus\\": "src/" }
  },
  "autoload-dev": {
    "psr-4": { "Monadial\\Nexus\\Ddd\\Bus\\Tests\\": "tests/" }
  },
  "bin": ["bin/ddd"]
}
```

**Forbidden adds:** `nexus-actors/ddd-aggregate`, any `symfony/*` (other than `symfony/uid` if absolutely needed — but NOT for the bus impls), any `doctrine/*`, `nexus-persistence*`. Phase 18 fitness tests enforce this.

- [ ] **Step 2: Write `psalm.xml` and `phpcs.xml`** — mirror the conventions of `packages/nexus-ddd-messaging/psalm.xml` and `packages/nexus-ddd-aggregate/psalm.xml`. Strict mode, level 1, no baseline. PHPCS includes Slevomat extensions per project policy.

- [ ] **Step 3: Update root `composer.json`** — add `"path": { "url": "packages/nexus-ddd-bus" }` if other packages do this; otherwise the root autoload wildcard already covers `packages/*`.

- [ ] **Step 4: Update root `phpunit.xml`**

```xml
<source>
    <include>
        <!-- existing entries -->
        <directory>packages/nexus-ddd-bus/src</directory>
        <directory>packages/nexus-ddd-bus/tests/Support</directory>
    </include>
</source>
<testsuites>
    <testsuite name="unit">
        <!-- existing -->
        <directory>packages/nexus-ddd-bus/tests/Unit</directory>
    </testsuite>
</testsuites>
```

- [ ] **Step 5: Update root `deptrac.yaml`**

```yaml
- name: DddBus
  collectors:
    - type: directory
      value: packages/nexus-ddd-bus/src/.*
```

In the `ruleset:` block:

```yaml
DddBus:
  - DddCore
  - DddMessaging
```

This explicitly forbids `DddAggregate` and `Persistence*` — the plan's architectural lock.

- [ ] **Step 6: Verify pipeline**

```bash
docker compose exec -T php composer dump-autoload --quiet
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
docker compose exec -T php vendor/bin/psalm --no-cache
docker compose exec -T php vendor/bin/deptrac
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-bus/
```

All clean. (The unit suite is empty for the new package at this point; the others have nothing to flag.)

- [ ] **Step 7: Commit**

```bash
git add packages/nexus-ddd-bus/composer.json packages/nexus-ddd-bus/psalm.xml packages/nexus-ddd-bus/phpcs.xml packages/nexus-ddd-bus/.gitignore composer.json phpunit.xml deptrac.yaml
git commit -m "feat(ddd-bus): package skeleton + composer/phpunit/deptrac wiring"
```

---

## Phase 2 — Profile enum + base exception classes

**Files:**
- Create: `packages/nexus-ddd-bus/src/Profile/Profile.php`
- Create: `packages/nexus-ddd-bus/src/Exception/BusException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/BusNotAvailableInProfileException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/BusNameNotRegisteredException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/DuplicateRoutingException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/CommandReturnTypeException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/ValidationFailedException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/MissingValidatorException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/MissingAuthorizationDeciderException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/AccessDeniedException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/CausationDepthExceededException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/InProcessConnectionMismatchException.php`
- Create: `packages/nexus-ddd-bus/src/Exception/ActorWriterInvariantViolation.php`
- Tests: `packages/nexus-ddd-bus/tests/Unit/Profile/ProfileTest.php`
- Tests: `packages/nexus-ddd-bus/tests/Unit/Exception/ExceptionHierarchyTest.php`

**Inheritance roots (locked):**
- `BusException` — abstract, extends `NexusDddException` (the framework-wiring root from `nexus-ddd-core`). Every framework-wiring fault in this package (boot-time misconfiguration, runtime contract violation by the bus itself) extends `BusException`.
- `AccessDeniedException` extends `DomainException` (NOT `BusException`) — authorization rejection is a domain-level fact (the principal cannot perform this action), not a framework misconfiguration. Routes through `Either::left` for `tryDispatch()` callers (per spec §8.5.1.2).
- `ValidationFailedException` extends `DomainException` — same reasoning.
- `ActorWriterInvariantViolation` extends `BusException` AND implements `TerminalFailure` (from messaging) — the supervisor must not retry; this signals the actor mailbox-ack invariant was violated.

- [ ] **Step 1: Write the `Profile` enum test FIRST**

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Profile;

use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Profile::class)]
final class ProfileTest extends TestCase
{
    #[Test]
    public function casesAreSyncAsyncActor(): void
    {
        self::assertSame('sync', Profile::Sync->value);
        self::assertSame('async', Profile::Async->value);
        self::assertSame('actor', Profile::Actor->value);
    }

    #[Test]
    public function isSyncReturnsTrueForSync(): void
    {
        self::assertTrue(Profile::Sync->isSync());
        self::assertFalse(Profile::Async->isSync());
        self::assertFalse(Profile::Actor->isSync());
    }

    #[Test]
    public function allowsAsyncBusReturnsTrueForAsyncAndActor(): void
    {
        self::assertFalse(Profile::Sync->allowsAsyncBus());
        self::assertTrue(Profile::Async->allowsAsyncBus());
        self::assertTrue(Profile::Actor->allowsAsyncBus());
    }

    #[Test]
    public function allowsActorBusReturnsTrueOnlyForActor(): void
    {
        self::assertFalse(Profile::Sync->allowsActorBus());
        self::assertFalse(Profile::Async->allowsActorBus());
        self::assertTrue(Profile::Actor->allowsActorBus());
    }
}
```

- [ ] **Step 2: Write the `Profile` enum**

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Ddd\Bus\Profile;

/**
 * @psalm-api
 *
 * Deployment profile selector. Determines which bus implementations are
 * available at runtime and how the OCC retry middleware behaves
 * (per umbrella spec v7 §4.2 and §8.5.1).
 *
 * `Sync` is dev / single-process; `Async` is the production default
 * (outbox + relay); `Actor` is the actor-routed profile (single-writer
 * per aggregate id).
 */
enum Profile: string
{
    case Sync = 'sync';
    case Async = 'async';
    case Actor = 'actor';

    public function isSync(): bool
    {
        return $this === self::Sync;
    }

    public function allowsAsyncBus(): bool
    {
        return $this === self::Async || $this === self::Actor;
    }

    public function allowsActorBus(): bool
    {
        return $this === self::Actor;
    }
}
```

- [ ] **Step 3: Write the exception hierarchy test**

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\AccessDeniedException;
use Monadial\Nexus\Ddd\Bus\Exception\ActorWriterInvariantViolation;
use Monadial\Nexus\Ddd\Bus\Exception\BusException;
use Monadial\Nexus\Ddd\Bus\Exception\BusNameNotRegisteredException;
use Monadial\Nexus\Ddd\Bus\Exception\BusNotAvailableInProfileException;
use Monadial\Nexus\Ddd\Bus\Exception\CausationDepthExceededException;
use Monadial\Nexus\Ddd\Bus\Exception\CommandReturnTypeException;
use Monadial\Nexus\Ddd\Bus\Exception\DuplicateRoutingException;
use Monadial\Nexus\Ddd\Bus\Exception\InProcessConnectionMismatchException;
use Monadial\Nexus\Ddd\Bus\Exception\MissingAuthorizationDeciderException;
use Monadial\Nexus\Ddd\Bus\Exception\MissingValidatorException;
use Monadial\Nexus\Ddd\Bus\Exception\ValidationFailedException;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Validation\Violations;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;
use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;
use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusNotAvailableInProfileException::class)]
#[CoversClass(BusNameNotRegisteredException::class)]
#[CoversClass(DuplicateRoutingException::class)]
#[CoversClass(CommandReturnTypeException::class)]
#[CoversClass(ValidationFailedException::class)]
#[CoversClass(MissingValidatorException::class)]
#[CoversClass(MissingAuthorizationDeciderException::class)]
#[CoversClass(AccessDeniedException::class)]
#[CoversClass(CausationDepthExceededException::class)]
#[CoversClass(InProcessConnectionMismatchException::class)]
#[CoversClass(ActorWriterInvariantViolation::class)]
final class ExceptionHierarchyTest extends TestCase
{
    #[Test]
    public function busNotAvailableInProfileExtendsBusException(): void
    {
        $e = BusNotAvailableInProfileException::for('long-running', Profile::Sync, 'App\\Orders\\BulkImport');
        self::assertInstanceOf(BusException::class, $e);
        self::assertInstanceOf(NexusDddException::class, $e);
        self::assertStringContainsString('long-running', $e->getMessage());
        self::assertStringContainsString('sync', $e->getMessage());
    }

    #[Test]
    public function busNameNotRegisteredListsKnownNames(): void
    {
        $e = BusNameNotRegisteredException::for('long-runnning', ['default', 'long-running']);
        self::assertInstanceOf(BusException::class, $e);
        self::assertStringContainsString('long-runnning', $e->getMessage());
        self::assertStringContainsString('default', $e->getMessage());
        self::assertStringContainsString('long-running', $e->getMessage());
    }

    #[Test]
    public function duplicateRoutingNamesBothSources(): void
    {
        $e = DuplicateRoutingException::between('App\\PlaceOrder', 'attribute', 'dsl');
        self::assertInstanceOf(BusException::class, $e);
    }

    #[Test]
    public function commandReturnTypeFlagsHandlerClass(): void
    {
        $e = CommandReturnTypeException::for('App\\PlaceOrderHandler', 'string');
        self::assertInstanceOf(BusException::class, $e);
        self::assertStringContainsString('void', $e->getMessage());
    }

    #[Test]
    public function validationFailedCarriesViolations(): void
    {
        $violations = new Violations([]);
        $e = ValidationFailedException::with($violations);
        self::assertInstanceOf(DomainException::class, $e);
        self::assertSame($violations, $e->violations());
    }

    #[Test]
    public function missingValidatorIsBusException(): void
    {
        $e = MissingValidatorException::forHandler('App\\PlaceOrderHandler');
        self::assertInstanceOf(BusException::class, $e);
    }

    #[Test]
    public function missingAuthorizationDeciderIsBusException(): void
    {
        $e = MissingAuthorizationDeciderException::forHandler('App\\PlaceOrderHandler');
        self::assertInstanceOf(BusException::class, $e);
    }

    #[Test]
    public function accessDeniedExtendsDomainException(): void
    {
        $e = AccessDeniedException::for('order.cancel', 'cust-1');
        self::assertInstanceOf(DomainException::class, $e);
        self::assertStringContainsString('order.cancel', $e->getMessage());
    }

    #[Test]
    public function causationDepthExceededIsBusException(): void
    {
        $e = CausationDepthExceededException::at(33, 32);
        self::assertInstanceOf(BusException::class, $e);
        self::assertStringContainsString('32', $e->getMessage());
    }

    #[Test]
    public function inProcessMismatchIsBusException(): void
    {
        $e = InProcessConnectionMismatchException::between('orders_db', 'shipping_db');
        self::assertInstanceOf(BusException::class, $e);
    }

    #[Test]
    public function actorWriterInvariantImplementsTerminalFailure(): void
    {
        $e = ActorWriterInvariantViolation::forActor('App\\Order', 'order-1', new \RuntimeException('oce'));
        self::assertInstanceOf(BusException::class, $e);
        self::assertInstanceOf(TerminalFailure::class, $e);
    }
}
```

- [ ] **Step 4: Run, confirm 11 failures (classes don't exist)**

- [ ] **Step 5: Write `BusException` (abstract base)**

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Ddd\Bus\Exception;

use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;

abstract class BusException extends NexusDddException {}
```

- [ ] **Step 6: Write the 11 concrete exception classes**

```php
// BusNotAvailableInProfileException.php
final class BusNotAvailableInProfileException extends BusException
{
    public static function for(string $busName, Profile $profile, string $commandClass): self
    {
        return new self(sprintf(
            'Route for `%s` requires bus `%s`, but the active profile is `%s`. ' .
            'Bus `%s` is unavailable in profile `%s`. ' .
            'Fix: switch profile, or change the route to use a profile-compatible bus.',
            $commandClass,
            $busName,
            $profile->value,
            $busName,
            $profile->value,
        ));
    }
}

// BusNameNotRegisteredException.php
final class BusNameNotRegisteredException extends BusException
{
    /** @param list<string> $knownNames */
    public static function for(string $requestedName, array $knownNames): self
    {
        return new self(sprintf(
            'Route specifies bus `%s`, but no bus by that name is registered. Known buses: [%s].',
            $requestedName,
            implode(', ', $knownNames),
        ));
    }
}

// DuplicateRoutingException.php
final class DuplicateRoutingException extends BusException
{
    public static function between(string $messageClass, string $sourceA, string $sourceB): self
    {
        return new self(sprintf(
            'Conflicting routing for `%s`: registered via both `%s` and `%s`.',
            $messageClass, $sourceA, $sourceB,
        ));
    }
}

// CommandReturnTypeException.php
final class CommandReturnTypeException extends BusException
{
    public static function for(string $handlerClass, string $declaredReturnType): self
    {
        return new self(sprintf(
            'Command handler `%s` declared return type `%s`; commands are pure CQS — handlers MUST declare `: void`.',
            $handlerClass, $declaredReturnType,
        ));
    }
}

// ValidationFailedException.php  (extends DomainException, NOT BusException)
final class ValidationFailedException extends DomainException
{
    private function __construct(string $message, private readonly Violations $violations)
    {
        parent::__construct($message);
    }

    public static function with(Violations $violations): self
    {
        return new self(
            sprintf('Validation failed with %d violation(s).', count($violations->all())),
            $violations,
        );
    }

    public function violations(): Violations
    {
        return $this->violations;
    }
}

// MissingValidatorException.php
final class MissingValidatorException extends BusException
{
    public static function forHandler(string $handlerClass): self
    {
        return new self(sprintf(
            'Handler `%s` declares `#[Validate]` but no Validator is registered. Register a Validator implementation in DI before booting the bus.',
            $handlerClass,
        ));
    }
}

// MissingAuthorizationDeciderException.php
final class MissingAuthorizationDeciderException extends BusException
{
    public static function forHandler(string $handlerClass): self
    {
        return new self(sprintf(
            'Handler `%s` declares `#[Authorize]` but no AuthorizationDecider is registered. Register a decider in DI before booting the bus.',
            $handlerClass,
        ));
    }
}

// AccessDeniedException.php  (extends DomainException, NOT BusException)
final class AccessDeniedException extends DomainException
{
    public static function for(string $policy, mixed $subject): self
    {
        $subjectStr = is_scalar($subject) ? (string) $subject : get_debug_type($subject);
        return new self(sprintf('Access denied: principal cannot perform `%s` on `%s`.', $policy, $subjectStr));
    }
}

// CausationDepthExceededException.php
final class CausationDepthExceededException extends BusException
{
    public static function at(int $observedDepth, int $cap): self
    {
        return new self(sprintf(
            'Causation depth %d exceeded cap %d. A buggy process manager may be emitting commands in response to its own emitted events. Raise the cap via configuration if the depth is intentional.',
            $observedDepth, $cap,
        ));
    }
}

// InProcessConnectionMismatchException.php
final class InProcessConnectionMismatchException extends BusException
{
    public static function between(string $sourceConnection, string $handlerConnection): self
    {
        return new self(sprintf(
            'In-process subscriber attempted to write to connection `%s` but the source aggregate is bound to `%s`. ' .
            'Cross-database in-tx subscriptions require XA/2PC, which the framework rejects.',
            $handlerConnection, $sourceConnection,
        ));
    }
}

// ActorWriterInvariantViolation.php
final class ActorWriterInvariantViolation extends BusException implements TerminalFailure
{
    public static function forActor(string $aggregateClass, string $aggregateId, \Throwable $cause): self
    {
        return new self(sprintf(
            'Actor-mode OCC violation for %s(%s): the mailbox-ack invariant was breached. Supervisor must restart, not retry. Underlying cause: %s.',
            $aggregateClass, $aggregateId, $cause->getMessage(),
        ), 0, $cause);
    }
}
```

The `ValidationFailedException` references `Violations`, which is created in Phase 4. To avoid a forward-dependency that breaks Phase 2 in isolation, **defer the `ValidationFailedException` class creation to Phase 4** (where `Violations` lives). Phase 2 ships the other 10 exceptions plus `BusException` plus `Profile`. The hierarchy test in Phase 2 covers 10 exceptions; Phase 4 adds the `ValidationFailedException` covering test.

- [ ] **Step 7: Run tests + Psalm + PHPCS, all clean**
- [ ] **Step 8: Commit**

```bash
git commit -m "feat(ddd-bus): Profile enum + 10 bus exception classes (BusException root, AccessDenied/ValidationFailed under DomainException, ActorWriterInvariantViolation as TerminalFailure)"
```

---

## Phase 3 — Marker types: `Accepted`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Marker/Accepted.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Marker/AcceptedTest.php`

**Lock (per Q2):** `Accepted` is a typed marker — no fields, no factory, no public constructor. The intent is "the dispatcher accepted this command; sync handler completed OR async enqueue succeeded." Tracing rides on `MessageContext::current()->metadata->id`, NOT on `Accepted`. The framework refuses to surface a `messageId` from the marker because it invites adopters to build a command-status oracle the framework cannot honor at-least-once.

- [ ] **Step 1: Write the test FIRST**

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Marker;

use Monadial\Nexus\Ddd\Bus\Marker\Accepted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Accepted::class)]
final class AcceptedTest extends TestCase
{
    #[Test]
    public function instanceIsConstructibleViaInstanceFactory(): void
    {
        self::assertInstanceOf(Accepted::class, Accepted::instance());
    }

    #[Test]
    public function instanceIsSingletonValueObject(): void
    {
        self::assertSame(Accepted::instance(), Accepted::instance());
    }
}
```

- [ ] **Step 2: Run, confirm failure**

- [ ] **Step 3: Write the class**

```php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\Ddd\Bus\Marker;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Typed marker for `tryDispatch(): Either<Throwable, Accepted>` returns.
 * No fields. No factory other than the cached `instance()` accessor.
 *
 * Tracing rides on `MessageContext::current()->metadata->id`, NOT on this
 * marker — see umbrella spec §8.6 / Q2.
 */
final readonly class Accepted
{
    private function __construct() {}

    private static self $instance;

    /**
     * @psalm-pure
     * @psalm-suppress ImpureStaticProperty Cached marker instance, semantically pure.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
```

Note: The cached-instance pattern looks like a singleton but is *not* — `Accepted` has no state, no mutators, and no behaviour. Caching the instance is purely an allocation optimization. The class is `final readonly`. This is permitted by the project's "no singletons" rule because there is no shared mutable state — see `claude.md` ("named constructors and factories are fine"). If the project's static-analysis rule complains, we promote `instance()` to a public constructor `new Accepted()` — semantically equivalent.

- [ ] **Step 4: Run, verify pass**

- [ ] **Step 5: Psalm + PHPCS clean**

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(ddd-bus): Accepted typed marker for tryDispatch()"
```

---

## Phase 4 — Validation slot: `Validator` interface + `ValidationContext` + `Violations` + `Violation` + `ValidationFailedException`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Validation/Validator.php` (interface)
- Create: `packages/nexus-ddd-bus/src/Validation/ValidationContext.php`
- Create: `packages/nexus-ddd-bus/src/Validation/Violations.php`
- Create: `packages/nexus-ddd-bus/src/Validation/Violation.php`
- Create: `packages/nexus-ddd-bus/src/Exception/ValidationFailedException.php` (deferred from Phase 2)
- Tests for each

**Locks per Q6:** `Validator::validate(object, ValidationContext): Violations` returns Violations as a value, never throws. The validation middleware lifts non-empty `Violations` to `ValidationFailedException` (or `Either::left` for `tryDispatch()` callers).

- [ ] **Step 1: TDD `Violation` value object**

`Violation` carries `(string $path, string $message, string $code)`. Tests: construction, equality. `final readonly class`.

```php
namespace Monadial\Nexus\Ddd\Bus\Validation;

/**
 * @psalm-immutable
 * @psalm-api
 */
final readonly class Violation
{
    public function __construct(
        public string $code,
        public string $message,
        public string $path,
    ) {}

    public function equals(self $other): bool
    {
        return $this->code === $other->code
            && $this->message === $other->message
            && $this->path === $other->path;
    }
}
```

(Property order alphabetical per Slevomat policy — `code`, `message`, `path`.)

- [ ] **Step 2: TDD `Violations` collection**

`Violations` carries `list<Violation>`; provides `isEmpty(): bool`, `all(): list<Violation>`, `count(): int`, `forPath(string): Violations` (filter), `merge(Violations): Violations`. `final readonly class`.

```php
namespace Monadial\Nexus\Ddd\Bus\Validation;

/**
 * @psalm-immutable
 * @psalm-api
 */
final readonly class Violations
{
    /** @param list<Violation> $violations */
    public function __construct(public array $violations) {}

    public static function empty(): self { return new self([]); }

    public function isEmpty(): bool { return $this->violations === []; }

    /** @return list<Violation> */
    public function all(): array { return $this->violations; }

    public function count(): int { return count($this->violations); }

    public function forPath(string $path): self
    {
        return new self(array_values(array_filter(
            $this->violations,
            static fn(Violation $v): bool => $v->path === $path,
        )));
    }

    public function merge(self $other): self
    {
        return new self([...$this->violations, ...$other->violations]);
    }
}
```

- [ ] **Step 3: TDD `ValidationContext` value object**

`ValidationContext(list<string> $groups, mixed $principal, BusHeaders $headers)`. The `BusHeaders` type ships in Phase 11 (header phase) — for now, accept the forward dep and type-import. **Decision:** ship `ValidationContext` with `array<string, scalar> $headers` plain-array typed in Phase 4; refactor to `BusHeaders` in Phase 11 once the type exists. The plan's commit ordering means Phase 11 will have a small migration commit.

```php
namespace Monadial\Nexus\Ddd\Bus\Validation;

/**
 * @psalm-immutable
 * @psalm-api
 */
final readonly class ValidationContext
{
    /**
     * @param list<string> $groups Validation groups (Symfony Validator semantics).
     * @param array<string, scalar> $headers Bus headers (refactored to BusHeaders in Phase 11).
     */
    public function __construct(
        public array $groups = [],
        public mixed $principal = null,
        public array $headers = [],
    ) {}

    public static function default(): self { return new self(); }
}
```

The `mixed $principal` field is unavoidable — the framework cannot constrain what an app's principal type is. Per project's no-`null` rule, the constructor signature should be `Option<mixed> $principal` — but `Option<mixed>` is a code smell since `mixed` already encodes optionality. **Compromise:** keep `mixed $principal = null` here as a *narrow exception* (boundary-with-app convention; documented in the class docblock); the `Validator` adapter receives `Option::fromNullable($ctx->principal)` and works in `Option<mixed>` from there.

- [ ] **Step 4: TDD `Validator` interface (no tests for the interface itself; covered by `#[CoversNothing]`)**

```php
namespace Monadial\Nexus\Ddd\Bus\Validation;

/**
 * @psalm-api
 *
 * Project-supplied validator. Implementations live in adapter packages
 * (Symfony Validator, Respect, custom). Without a registered Validator,
 * the bus emits a boot warning and `#[Validate]` is no-op.
 *
 * Per umbrella spec §8.5.1.1, `validate()` returns `Violations` as a value
 * — it never throws. The bus's ValidationMiddleware lifts non-empty
 * Violations to ValidationFailedException (or to Either::left for
 * tryDispatch() callers).
 */
interface Validator
{
    public function validate(object $message, ValidationContext $context): Violations;
}
```

- [ ] **Step 5: Now ship `ValidationFailedException` (deferred from Phase 2)**

```php
namespace Monadial\Nexus\Ddd\Bus\Exception;

use Monadial\Nexus\Ddd\Bus\Validation\Violations;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;

final class ValidationFailedException extends DomainException
{
    private function __construct(string $message, private readonly Violations $violations)
    {
        parent::__construct($message);
    }

    public static function with(Violations $violations): self
    {
        return new self(
            sprintf('Validation failed with %d violation(s).', $violations->count()),
            $violations,
        );
    }

    public function violations(): Violations
    {
        return $this->violations;
    }
}
```

- [ ] **Step 6: Run tests + Psalm + PHPCS clean**

- [ ] **Step 7: Commit**

```bash
git commit -m "feat(ddd-bus): validation slot — Validator interface + Violations + Violation + ValidationContext + ValidationFailedException"
```

---

## Phase 5 — Authorization slot: `AuthorizationDecider` + `AuthorizationContext` + `SubjectResolver`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Authorization/AuthorizationDecider.php` (interface)
- Create: `packages/nexus-ddd-bus/src/Authorization/AuthorizationContext.php` (value object)
- Create: `packages/nexus-ddd-bus/src/Authorization/SubjectResolver.php` (helper)
- Tests for each

**Locks per Q7:**
- `#[Authorize(policy: 'order.cancel', subject: 'orderId')]` — `subject:` string form is a property-name shortcut.
- `#[Authorize(policy: 'order.cancel', subject: fn(CancelOrder $c, MessageContext $ctx) => …)]` — callable form receives `($message, MessageContext): mixed`.
- `AuthorizationDecider::decide(string $policy, mixed $subject, AuthorizationContext $ctx): void` — throws `AccessDeniedException` on denial.

- [ ] **Step 1: TDD `AuthorizationContext` value object** — fields `(mixed $principal, array<string, scalar> $headers, Envelope $envelope)`. Same `mixed $principal` exception as `ValidationContext`. Refactor to `BusHeaders` in Phase 11.

- [ ] **Step 2: TDD `AuthorizationDecider` interface**

```php
namespace Monadial\Nexus\Ddd\Bus\Authorization;

use Monadial\Nexus\Ddd\Bus\Exception\AccessDeniedException;

/**
 * @psalm-api
 *
 * Project-supplied authorization decider. Implementations live in adapter
 * packages (Symfony Security voters, Casbin, custom).
 *
 * Per umbrella spec §8.5.1.2, `decide()` throws AccessDeniedException on
 * denial. Bus middleware converts the throw to Either::left for
 * tryDispatch() callers.
 */
interface AuthorizationDecider
{
    /**
     * @throws AccessDeniedException if the principal cannot perform $policy on $subject
     */
    public function decide(string $policy, mixed $subject, AuthorizationContext $context): void;
}
```

- [ ] **Step 3: TDD `SubjectResolver`**

`SubjectResolver` reads the `#[Authorize(subject:)]` value (either a string property-name or a callable) and resolves the runtime subject.

```php
namespace Monadial\Nexus\Ddd\Bus\Authorization;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use ReflectionObject;

final class SubjectResolver
{
    /**
     * @param string|Closure(object, MessageContext): mixed $subjectSpec
     */
    public function resolve(object $message, string|Closure $subjectSpec, MessageContext $ctx): mixed
    {
        if ($subjectSpec instanceof Closure) {
            return ($subjectSpec)($message, $ctx);
        }

        $reflection = new ReflectionObject($message);
        if (!$reflection->hasProperty($subjectSpec)) {
            throw new \LogicException(sprintf(
                'Property `%s` does not exist on `%s`. The `#[Authorize(subject:)]` string form names a property; the named property must exist on the message class. Use the callable form for composite subjects.',
                $subjectSpec, $message::class,
            ));
        }

        return $reflection->getProperty($subjectSpec)->getValue($message);
    }
}
```

The `string|Closure` parameter is technically a "compound type" forbidden by §21. **Resolution:** wrap as `SubjectSpec` interface with `StringSubjectSpec` / `CallableSubjectSpec` impls. Defer to Phase 17 polish — for now keep the string|Closure as a documented exception. The `AuthorizeAttributeSubjectRule` Psalm rule (deferred — not in this plan's 7 rules) would have validated string forms; we ship the runtime fallback `LogicException` at Phase 5.

- [ ] **Step 4: Run tests + Psalm + PHPCS clean**

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(ddd-bus): authorization slot — AuthorizationDecider interface + AuthorizationContext + SubjectResolver"
```

---

## Phase 6 — Metrics slot: `MetricsCollector` + `NoOpMetricsCollector`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Metrics/MetricsCollector.php` (interface)
- Create: `packages/nexus-ddd-bus/src/Metrics/NoOpMetricsCollector.php` (default impl)
- Tests for each

The interface mirrors umbrella spec §23.3 — emit `count(name, tags)`, `histogram(name, value, tags)`, `gauge(name, value, tags)`. Adapter packages (P5) ship Prometheus / StatsD / OpenTelemetry impls.

- [ ] **Step 1: TDD `MetricsCollector` interface**

```php
namespace Monadial\Nexus\Ddd\Bus\Metrics;

/**
 * @psalm-api
 *
 * Project-supplied metrics collector. Default is no-op (NoOpMetricsCollector);
 * adapter packages in P5 ship Prometheus / StatsD / OpenTelemetry impls.
 *
 * Standard metric names are locked in umbrella spec §23.3 — adapters MUST
 * pass through `ddd.command.count`, `ddd.command.duration_ms`, etc.
 * verbatim without renaming.
 */
interface MetricsCollector
{
    /**
     * @param array<string, scalar> $tags
     */
    public function count(string $name, int $delta, array $tags): void;

    /**
     * @param array<string, scalar> $tags
     */
    public function histogram(string $name, float $value, array $tags): void;

    /**
     * @param array<string, scalar> $tags
     */
    public function gauge(string $name, float $value, array $tags): void;
}
```

- [ ] **Step 2: TDD `NoOpMetricsCollector`** — every method is a no-op. Tests assert the methods don't throw and don't capture state.

```php
namespace Monadial\Nexus\Ddd\Bus\Metrics;

use Override;

final class NoOpMetricsCollector implements MetricsCollector
{
    #[Override]
    public function count(string $name, int $delta, array $tags): void {}

    #[Override]
    public function histogram(string $name, float $value, array $tags): void {}

    #[Override]
    public function gauge(string $name, float $value, array $tags): void {}
}
```

- [ ] **Step 3: Run tests + Psalm + PHPCS clean**

- [ ] **Step 4: Commit**

```bash
git commit -m "feat(ddd-bus): metrics slot — MetricsCollector interface + NoOpMetricsCollector default"
```

---

## Phase 7 — `IdempotencyStore` two-phase contract + `IdempotencyReservation` + `IdempotencyKey` + `InMemoryIdempotencyStore`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Idempotency/IdempotencyKey.php`
- Create: `packages/nexus-ddd-bus/src/Idempotency/IdempotencyReservation.php`
- Create: `packages/nexus-ddd-bus/src/Idempotency/IdempotencyStore.php` (interface)
- Create: `packages/nexus-ddd-bus/src/Idempotency/InMemoryIdempotencyStore.php` (tests-only impl)
- Tests for each

**Locks per Q3:**
- `IdempotencyStore::tryReserve(string $handlerClass, IdempotencyKey $key): Option<IdempotencyReservation>` — returns `Option::some(token)` on first attempt; `Option::none()` if `(handlerClass, key)` was already committed.
- `IdempotencyStore::markCompleted(IdempotencyReservation $token): void` — runs in the handler's TX.
- `IdempotencyStore::release(IdempotencyReservation $token): void` — runs on terminal failure (retry-budget exhaustion, terminal exception) so future redelivery can attempt the handler again.
- `IdempotencyReservation` carries `(string $handlerClass, IdempotencyKey $idempotencyKey)` plus impl-private fields (e.g., a row-id token for the Postgres impl). Public properties: `handlerClass`, `idempotencyKey`. Impl-private fields are stored in a `mixed $payload` field (escaped through `mixed` because the impl owns the shape).

- [ ] **Step 1: TDD `IdempotencyKey` value object**

```php
namespace Monadial\Nexus\Ddd\Bus\Idempotency;

/**
 * @psalm-immutable
 * @psalm-api
 *
 * Application-level idempotency key. Distinct from MessageId (transport-level);
 * see umbrella spec §13 / §11.1.2.
 */
final readonly class IdempotencyKey
{
    public function __construct(public string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('IdempotencyKey value cannot be empty.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

- [ ] **Step 2: TDD `IdempotencyReservation` value object**

```php
namespace Monadial\Nexus\Ddd\Bus\Idempotency;

/**
 * @psalm-immutable
 * @psalm-api
 *
 * Two-phase reservation token. Issued by IdempotencyStore::tryReserve;
 * passed back to markCompleted (handler success) or release (terminal
 * failure). Per umbrella spec §13.1 — pluggable two-phase contract.
 *
 * The `payload` field is impl-owned (e.g., DB row id, Redis lock token).
 * Adapter impls cast it to the concrete type they wrote.
 */
final readonly class IdempotencyReservation
{
    /**
     * @param class-string $handlerClass
     */
    public function __construct(
        public string $handlerClass,
        public IdempotencyKey $idempotencyKey,
        public mixed $payload,
    ) {}
}
```

- [ ] **Step 3: TDD `IdempotencyStore` interface**

```php
namespace Monadial\Nexus\Ddd\Bus\Idempotency;

use Fp\Functional\Option\Option;

/**
 * @psalm-api
 *
 * Two-phase idempotency contract. Per umbrella spec §13.1 + Q3:
 *   tryReserve   — gates redelivery + concurrent retries; returns Some(token) on first
 *   markCompleted — durable commit; runs in handler's TX
 *   release       — release on terminal failure to allow future redelivery
 *
 * The pipeline calls tryReserve BEFORE the OCC retry loop (umbrella spec
 * §8.5.1 step 7); the OCC retry loop reuses the same token across retry
 * attempts; markCompleted runs in the handler's TX once the OCC append
 * commits. On terminal failure (retry-budget exhaustion, non-retryable
 * exception), the middleware calls release so future redelivery can
 * attempt the handler again.
 */
interface IdempotencyStore
{
    /**
     * @param class-string $handlerClass
     * @return Option<IdempotencyReservation>  None means "already handled" — caller skips the handler.
     */
    public function tryReserve(string $handlerClass, IdempotencyKey $key): Option;

    public function markCompleted(IdempotencyReservation $token): void;

    public function release(IdempotencyReservation $token): void;
}
```

- [ ] **Step 4: TDD `InMemoryIdempotencyStore`**

The in-memory impl stores `(handlerClass, key)` pairs in two arrays: `$reserved` (pending) and `$committed`. Uses `Option::none()` as "already committed"; `Option::some(reservation)` otherwise.

```php
namespace Monadial\Nexus\Ddd\Bus\Idempotency;

use Fp\Functional\Option\Option;
use Override;

/**
 * @psalm-api
 *
 * Tests-only implementation. NOT for production. Concrete production
 * adapters live in `nexus-ddd-bus-idempotency-doctrine` and
 * `nexus-ddd-idempotency-redis`.
 */
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, true> key = "{handlerClass}::{idempotencyKey.value}" */
    private array $reserved = [];

    /** @var array<string, true> */
    private array $committed = [];

    #[Override]
    public function tryReserve(string $handlerClass, IdempotencyKey $key): Option
    {
        $compositeKey = $handlerClass . '::' . $key->value;
        if (isset($this->committed[$compositeKey])) {
            return Option::none();
        }
        if (isset($this->reserved[$compositeKey])) {
            // Already reserved by a concurrent caller. Per spec §13.1, the
            // pipeline calls tryReserve once per dispatch; OCC retries
            // reuse the SAME reservation. So this branch is hit by tests
            // simulating concurrent first-attempt dispatches; we treat it
            // the same as already-handled (None).
            return Option::none();
        }
        $this->reserved[$compositeKey] = true;
        return Option::some(new IdempotencyReservation($handlerClass, $key, $compositeKey));
    }

    #[Override]
    public function markCompleted(IdempotencyReservation $token): void
    {
        \assert(is_string($token->payload));
        unset($this->reserved[$token->payload]);
        $this->committed[$token->payload] = true;
    }

    #[Override]
    public function release(IdempotencyReservation $token): void
    {
        \assert(is_string($token->payload));
        unset($this->reserved[$token->payload]);
    }
}
```

Tests:
- `tryReserve` on fresh state returns `Option::some(token)`.
- Second `tryReserve` for same `(handlerClass, key)` (without commit) returns `Option::none()`.
- `markCompleted(token)` then `tryReserve(same)` returns `Option::none()`.
- `release(token)` then `tryReserve(same)` returns `Option::some(token)` again.
- Different `handlerClass` + same `key` → `tryReserve` returns `Option::some()` for both.
- Different `key` + same `handlerClass` → `tryReserve` returns `Option::some()` for both.

- [ ] **Step 5: Run tests + Psalm + PHPCS clean**

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(ddd-bus): idempotency slot — IdempotencyKey + IdempotencyStore (two-phase tryReserve/markCompleted/release) + IdempotencyReservation + InMemoryIdempotencyStore"
```

---

## Phase 8 — Attributes: `Validate`, `Authorize`, `OnBus`, `IdempotencyKey`, `Idempotent`, `InProcess`, `CommandHandler`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Attribute/Validate.php`
- Create: `packages/nexus-ddd-bus/src/Attribute/Authorize.php`
- Create: `packages/nexus-ddd-bus/src/Attribute/OnBus.php`
- Create: `packages/nexus-ddd-bus/src/Attribute/IdempotencyKey.php`
- Create: `packages/nexus-ddd-bus/src/Attribute/Idempotent.php`
- Create: `packages/nexus-ddd-bus/src/Attribute/InProcess.php`
- Create: `packages/nexus-ddd-bus/src/Attribute/CommandHandler.php`
- Tests for each

**Spec discipline (Q11 sub-item: marker-interface canonical):** the marker interface (`Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler`) is the canonical shape. The `#[CommandHandler]` attribute is a discoverability shortcut for multi-method services where grouping aggregate operations on a single application service is preferred. Most projects should use the marker interface; the attribute is the exception. (Per umbrella spec §8.3.) Document this in the attribute class docblock.

- [ ] **Step 1: TDD `OnBus` attribute** — `final readonly class`, `#[Attribute(Attribute::TARGET_CLASS)]`. Single string field `name`.

```php
namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Routing hint per umbrella spec §8.2 (composite routing). Resolution
 * order: explicit DSL → `#[OnBus(name:)]` → namespace-pattern → default.
 *
 * Place on a Command class to declare the bus that should receive it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class OnBus
{
    public function __construct(public string $name) {}
}
```

- [ ] **Step 2: TDD `Validate` attribute** — `#[Attribute(Attribute::TARGET_METHOD)]`. Optional `groups: list<string>` (default empty).

```php
namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Per umbrella spec §8.5.1.1 — declarative trigger for the
 * ValidationMiddleware. Place on the handler method (`__invoke` or named).
 *
 * Bus-level `#[Validate]` is defense-in-depth, NOT the primary line of
 * defense. Commands SHOULD validate their own invariants in the constructor.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Validate
{
    /** @param list<string> $groups */
    public function __construct(public array $groups = []) {}
}
```

- [ ] **Step 3: TDD `Authorize` attribute** — `#[Attribute(Attribute::TARGET_METHOD)]`. Fields: `policy: string`, `subject: string|Closure|null = null`, `before: string|null = null`.

`subject:` accepts either a string (property-name shortcut) or a `Closure(object, MessageContext): mixed`. Per Q7. The `before:` field flips the default `Validate → Authorize` ordering when set to `'validation'`. The `MiddlewareOrderingRule` Psalm rule (Phase 17) validates `before:` is one of `PipelineStage`'s canonical names.

PHP's attribute mechanism doesn't directly accept `Closure`. **Resolution:** for the callable form, the attribute carries a `string $subjectCallable` referencing a public static method (`'App\\Subjects\\OrderSubject::resolve'`). The bus calls `Closure::fromCallable($subjectCallable)` at boot. Document the pattern; the `AuthorizeAttributeSubjectRule` Psalm rule (out of scope) would validate the string. Adopters who want a true callable wrap it in a one-method class.

```php
namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;
use Fp\Functional\Option\Option;

/**
 * @psalm-api
 *
 * Per umbrella spec §8.5.1.2 — declarative trigger for the
 * AuthorizationMiddleware. Place on the handler method.
 *
 *     #[Authorize(policy: 'order.cancel', subject: 'orderId')]
 *     // String form: subject names a property on the command class.
 *
 *     #[Authorize(policy: 'order.cancel', subject: 'App\\Subjects\\OrderSubject::resolve')]
 *     // Callable form: 'Class::method' (public static); receives ($message, MessageContext): mixed.
 *
 * The `before:` field controls pipeline stage ordering — set to
 * 'validation' to run Authorize before Validate. Validated by
 * MiddlewareOrderingRule Psalm rule.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Authorize
{
    public function __construct(
        public string $policy,
        public ?string $subject = null,
        public ?string $before = null,
    ) {}
}
```

The `?string` types on `subject` and `before` are nullable — the no-null rule (§21) prefers `Option<string>`, but PHP attributes' constructor argument type system forbids `Option<T>` (default value cannot be `Option::none()`). **Documented narrow exception.** The bus reads the attribute, immediately wraps with `Option::fromNullable`, and works in `Option<string>` from there.

- [ ] **Step 4: TDD `IdempotencyKey` attribute** — `#[Attribute(Attribute::TARGET_CLASS)]`, single field `field: string`. Per Q1. The `IdempotencyKeyFieldExistsRule` Psalm rule (Phase 17) validates the field exists on the command class and returns string.

```php
namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Per umbrella spec §13.2.1 — names a property on the command class
 * whose value is used as the application-level idempotency key. Without
 * this attribute, the framework falls back to MessageContext-supplied
 * `nexus.idempotency-key` header; without that, falls back to messageId.
 *
 *     #[IdempotencyKey(field: 'clientRequestId')]
 *     readonly class PlaceOrder { …; public string $clientRequestId; … }
 *
 * Validated by IdempotencyKeyFieldExistsRule Psalm rule (Phase 17).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class IdempotencyKey
{
    public function __construct(public string $field) {}
}
```

- [ ] **Step 5: TDD `Idempotent` attribute** — `#[Attribute(Attribute::TARGET_CLASS)]`. Fields: `store: ?string = null` (named idempotency-store key), `off: bool = false`. Per umbrella spec §13.2.

- [ ] **Step 6: TDD `InProcess` attribute** — `#[Attribute(Attribute::TARGET_METHOD)]`. No fields. Per umbrella spec §11.2 — marks an event handler as in-tx.

- [ ] **Step 7: TDD `CommandHandler` attribute** — `#[Attribute(Attribute::TARGET_METHOD)]`. No fields. Per umbrella spec §8.3 — the secondary discoverability path. Document that the marker interface (`Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler`) is canonical.

```php
namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Per umbrella spec §8.3 — secondary discoverability shortcut for
 * multi-method services. The canonical shape is implementing the
 * `Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler` marker
 * interface; this attribute is the exception, not the rule.
 *
 *     final class OrdersService {
 *         #[CommandHandler]
 *         public function place(PlaceOrder $cmd): void { … }
 *
 *         #[CommandHandler]
 *         public function cancel(CancelOrder $cmd): void { … }
 *     }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class CommandHandler {}
```

- [ ] **Step 8: Run tests + Psalm + PHPCS clean**

- [ ] **Step 9: Commit**

```bash
git commit -m "feat(ddd-bus): seven attributes — Validate, Authorize, OnBus, IdempotencyKey, Idempotent, InProcess, CommandHandler"
```

---

## Phase 9 — `Middleware` interface + `MiddlewarePipeline` + `PipelineStage` enum + `PipelineContext`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Middleware/Middleware.php` (interface)
- Create: `packages/nexus-ddd-bus/src/Middleware/MiddlewarePipeline.php`
- Create: `packages/nexus-ddd-bus/src/Middleware/PipelineStage.php` (enum)
- Create: `packages/nexus-ddd-bus/src/Internal/Pipeline/PipelineContext.php`
- Tests

**Lock:** the `Middleware` interface uses an Onion-style pipeline — each middleware receives `(Envelope, NextHandler)` and either calls `$next($envelope)` or short-circuits. The `NextHandler` is a `Closure(Envelope): mixed`.

The `PipelineStage` enum lists all 11 canonical stages. `MiddlewarePipeline` registers middlewares against stages; the `MiddlewareOrderingRule` Psalm rule (Phase 17) validates that `before:` / `after:` arguments name canonical stages.

- [ ] **Step 1: TDD `PipelineStage` enum**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

/**
 * @psalm-api
 *
 * Canonical pipeline stage names per umbrella spec §8.5.1. Lock the
 * sequence here; MiddlewareOrderingRule (Phase 17) validates that
 * adopter-supplied `before:` / `after:` arguments name an existing case.
 *
 * Stages:
 *   1. Causation (causation propagation, depth check)
 *   2. OtelSpan (OpenTelemetry span open; no-op default)
 *   3. LoggingStart (INFO log with metadata)
 *   4. MetricsStart (start timer)
 *   5. Validation
 *   6. Authorization
 *   7. Idempotency (application-level dedup; outside retry loop)
 *   8. OccRetry (host-aware: SyncHost retries, ActorHost wraps as ActorWriterInvariantViolation)
 *      ↳ Handler (the actual handler invocation)
 *      ↳ EventDrain (CommandBus only — drain recorded events to outbox)
 *   9. MetricsEnd (record duration, outcome)
 *   10. LoggingEnd (INFO log with outcome)
 *   11. SpanClose
 */
enum PipelineStage: string
{
    case Causation = 'causation';
    case OtelSpan = 'otel-span';
    case LoggingStart = 'logging-start';
    case MetricsStart = 'metrics-start';
    case Validation = 'validation';
    case Authorization = 'authorization';
    case Idempotency = 'idempotency';
    case OccRetry = 'occ-retry';
    case Handler = 'handler';
    case EventDrain = 'event-drain';
    case MetricsEnd = 'metrics-end';
    case LoggingEnd = 'logging-end';
    case SpanClose = 'span-close';

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn(self $s): string => $s->value, self::cases());
    }
}
```

- [ ] **Step 2: TDD `Middleware` interface**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;

/**
 * @psalm-api
 *
 * Onion-style middleware. Implementations may transform the envelope
 * before calling `$next($envelope)`, short-circuit (skip the handler),
 * inspect the return value, or wrap exceptions.
 *
 * The return value flows back through `$next` — for void-returning
 * commands, it's null; for queries, the typed result; for events, null.
 */
interface Middleware
{
    /**
     * @template TMessage of object
     * @param Envelope<TMessage> $envelope
     * @param Closure(Envelope<TMessage>): mixed $next
     */
    public function process(Envelope $envelope, Closure $next): mixed;
}
```

- [ ] **Step 3: TDD `MiddlewarePipeline`**

The pipeline composes a list of middlewares into a single closure. Building order matters — outermost middleware runs first.

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;

/**
 * @psalm-api
 *
 * Composes a list of Middleware impls into a single Closure that
 * dispatches an Envelope through the canonical pipeline. Built once at
 * boot from the registered middlewares (in PipelineStage order); reused
 * across every dispatch.
 */
final class MiddlewarePipeline
{
    /**
     * @param list<Middleware> $middlewares  Outermost first; innermost last.
     * @param Closure(Envelope): mixed $core  The terminal handler (the actual dispatch).
     */
    public function __construct(
        private readonly array $middlewares,
        private readonly Closure $core,
    ) {}

    public function dispatch(Envelope $envelope): mixed
    {
        $next = $this->core;
        // Walk from innermost to outermost so each middleware wraps the next.
        foreach (array_reverse($this->middlewares) as $middleware) {
            $current = $middleware;
            $previous = $next;
            $next = static fn(Envelope $env): mixed => $current->process($env, $previous);
        }
        return $next($envelope);
    }
}
```

Tests:
- Empty middleware list calls `$core` directly.
- Single middleware wraps `$core`.
- Multiple middlewares wrap in registration order (outermost first).
- A middleware short-circuiting (returning early without calling `$next`) prevents inner middlewares from running.
- Exceptions thrown by inner middlewares propagate up; outer middlewares can catch.

- [ ] **Step 4: TDD `PipelineContext`** — short-lived mutable object threaded through stages for cross-stage state (e.g., the `IdempotencyReservation` that the OCC retry middleware needs to release on terminal failure). The `PipelineContext` is *not* a value object — it's a per-dispatch scratchpad.

```php
namespace Monadial\Nexus\Ddd\Bus\Internal\Pipeline;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyReservation;

/**
 * @internal
 *
 * Per-dispatch scratchpad. Threaded through the pipeline via a `Stamp` on
 * the Envelope (`PipelineContextStamp`) so middlewares can read/write
 * cross-stage state without leaking it into MessageMetadata.
 *
 * NOT a value object — short-lived, mutable. Thread one per dispatch.
 */
final class PipelineContext
{
    /** @var Option<IdempotencyReservation> */
    public Option $idempotencyReservation;

    public int $causationDepth = 0;
    public int $retryAttempt = 0;

    public function __construct()
    {
        $this->idempotencyReservation = Option::none();
    }
}
```

- [ ] **Step 5: Run tests + Psalm + PHPCS clean**

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(ddd-bus): Middleware interface + MiddlewarePipeline + PipelineStage enum + PipelineContext scratchpad"
```

---

## Phase 10 — Individual middleware impls

Split into 3 sub-phases per the brief.

### Phase 10a — Causation, OTel-span, logging-start

**Files:**
- Create: `packages/nexus-ddd-bus/src/Header/HeaderKeys.php`
- Create: `packages/nexus-ddd-bus/src/Header/BusHeaders.php`
- Create: `packages/nexus-ddd-bus/src/Header/BusHeadersStamp.php`
- Create: `packages/nexus-ddd-bus/src/Middleware/CausationPropagationMiddleware.php`
- Create: `packages/nexus-ddd-bus/src/Middleware/OpenTelemetrySpanMiddleware.php`
- Create: `packages/nexus-ddd-bus/src/Middleware/LoggingStartMiddleware.php`
- Tests for each

**Locks per Q11 sub-items:**
- Causation depth cap = 32 (configurable via `CausationPropagationMiddleware` constructor). Exceeded → `CausationDepthExceededException`. Increment on each dispatch.
- The depth is persisted on the envelope as `BusHeaders[HeaderKeys::CAUSATION_DEPTH]`.
- The `OpenTelemetrySpanMiddleware` is a no-op by default; activated when `open-telemetry/sdk` is detected. We ship the no-op slot here.
- `LoggingStartMiddleware` writes INFO log with structured fields (`messageType`, `messageId`, `correlationId`, `causationId`); payload at DEBUG (gated). Per umbrella spec §23.1.

- [ ] **Step 1: TDD `HeaderKeys` constants class**

```php
namespace Monadial\Nexus\Ddd\Bus\Header;

/**
 * @psalm-api
 *
 * String constants for bus header names. All keys share the `nexus.` prefix
 * to namespace against application headers.
 */
final class HeaderKeys
{
    public const string CAUSATION_DEPTH = 'nexus.causation.depth';
    public const string IDEMPOTENCY_KEY = 'nexus.idempotency-key';
    public const string PRINCIPAL = 'nexus.principal';
    public const string REPLAY = 'nexus.replay';
    public const string RETRY_ATTEMPT = 'nexus.retry.attempt';
    public const string RETRY_BUDGET_REMAINING_MS = 'nexus.retry.budget_remaining_ms';
}
```

- [ ] **Step 2: TDD `BusHeaders` value object**

```php
namespace Monadial\Nexus\Ddd\Bus\Header;

use Fp\Functional\Option\Option;

/**
 * @psalm-immutable
 * @psalm-api
 *
 * Bus-side headers carried as a Stamp on the Envelope. Per drift-resolution
 * note in this plan's header — the shipped MessageMetadata has no
 * arbitrary-key bag, so bus headers ride here. Future spec revision may
 * collapse into MessageMetadata::headers().
 */
final readonly class BusHeaders
{
    /** @param array<string, scalar> $values */
    public function __construct(public array $values) {}

    public static function empty(): self { return new self([]); }

    /** @return Option<scalar> */
    public function get(string $key): Option
    {
        return Option::fromNullable($this->values[$key] ?? null);
    }

    /** @param scalar $value */
    public function with(string $key, mixed $value): self
    {
        return new self([...$this->values, $key => $value]);
    }
}
```

- [ ] **Step 3: TDD `BusHeadersStamp`**

```php
namespace Monadial\Nexus\Ddd\Bus\Header;

use Monadial\Nexus\Ddd\Messaging\Envelope\Stamp;

final readonly class BusHeadersStamp implements Stamp
{
    public function __construct(public BusHeaders $headers) {}
}
```

- [ ] **Step 4: TDD `CausationPropagationMiddleware`**

Reads the inbound envelope, increments the depth, writes back via `BusHeadersStamp`. If depth exceeds cap, throw `CausationDepthExceededException`.

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Exception\CausationDepthExceededException;
use Monadial\Nexus\Ddd\Bus\Header\BusHeaders;
use Monadial\Nexus\Ddd\Bus\Header\BusHeadersStamp;
use Monadial\Nexus\Ddd\Bus\Header\HeaderKeys;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

final class CausationPropagationMiddleware implements Middleware
{
    public function __construct(private readonly int $depthCap = 32) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $headers = $envelope->stamp(BusHeadersStamp::class)
            ->map(static fn(BusHeadersStamp $s): BusHeaders => $s->headers)
            ->getOrElse(BusHeaders::empty());

        $currentDepth = $headers->get(HeaderKeys::CAUSATION_DEPTH)
            ->map(static fn(int|string|float|bool $v): int => (int) $v)
            ->getOrElse(0);

        $newDepth = $currentDepth + 1;
        if ($newDepth > $this->depthCap) {
            throw CausationDepthExceededException::at($newDepth, $this->depthCap);
        }

        $newHeaders = $headers->with(HeaderKeys::CAUSATION_DEPTH, $newDepth);
        $newEnvelope = $envelope->withStamp(new BusHeadersStamp($newHeaders));
        // ↑ Envelope::withStamp is shipped on messaging package's Envelope.
        // If not, this plan documents the gap; we add a helper.

        return $next($newEnvelope);
    }
}
```

**Open question to surface during execution:** does `Envelope::withStamp(Stamp): Envelope` exist? Phase 1's TDD step verifies. If not, the bus middleware uses a local helper or contributes the method upstream.

- [ ] **Step 5: TDD `OpenTelemetrySpanMiddleware` (no-op default)**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

/**
 * @psalm-api
 *
 * No-op default. Adapter packages (P5) replace with a real OTel span impl
 * that reads/writes traceparent/tracestate per umbrella spec §23.4.
 */
final class OpenTelemetrySpanMiddleware implements Middleware
{
    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        return $next($envelope);
    }
}
```

- [ ] **Step 6: TDD `LoggingStartMiddleware`**

Writes a single INFO log line with structured fields (per umbrella spec §23.1).

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;
use Psr\Log\LoggerInterface;

final class LoggingStartMiddleware implements Middleware
{
    public function __construct(private readonly LoggerInterface $logger) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $this->logger->info('ddd.command.dispatched', [
            'causationId' => $envelope->metadata->causationId
                ->map(static fn($id): string => $id->toString())->getOrElse(''),
            'correlationId' => $envelope->metadata->correlationId
                ->map(static fn($id): string => $id->toString())->getOrElse(''),
            'messageId' => $envelope->metadata->id->toString(),
            'messageType' => $envelope->message::class,
        ]);
        return $next($envelope);
    }
}
```

(LoggingEndMiddleware in Phase 10c is the symmetric exit log.)

- [ ] **Step 7: Run tests + Psalm + PHPCS clean**
- [ ] **Step 8: Commit**

```bash
git commit -m "feat(ddd-bus): pipeline stages 1-3 — causation propagation (depth cap 32), OTel span (no-op default), logging start"
```

### Phase 10b — Validation, Authorization

**Files:**
- Create: `packages/nexus-ddd-bus/src/Middleware/MetricsStartMiddleware.php` (stage 4)
- Create: `packages/nexus-ddd-bus/src/Middleware/ValidationMiddleware.php` (stage 5)
- Create: `packages/nexus-ddd-bus/src/Middleware/AuthorizationMiddleware.php` (stage 6)
- Tests for each

The `MetricsStartMiddleware` reads the `MetricsCollector` from constructor injection; starts a wall-clock timer for `ddd.{kind}.duration_ms`; passes through.

The `ValidationMiddleware` reads the handler's `#[Validate]` attribute (resolved via reflection at boot, cached per handler class), invokes the project's `Validator::validate(message, ValidationContext)`, and lifts non-empty `Violations` to `ValidationFailedException` (or `Either::left` for `tryDispatch`). If the bus has no `Validator` registered AND any handler has `#[Validate]`, boot fails with `MissingValidatorException`.

The `AuthorizationMiddleware` reads the handler's `#[Authorize]` attribute, resolves the subject via `SubjectResolver`, calls `AuthorizationDecider::decide(policy, subject, AuthorizationContext)`. The decider throws `AccessDeniedException` on denial. If the bus has no decider registered AND any handler has `#[Authorize]`, boot fails with `MissingAuthorizationDeciderException`.

**Lock per Q4:** default order is Validate → Authorize. The `#[Authorize(before: 'validation')]` flag flips to Authorize → Validate. The middleware order is determined at registration time; the bus reads the attribute and reorders these two stages for that handler. The `MiddlewareOrderingRule` Psalm rule validates `before:` is a `PipelineStage::names()` value.

**Implementation note:** the simpler model is to ship the pipeline with both middlewares always present and have each middleware check whether it should run for the current handler. The "before:" reordering is then a matter of *which middleware runs first* for that one handler — this is more easily modeled as a *single* "validation+authorization" middleware that sequences internally per-handler rather than two independent middlewares. Plan picks the latter — cleaner. Pseudo-shape:

```php
final class ValidateThenAuthorizeMiddleware implements Middleware
{
    // Reads the handler's #[Authorize(before:)] flag and runs validation + authorization
    // in the order indicated. If neither attribute is present, both phases no-op.
}
```

But this hides the canonical 11-stage shape. **Compromise:** ship two distinct middlewares (`ValidationMiddleware`, `AuthorizationMiddleware`) and document in the bus boot code that for handlers with `#[Authorize(before: 'validation')]`, the bus reverses their relative order in the pipeline composition. The reversal is per-handler — the bus cannot reverse globally. This means the pipeline is *not* exactly the canonical sequence at the implementation level for those handlers. The Psalm rule documents this.

- [ ] **Step 1: TDD `MetricsStartMiddleware`**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricsCollector;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;
use Psr\Clock\ClockInterface;

final class MetricsStartMiddleware implements Middleware
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly MetricsCollector $metrics,
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $this->metrics->count('ddd.command.count', 1, [
            'outcome' => 'started',
            'type' => $envelope->message::class,
        ]);
        return $next($envelope);
    }
}
```

(The duration measurement happens in `MetricsEndMiddleware` — Phase 10c. Threading the start time across middlewares uses the `PipelineContext` scratchpad from Phase 9.)

- [ ] **Step 2: TDD `ValidationMiddleware`**

Tests:
- Handler without `#[Validate]` → middleware passes through.
- Handler with `#[Validate]`, validator returns empty Violations → passes through.
- Handler with `#[Validate]`, validator returns non-empty Violations → throws `ValidationFailedException` carrying those violations.
- Bus instantiated without a Validator AND a handler with `#[Validate]` is registered → boot throws `MissingValidatorException`. (This test belongs in Phase 12 boot validation.)

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Attribute\Validate;
use Monadial\Nexus\Ddd\Bus\Exception\ValidationFailedException;
use Monadial\Nexus\Ddd\Bus\Validation\ValidationContext;
use Monadial\Nexus\Ddd\Bus\Validation\Validator;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator;
use Override;

final class ValidationMiddleware implements Middleware
{
    public function __construct(
        private readonly Validator $validator,
        private readonly CommandHandlerLocator $locator,
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $handlerInfo = $this->locator->locate($envelope->message::class);
        // ↑ The locator returns Option<HandlerInfo> with the handler class
        // and a precomputed list of attributes. If the handler does not
        // declare #[Validate], skip validation. (Caching this lookup
        // happens in the locator impl.)

        $hasValidate = $handlerInfo
            ->flatMap(static fn($info) => $info->attribute(Validate::class))
            ->isSome();
        if (!$hasValidate) {
            return $next($envelope);
        }

        $context = ValidationContext::default();  // populated from MessageContext in real impl
        $violations = $this->validator->validate($envelope->message, $context);
        if (!$violations->isEmpty()) {
            throw ValidationFailedException::with($violations);
        }
        return $next($envelope);
    }
}
```

The `CommandHandlerLocator` shape comes from messaging — verify exact API at execution time. If `locate()` doesn't expose attribute reflection, the bus introduces a small `HandlerAttributeIndex` helper.

- [ ] **Step 3: TDD `AuthorizationMiddleware`**

Mirror shape. Reads `#[Authorize]`, resolves subject via `SubjectResolver`, calls `AuthorizationDecider::decide`. On `AccessDeniedException`, propagates.

- [ ] **Step 4: Run tests + Psalm + PHPCS clean**
- [ ] **Step 5: Commit**

```bash
git commit -m "feat(ddd-bus): pipeline stages 4-6 — metrics start, validation (lifts Violations to ValidationFailedException), authorization (consumes AuthorizationDecider, default Validate→Authorize)"
```

### Phase 10c — Idempotency, OCC retry, handler invocation, event drain, metrics-end, logging-end, span-close, in-process-same-db

**Files:**
- Create: `packages/nexus-ddd-bus/src/Middleware/IdempotencyMiddleware.php` (stage 7)
- Create: `packages/nexus-ddd-bus/src/Middleware/OccRetryMiddleware.php` (stage 8 outer)
- Create: `packages/nexus-ddd-bus/src/Middleware/HandlerInvocationMiddleware.php` (stage 8 inner)
- Create: `packages/nexus-ddd-bus/src/Middleware/EventDrainMiddleware.php` (stage 8 inner; CommandBus only)
- Create: `packages/nexus-ddd-bus/src/Middleware/MetricsEndMiddleware.php` (stage 9)
- Create: `packages/nexus-ddd-bus/src/Middleware/LoggingEndMiddleware.php` (stage 10)
- Create: `packages/nexus-ddd-bus/src/Middleware/SpanCloseMiddleware.php` (stage 11)
- Create: `packages/nexus-ddd-bus/src/Middleware/InProcessSameDbMiddleware.php` (best-effort guard)
- Create: `packages/nexus-ddd-bus/src/Idempotency/IdempotencyKeyResolver.php` (used by IdempotencyMiddleware)
- Tests for each

**Locks per Q3 + Q9 + Q11:**
- Idempotency runs *outside* OCC retry. Same `messageId` + same `IdempotencyKey` across retries reuses the same reservation.
- OCC retry is host-aware. Constructor takes `Profile`. Under `Profile::Sync`, retries per `BackoffStrategy` (default `JitteredExponentialBackoff(base=50ms, cap=2s, maxAttempts=5)`). Under `Profile::Actor`, wraps `OptimisticLockException` in `ActorWriterInvariantViolation` and propagates — supervision handles. Under `Profile::Async`, retry config is per-route.
- Sync-route retry budget defaults to 5s; async budgets to 60s (per §19a). The OCC retry middleware reads `BusHeaders[HeaderKeys::RETRY_BUDGET_REMAINING_MS]` if present, else falls back to the profile default.
- Handler invocation is the innermost middleware. It calls the resolved `CommandHandler`/`QueryHandler`/`EventListener`.
- Event drain happens *only on CommandBus*. After the handler returns, the middleware reads recorded events from the active aggregate (via the `Outbox::flush()` path the messaging package owns) and writes them to the outbox.
- `InProcessSameDbMiddleware` is a *best-effort* runtime guard. Per the implementation note in the plan header — at the bus-middleware layer, the bus does not know which aggregate the handler will load. The middleware ships as a *registration-time* check: if the adopter registers `#[InProcess]`-attributed handlers AND specifies a `connectionName` for them at registration, the middleware compares against the source aggregate's bound connection (also at registration). If mismatch → boot-time `InProcessConnectionMismatchException`. **No runtime check** — that level requires aggregate-package collaboration which we deferred. Document the deferral.

- [ ] **Step 1: TDD `IdempotencyKeyResolver`**

```php
namespace Monadial\Nexus\Ddd\Bus\Idempotency;

use Monadial\Nexus\Ddd\Bus\Attribute\IdempotencyKey as IdempotencyKeyAttribute;
use Monadial\Nexus\Ddd\Bus\Header\BusHeadersStamp;
use Monadial\Nexus\Ddd\Bus\Header\HeaderKeys;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use ReflectionClass;
use ReflectionObject;

final class IdempotencyKeyResolver
{
    /**
     * Per umbrella spec §13.2.1 + §13.4 + Q1:
     *   1. Read #[IdempotencyKey(field:)] attribute on the message class — use the named property's value.
     *   2. Read MessageContext-supplied `nexus.idempotency-key` header (X-Nexus-Idempotency-Key).
     *   3. Fall back to messageId.
     */
    public function resolve(Envelope $envelope): IdempotencyKey
    {
        $messageClass = $envelope->message::class;
        $attrs = (new ReflectionClass($messageClass))->getAttributes(IdempotencyKeyAttribute::class);
        if ($attrs !== []) {
            $attribute = $attrs[0]->newInstance();
            $reflection = new ReflectionObject($envelope->message);
            $value = $reflection->getProperty($attribute->field)->getValue($envelope->message);
            return new IdempotencyKey((string) $value);
        }

        $headerValue = $envelope->stamp(BusHeadersStamp::class)
            ->flatMap(static fn(BusHeadersStamp $s) => $s->headers->get(HeaderKeys::IDEMPOTENCY_KEY));
        if ($headerValue->isSome()) {
            return new IdempotencyKey((string) $headerValue->get());
        }

        return new IdempotencyKey($envelope->metadata->id->toString());
    }
}
```

Tests:
- Message class with `#[IdempotencyKey(field: 'clientRequestId')]` → returns property value.
- Message class without attribute, BusHeadersStamp has `nexus.idempotency-key` → returns header value.
- Message class without attribute, no header → returns `messageId.toString()`.

- [ ] **Step 2: TDD `IdempotencyMiddleware`**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKeyResolver;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyStore;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator;
use Override;

final class IdempotencyMiddleware implements Middleware
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly IdempotencyKeyResolver $resolver,
        private readonly CommandHandlerLocator $locator,
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        // Per umbrella spec §13.2 — handlers opt out via #[Idempotent(off: true)].
        // Profile defaults: sync = off; async/actor = on.
        // For the P0 sync-only impl, idempotency is opt-in via attribute.

        $handlerInfo = $this->locator->locate($envelope->message::class);
        $isOptedOut = $handlerInfo
            ->flatMap(/* check #[Idempotent(off: true)] */)
            ->isSome();
        if ($isOptedOut) {
            return $next($envelope);
        }

        $key = $this->resolver->resolve($envelope);
        $handlerClass = $handlerInfo->map(/* extract handler class */)->getOrElse('unknown');

        $reservation = $this->store->tryReserve($handlerClass, $key);
        if ($reservation->isNone()) {
            // Already handled — short-circuit; return null (commands void; queries n/a in idempotency for P0).
            return null;
        }

        $token = $reservation->get();
        try {
            $result = $next($envelope);
            $this->store->markCompleted($token);
            return $result;
        } catch (\Throwable $e) {
            // Per Q3 — release on terminal failure to allow future redelivery.
            // The OCC retry middleware (next stage in) must NOT wrap commit/release —
            // if OCC retries, the same reservation is reused.
            // For P0 we release on every exception; the OCC retry middleware
            // catches OptimisticLockException above us, retries internally,
            // and only re-raises after retry exhaustion.
            $this->store->release($token);
            throw $e;
        }
    }
}
```

Tests cover happy-path (`tryReserve` → handler succeeds → `markCompleted`), short-circuit on already-handled (`tryReserve → None`), terminal failure (`tryReserve` → handler throws → `release`).

- [ ] **Step 3: TDD `OccRetryMiddleware`**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Exception\ActorWriterInvariantViolation;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Retry\BackoffStrategy;
use Override;
use Psr\Clock\ClockInterface;

final class OccRetryMiddleware implements Middleware
{
    public function __construct(
        private readonly Profile $profile,
        private readonly BackoffStrategy $backoff,
        private readonly ClockInterface $clock,
        private readonly int $defaultBudgetMs,  // 5000 sync, 60000 async
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        if ($this->profile === Profile::Actor) {
            // Per Q9 — under ActorHost, do NOT retry. Wrap any OCC violation
            // as ActorWriterInvariantViolation and let supervision restart
            // the actor.
            try {
                return $next($envelope);
            } catch (OptimisticLockException $e) {
                throw ActorWriterInvariantViolation::forActor(
                    $envelope->message::class,
                    $envelope->metadata->id->toString(),
                    $e,
                );
            }
        }

        // Sync (and Async route under Sync host) — retry per BackoffStrategy.
        // Budget tracking via wall-clock; retry attempt advances; messageId/correlationId
        // preserved per umbrella spec §8.5.1 critical-ordering invariants.

        $deadline = $this->clock->now()->add(new \DateInterval(sprintf('PT%dS', (int) ceil($this->defaultBudgetMs / 1000))));
        $attempt = 0;

        while (true) {
            try {
                return $next($envelope);
            } catch (OptimisticLockException $e) {
                $attempt++;
                if ($this->clock->now() >= $deadline) {
                    throw $e;  // budget exhausted — surface
                }
                $delay = $this->backoff->delayFor($attempt);
                if ($delay !== null) {
                    usleep((int) ($delay->toMicroseconds()));
                }
                // Continue loop. The same envelope (same messageId) is reused —
                // the IdempotencyMiddleware already reserved; the same reservation
                // is held across retries.
                continue;
            }
        }
    }
}
```

Tests:
- `Profile::Sync`, handler succeeds first try → no retry, returns result.
- `Profile::Sync`, handler throws `OptimisticLockException` once, then succeeds → retries once.
- `Profile::Sync`, handler throws repeatedly until budget exhausted → re-throws `OptimisticLockException`.
- `Profile::Sync`, handler throws non-`OptimisticLockException` → propagates immediately, no retry.
- `Profile::Actor`, handler throws `OptimisticLockException` → wraps as `ActorWriterInvariantViolation`, no retry.

- [ ] **Step 4: TDD `HandlerInvocationMiddleware`**

The terminal middleware. Resolves the handler from the locator, invokes it.

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Exception\HandlerNotFoundException;
use Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator;
use Override;

final class HandlerInvocationMiddleware implements Middleware
{
    public function __construct(private readonly CommandHandlerLocator $locator) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $info = $this->locator->locate($envelope->message::class);
        $handler = $info
            ->map(/* extract handler instance */)
            ->getOrElse(null);
        if ($handler === null) {
            throw HandlerNotFoundException::forCommand($envelope->message::class);
        }
        // Invoke. CommandBus: void; QueryBus: typed return; EventBus: void per subscriber (handled in SyncEventBus, not here).
        $handler($envelope->message);
        return $next($envelope);  // For composition; this is the innermost layer.
    }
}
```

(The actual handler invocation shape depends on whether the handler is a marker-interface impl or an `#[CommandHandler]`-attributed method. The locator handles that resolution.)

- [ ] **Step 5: TDD `EventDrainMiddleware`** (CommandBus only)

After the handler returns, drain recorded events from the active aggregate(s) and write to outbox.

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Outbox\Outbox;
use Override;

final class EventDrainMiddleware implements Middleware
{
    public function __construct(private readonly Outbox $outbox) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $result = $next($envelope);  // Run the handler first.
        // Per umbrella spec §11.1 — bus middleware drains recorded events
        // from the aggregate and writes to outbox in the same TX.
        $this->outbox->flush();
        return $result;
    }
}
```

The drain happens by `Outbox::flush()` — the messaging-package outbox is responsible for collecting events recorded inside the handler (typically via `AggregateRepository::save` writing to a transactional staging area). For the sync profile, "outbox" is the in-memory unit of work; in the async profile, it's the DB-backed outbox table.

- [ ] **Step 6: TDD `MetricsEndMiddleware`, `LoggingEndMiddleware`, `SpanCloseMiddleware`** — symmetric exits to phase 10a's start middlewares.

- [ ] **Step 7: TDD `InProcessSameDbMiddleware` (best-effort)**

```php
namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

/**
 * @psalm-api
 *
 * Best-effort runtime guard for #[InProcess] event handlers. Compares the
 * handler's bound connection (registered at boot) against the source
 * aggregate's bound connection (registered at boot); throws
 * InProcessConnectionMismatchException at *registration* time if mismatched.
 *
 * **Limitation:** at runtime, the bus cannot introspect every connection
 * resolution path (per Q8 implementation note). Static analysis (Psalm's
 * InProcessHandlerSameDbRule, deferred to a follow-up package) is the
 * primary line. This middleware closes the obvious cases.
 */
final class InProcessSameDbMiddleware implements Middleware
{
    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        // P0: pass through. Boot-time validation is the load-bearing fence.
        return $next($envelope);
    }
}
```

The *boot-time* validation (Phase 12) is where `InProcessConnectionMismatchException` is actually thrown. The runtime middleware is a placeholder; document the deferral in the README.

- [ ] **Step 8: Run tests + Psalm + PHPCS clean**
- [ ] **Step 9: Commit**

```bash
git commit -m "feat(ddd-bus): pipeline stages 7-11 — IdempotencyKeyResolver + IdempotencyMiddleware (two-phase, outside retry) + OccRetryMiddleware (host-aware: Sync retries, Actor wraps as ActorWriterInvariantViolation; sync budget 5s, async 60s) + HandlerInvocation + EventDrain (CommandBus only) + MetricsEnd + LoggingEnd + SpanClose + InProcessSameDbMiddleware (best-effort, boot-time fence)"
```

---

## Phase 11 — `RoutingStrategy` interface + 4 impls (`ExplicitOnly`, `AttributeBased`, `NamespacePattern`, `Composite`)

**Files:**
- Create: `packages/nexus-ddd-bus/src/Routing/RoutingStrategy.php` (interface)
- Create: `packages/nexus-ddd-bus/src/Routing/RoutingResolution.php` (value object)
- Create: `packages/nexus-ddd-bus/src/Routing/ExplicitOnly.php`
- Create: `packages/nexus-ddd-bus/src/Routing/AttributeBased.php`
- Create: `packages/nexus-ddd-bus/src/Routing/NamespacePattern.php`
- Create: `packages/nexus-ddd-bus/src/Routing/Composite.php`
- Tests for each

**Locks per Q5:** four `RoutingStrategy` impls. The `Composite` chains them in order; first match wins. The order per umbrella spec §8.2 is: (1) explicit DSL routes → (2) `#[OnBus]` attribute → (3) namespace pattern → (4) default.

- [ ] **Step 1: TDD `RoutingStrategy` interface**

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;

/**
 * @psalm-api
 *
 * Resolves a command class to a registered bus name. Returns Option::none()
 * if the strategy cannot resolve — Composite walks to the next strategy.
 */
interface RoutingStrategy
{
    /**
     * @param class-string $messageClass
     * @return Option<RoutingResolution>
     */
    public function resolve(string $messageClass): Option;
}
```

- [ ] **Step 2: TDD `RoutingResolution` value object**

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

/**
 * @psalm-immutable
 * @psalm-api
 */
final readonly class RoutingResolution
{
    public function __construct(
        public string $busName,
        public string $resolvedBy,  // strategy class-name (for tooling — `bin/ddd routes show`)
    ) {}
}
```

- [ ] **Step 3: TDD `ExplicitOnly`**

Holds a static `array<class-string, busName>` map. Returns `Option::some($map[$class])` if mapped, else `Option::none()`.

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;
use Override;

/**
 * @psalm-api
 *
 * Static map of class-string → bus-name. Per umbrella spec §8.2, this is the
 * highest-priority routing strategy in the Composite chain.
 */
final class ExplicitOnly implements RoutingStrategy
{
    /** @var array<class-string, string> */
    private array $routes = [];

    /**
     * @param class-string $messageClass
     */
    public function explicit(string $messageClass, string $busName): self
    {
        $this->routes[$messageClass] = $busName;
        return $this;
    }

    #[Override]
    public function resolve(string $messageClass): Option
    {
        return Option::fromNullable($this->routes[$messageClass] ?? null)
            ->map(fn(string $busName): RoutingResolution => new RoutingResolution($busName, self::class));
    }
}
```

- [ ] **Step 4: TDD `AttributeBased`**

Reads `#[OnBus]` from the message class via reflection.

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Attribute\OnBus;
use Override;
use ReflectionClass;

final class AttributeBased implements RoutingStrategy
{
    #[Override]
    public function resolve(string $messageClass): Option
    {
        $attrs = (new ReflectionClass($messageClass))->getAttributes(OnBus::class);
        if ($attrs === []) {
            return Option::none();
        }
        $name = $attrs[0]->newInstance()->name;
        return Option::some(new RoutingResolution($name, self::class));
    }
}
```

- [ ] **Step 5: TDD `NamespacePattern`**

Matches namespace patterns like `'App\\Reports\\*'`.

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;
use Override;

final class NamespacePattern implements RoutingStrategy
{
    /** @var list<array{pattern: string, busName: string}> */
    private array $patterns = [];

    public function namespace(string $pattern, string $busName): self
    {
        $this->patterns[] = ['busName' => $busName, 'pattern' => $pattern];
        return $this;
    }

    #[Override]
    public function resolve(string $messageClass): Option
    {
        foreach ($this->patterns as $entry) {
            if (fnmatch($entry['pattern'], $messageClass, FNM_NOESCAPE)) {
                return Option::some(new RoutingResolution($entry['busName'], self::class));
            }
        }
        return Option::none();
    }
}
```

(`fnmatch` with `*` matches PHP namespaces sufficiently for the namespace-pattern use case. Document that `**` is *not* required — `*` is a flat wildcard within the namespace tail.)

- [ ] **Step 6: TDD `Composite`**

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;
use Override;

/**
 * @psalm-api
 *
 * Walks sub-strategies in registration order; first Some(...) wins. Per
 * umbrella spec §8.2, the standard order is: ExplicitOnly →
 * AttributeBased → NamespacePattern → (fallback to default).
 */
final class Composite implements RoutingStrategy
{
    /** @param list<RoutingStrategy> $strategies */
    public function __construct(
        private readonly array $strategies,
        private readonly string $defaultBusName,
    ) {}

    #[Override]
    public function resolve(string $messageClass): Option
    {
        foreach ($this->strategies as $strategy) {
            $resolution = $strategy->resolve($messageClass);
            if ($resolution->isSome()) {
                return $resolution;
            }
        }
        // Fallback to default — always Some.
        return Option::some(new RoutingResolution($this->defaultBusName, self::class));
    }
}
```

- [ ] **Step 7: Run tests + Psalm + PHPCS clean**
- [ ] **Step 8: Commit**

```bash
git commit -m "feat(ddd-bus): RoutingStrategy interface + 4 impls (ExplicitOnly, AttributeBased, NamespacePattern, Composite) + RoutingResolution VO"
```

---

## Phase 12 — `BusRegistry` + `CommandRouter` + boot-time profile×routing validation

**Files:**
- Create: `packages/nexus-ddd-bus/src/Routing/BusRegistry.php`
- Create: `packages/nexus-ddd-bus/src/Routing/CommandRouter.php`
- Tests

**Locks per Q11 (degradeAsyncToSync, bus-name typo, InProcess+SharedInvocation boot error):**
- `BusRegistry` holds `array<busName, CommandBus|QueryBus|EventBus>` (separate registries per kind, or one tagged registry — pick one impl).
- At construction, `BusRegistry::validate(Profile, RoutingStrategy)` walks the routing decisions for every registered message class and asserts:
  - The named bus exists → else `BusNameNotRegisteredException`.
  - The named bus is allowed under the profile → else `BusNotAvailableInProfileException`.
- `degradeAsyncToSync` flag (per umbrella spec §8.2.1): when active AND `Profile::Sync` AND dev sentinel, async-only routes are demoted to default. Each demotion logs a boot-time WARNING.

The `CommandRouter` wraps a `BusRegistry` + a `Composite` routing strategy, exposing `routeFor(class-string): CommandBus`.

- [ ] **Step 1: TDD `BusRegistry`**

Tests:
- Register `('default', SyncCommandBus)` + `('long-running', /* mocked AsyncCommandBus */)`. Lookup by name returns the registered impl.
- `validate(Profile::Sync, …)` with a route to `'long-running'` → throws `BusNotAvailableInProfileException`.
- `validate(Profile::Async, …)` with a route to `'long-running'` → no throw.
- Routing to `'long-runnning'` (typo) → throws `BusNameNotRegisteredException` listing `['default', 'long-running']`.
- `degradeAsyncToSync = true` + `Profile::Sync` + APP_ENV=dev → no throw, logs WARNING.
- `degradeAsyncToSync = true` + `Profile::Sync` + APP_ENV=prod → throws (degrade flag is dev-only).

- [ ] **Step 2: TDD `CommandRouter`**

```php
namespace Monadial\Nexus\Ddd\Bus\Routing;

use Monadial\Nexus\Ddd\Bus\Exception\BusNameNotRegisteredException;
use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;

final class CommandRouter
{
    public function __construct(
        private readonly BusRegistry $registry,
        private readonly RoutingStrategy $strategy,
    ) {}

    /**
     * @param class-string $messageClass
     */
    public function routeFor(string $messageClass): CommandBus
    {
        $resolution = $this->strategy->resolve($messageClass)->get();  // Composite always Some
        $bus = $this->registry->command($resolution->busName);
        return $bus->getOrElseThrow(fn() => BusNameNotRegisteredException::for(
            $resolution->busName,
            $this->registry->commandNames(),
        ));
    }
}
```

(Analogous `QueryRouter` and `EventRouter` skipped from the plan as boilerplate-after-pattern-locked. Add as needed in Phase 13's bus impls.)

- [ ] **Step 3: TDD `MissingValidatorException` + `MissingAuthorizationDeciderException` boot validation**

These are validated by the bus boot code (the `NexusDddBusBuilder` or analogous orchestrator — name TBD; for P0 we ship a `BusBuilder` factory). At construction, the builder walks all registered handlers; if any has `#[Validate]` and no `Validator` is registered → throw `MissingValidatorException`. Same for `#[Authorize]`.

This is implemented as a small `BusBuilder::validate()` step. Place it in `Routing/BusBuilder.php` or in `Bus/SyncCommandBus.php` constructor — pick the cleaner shape at execution time.

- [ ] **Step 4: TDD `InProcess` + `SharedInvocation` boot error** (per umbrella spec §11.2.1)

Combining `#[InProcess]` + `#[SharedInvocation]` on the same handler is a boot error. The boot code (in `BusBuilder` or `EventRouter` registration) walks registered event handlers and throws on the combination. (`SharedInvocation` is from messaging — verify the attribute exists. If not, this validation defers to follow-up.)

- [ ] **Step 5: Run tests + Psalm + PHPCS clean**
- [ ] **Step 6: Commit**

```bash
git commit -m "feat(ddd-bus): BusRegistry + CommandRouter + boot-time profile×routing validation (BusNotAvailableInProfile/BusNameNotRegistered) + missing-validator/decider boot checks + degradeAsyncToSync dev-only fallback"
```

---

## Phase 13 — `SyncCommandBus`, `SyncQueryBus`, `SyncEventBus`

**Files:**
- Create: `packages/nexus-ddd-bus/src/Bus/RichCommandBus.php` (interface)
- Create: `packages/nexus-ddd-bus/src/Bus/RichQueryBus.php` (interface)
- Create: `packages/nexus-ddd-bus/src/Bus/RichEventBus.php` (interface)
- Create: `packages/nexus-ddd-bus/src/Bus/SyncCommandBus.php`
- Create: `packages/nexus-ddd-bus/src/Bus/SyncQueryBus.php`
- Create: `packages/nexus-ddd-bus/src/Bus/SyncEventBus.php`
- Tests

**Locks per Q2 (Accepted marker) + drift-resolution (Rich* interfaces):**
- `RichCommandBus extends CommandBus` adds `tryDispatch(Command): Either<Throwable, Accepted>`. The shipped `dispatchCommand(Command): void` continues to work.
- `RichQueryBus extends QueryBus` adds `tryAsk(Query<TResult>): Either<Throwable, TResult>` (template parameter flows through).
- `RichEventBus extends EventBus` adds `tryPublish(DomainEvent): Either<Throwable, Accepted>`.
- The concrete `SyncCommandBus` implements `RichCommandBus` AND `EnvelopedCommandBus` (both — for compatibility with messaging's outbox-flush path).

- [ ] **Step 1: TDD `RichCommandBus` interface**

```php
namespace Monadial\Nexus\Ddd\Bus\Bus;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Bus\Marker\Accepted;
use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;
use Monadial\Nexus\Ddd\Messaging\Message\Command;

/**
 * @psalm-api
 *
 * Per umbrella spec §8.6 + Q2 — adds tryDispatch() returning
 * Either<Throwable, Accepted>. Tracing rides on
 * MessageContext::current()->metadata->id, NOT on the Accepted marker.
 *
 * Drift note: this interface lives in nexus-ddd-bus until messaging
 * collapses it into the base CommandBus interface (post-merge follow-up #2).
 */
interface RichCommandBus extends CommandBus
{
    /**
     * @return Either<\Throwable, Accepted>
     */
    public function tryDispatch(Command $command): Either;
}
```

- [ ] **Step 2: TDD `SyncCommandBus`**

The bus composes the canonical 11-stage pipeline. Constructor takes:
- `Profile $profile`
- `MessageContextStack $contextStack` (DI per "no singletons" rule)
- `ClockInterface $clock`
- `LoggerInterface $logger`
- `MetricsCollector $metrics` (default `NoOpMetricsCollector`)
- `Validator $validator` (optional via Option-style — apps without `#[Validate]` need not register)
- `AuthorizationDecider $decider` (same)
- `IdempotencyStore $idempotencyStore` (`InMemoryIdempotencyStore` for P0)
- `IdempotencyKeyResolver $idempotencyKeyResolver`
- `CommandHandlerLocator $locator` (from messaging)
- `Outbox $outbox` (from messaging — for event drain)
- `BackoffStrategy $backoff` (default `JitteredExponentialBackoff(50ms, 2s, 5)`)
- `int $retryBudgetMs` (default 5000 sync; 60000 async)
- `list<Middleware> $extraMiddlewares` — adopter-supplied additions

The bus assembles the pipeline:

```php
public function dispatchCommand(Command $command): void
{
    $this->tryDispatch($command)->fold(
        onLeft: fn(\Throwable $e) => throw $e,
        onRight: static fn(Accepted $_): null => null,
    );
}

public function tryDispatch(Command $command): Either
{
    try {
        $envelope = new Envelope(
            $command,
            MessageMetadata::root($this->clock),  // root if no parent context; otherwise propagated
        );
        $this->pipeline->dispatch($envelope);
        return Either::right(Accepted::instance());
    } catch (\Throwable $e) {
        return Either::left($e);
    }
}
```

The pipeline construction reads `#[Authorize(before: 'validation')]` per-handler at registration (cached) and reorders the validation/authorization middlewares for that handler. The simpler P0 model: ship one shared pipeline that always runs validation then authorization; defer per-handler reordering to a follow-up if it turns out adopters need it.

Tests (smoke-level):
- Register a fixture handler. Dispatch command. Assert handler was called.
- Dispatch with a `Validator` that returns violations → `tryDispatch` returns `Either::left(ValidationFailedException)`.
- Dispatch with an `AuthorizationDecider` that throws → `tryDispatch` returns `Either::left(AccessDeniedException)`.
- Dispatch the same command twice (same `IdempotencyKey`) → second short-circuits.
- Dispatch with `Profile::Actor` and a handler that throws `OptimisticLockException` → re-throws as `ActorWriterInvariantViolation`.

- [ ] **Step 3: TDD `SyncQueryBus`** — analogous, but `dispatchQuery` returns `mixed` (the query result). No idempotency middleware (queries are inherently idempotent and the spec says idempotency is for commands). No event drain.

- [ ] **Step 4: TDD `SyncEventBus`** — fan-out to N subscribers. For `Profile::Sync`, all subscribers run in-tx (per umbrella spec §11.0 — sync profile has no outbox). For other profiles, subscribers route via outbox + relay (not in P0).

- [ ] **Step 5: Run tests + Psalm + PHPCS clean**
- [ ] **Step 6: Commit**

```bash
git commit -m "feat(ddd-bus): RichCommandBus/RichQueryBus/RichEventBus interfaces + SyncCommandBus + SyncQueryBus + SyncEventBus (assembles canonical 11-stage pipeline)"
```

---

## Phase 14 — `IdempotencyKeyResolver` integration

**Note:** `IdempotencyKeyResolver` was created in Phase 10c (because `IdempotencyMiddleware` consumes it). Phase 14 is the integration phase: hook `IdempotencyKeyResolver` into the bus boot code, ensure resolution paths are tested, document the order (attribute → BusHeaders → messageId).

**Files:**
- Modify: `packages/nexus-ddd-bus/src/Bus/SyncCommandBus.php` (verify `IdempotencyKeyResolver` is wired)
- Modify: `packages/nexus-ddd-bus/src/Idempotency/IdempotencyKeyResolver.php` (add explicit support for `MessageContext`-supplied header)
- Tests: `packages/nexus-ddd-bus/tests/Unit/Idempotency/IdempotencyKeyResolverIntegrationTest.php`

- [ ] **Step 1: Test the three resolution paths in integration**
  - Command class with `#[IdempotencyKey(field: 'clientRequestId')]` + bus dispatches it twice with same `clientRequestId` → second short-circuits.
  - Command class without attribute, but envelope has `BusHeadersStamp[nexus.idempotency-key]` → second dispatch with same header value short-circuits.
  - Command class without attribute and no header → falls back to `messageId`. Two dispatches with different `messageId` both run.

- [ ] **Step 2: Add HTTP-header documentation in the README** — adapter packages (`nexus-ddd-symfony`) populate `BusHeaders[nexus.idempotency-key]` from `X-Nexus-Idempotency-Key` HTTP header per umbrella spec §13.4.

- [ ] **Step 3: Run tests + Psalm + PHPCS clean**
- [ ] **Step 4: Commit**

```bash
git commit -m "feat(ddd-bus): IdempotencyKeyResolver integration — verify three-tier resolution (attribute → BusHeaders.nexus.idempotency-key → messageId fallback)"
```

---

## Phase 15 — `bin/ddd routes show` CLI command

**Files:**
- Create: `packages/nexus-ddd-bus/bin/ddd` (executable shim)
- Create: `packages/nexus-ddd-bus/src/Cli/RoutesShowCommand.php`
- Tests: `packages/nexus-ddd-bus/tests/Unit/Cli/RoutesShowCommandTest.php`

The command prints the registered routing table; with a class arg, shows how that class resolves through the composite strategy (per umbrella spec §27.1).

The framework-agnostic CLI uses no symfony/console (per "no Symfony deps" lock). Plan: ship the simplest possible command runner — a script in `bin/ddd` that dispatches to a `Cli\Command` interface (one method, `run(array $args, OutputInterface): int`). For P0, only `routes show` exists.

- [ ] **Step 1: TDD `RoutesShowCommand`**

```php
namespace Monadial\Nexus\Ddd\Bus\Cli;

use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\Composite;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingStrategy;

final class RoutesShowCommand
{
    public function __construct(
        private readonly BusRegistry $registry,
        private readonly RoutingStrategy $strategy,
    ) {}

    /**
     * @param list<string> $args
     */
    public function run(array $args): string
    {
        if ($args === []) {
            return $this->renderAll();
        }
        $messageClass = $args[0];
        return $this->renderOne($messageClass);
    }

    private function renderAll(): string
    {
        // Print: bus-name → impl-class
        // Print: known-message-class → resolved-bus → resolved-by-strategy
        return /* tabular output */;
    }

    private function renderOne(string $messageClass): string
    {
        $resolution = $this->strategy->resolve($messageClass)->get();
        return sprintf('%s → bus `%s` (resolved by %s)', $messageClass, $resolution->busName, $resolution->resolvedBy);
    }
}
```

Tests cover both modes (no args, single-arg).

- [ ] **Step 2: TDD `bin/ddd` shim** — minimal shell script that loads the autoloader and dispatches to `RoutesShowCommand` if `$argv[1] === 'routes'` and `$argv[2] === 'show'`. Future commands (other phases / packages) extend this.

- [ ] **Step 3: Run tests + Psalm + PHPCS clean**
- [ ] **Step 4: Commit**

```bash
git commit -m "feat(ddd-bus): bin/ddd routes show CLI command (no symfony/console — minimal CLI shim)"
```

---

## Phase 16 — Smoke tests (full-pipeline end-to-end)

**Files:**
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/Fixtures/PlaceOrder.php` (readonly command)
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/Fixtures/PlaceOrderHandler.php` (implements `CommandHandler`)
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/Fixtures/CancelOrder.php` (with `#[Authorize]`)
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/Fixtures/CancelOrderHandler.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/PlaceOrderEndToEndSmokeTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/ValidationFailureSmokeTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/AuthorizationDeniedSmokeTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/IdempotencyShortCircuitSmokeTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/OccRetryRetriesAndRecoversSmokeTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/OccRetryActorWrapsAsInvariantSmokeTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Smoke/CausationDepthExceededSmokeTest.php`

- [ ] **Step 1: TDD `PlaceOrderEndToEndSmokeTest`** — the happy-path smoke. Dispatch `PlaceOrder`, assert handler runs, assert metrics emitted, assert idempotency token committed, assert event drained.

- [ ] **Step 2: TDD `ValidationFailureSmokeTest`** — fixture validator returns Violations, assert `tryDispatch` returns `Either::left(ValidationFailedException)`.

- [ ] **Step 3: TDD `AuthorizationDeniedSmokeTest`** — fixture decider throws `AccessDeniedException`, assert `tryDispatch` returns `Either::left`.

- [ ] **Step 4: TDD `IdempotencyShortCircuitSmokeTest`** — dispatch same command twice (same idempotency key), assert second is short-circuited.

- [ ] **Step 5: TDD `OccRetryRetriesAndRecoversSmokeTest`** — handler throws `OptimisticLockException` once, succeeds on second attempt, assert two attempts.

- [ ] **Step 6: TDD `OccRetryActorWrapsAsInvariantSmokeTest`** — `Profile::Actor` + handler throws `OptimisticLockException`, assert `tryDispatch` returns `Either::left(ActorWriterInvariantViolation)`.

- [ ] **Step 7: TDD `CausationDepthExceededSmokeTest`** — synthesize an envelope with `BusHeaders[nexus.causation.depth] = 32`, dispatch, assert `CausationDepthExceededException`.

- [ ] **Step 8: Run all smoke tests; Psalm + PHPCS clean**
- [ ] **Step 9: Commit**

```bash
git commit -m "test(ddd-bus): smoke tests covering full-pipeline (place-order happy path, validation failure, authorization denied, idempotency short-circuit, OCC retry recovers, OCC actor-mode wraps as invariant, causation-depth exceeded)"
```

---

## Phase 17 — Psalm rules in `nexus-psalm`

**Files (in `nexus-psalm` package, NOT in `nexus-ddd-bus`):**
- Create: `packages/nexus-psalm/src/Hook/Bus/CommandHandlerReturnTypeRule.php`
- Create: `packages/nexus-psalm/src/Hook/Bus/CommandReturnValueIgnoredRule.php`
- Create: `packages/nexus-psalm/src/Hook/Bus/ValidatedCommandReadonlyRule.php`
- Create: `packages/nexus-psalm/src/Hook/Bus/IdempotencyKeyFieldExistsRule.php`
- Create: `packages/nexus-psalm/src/Hook/Bus/AuthorizeBeforeValidationRule.php`
- Create: `packages/nexus-psalm/src/Hook/Bus/UnguardedExternalSideEffectRule.php`
- Create: `packages/nexus-psalm/src/Hook/Bus/MiddlewareOrderingRule.php`
- Create matching `Issue/` classes for each
- Create fixtures + tests for each rule (~7 rules × 3 files = ~21 new files)
- Modify: `packages/nexus-psalm/src/Plugin.php` — register the new rules

Each rule ships with TDD: write the fixture (a class that should fire the rule and a class that should not), assert via the Plugin test harness.

- [ ] **Step 1: TDD `CommandHandlerReturnTypeRule`** — every `CommandHandler::__invoke` and every method tagged `#[CommandHandler]` MUST declare `: void` return type. Fires `CommandHandlerNonVoidReturn` issue. Fixture: a handler declaring `: string` fires; a handler declaring `: void` doesn't.

- [ ] **Step 2: TDD `CommandReturnValueIgnoredRule`** — `$bus->dispatchCommand($cmd)` returns void; assigning to a variable is dead code. Fires `CommandReturnValueAssigned` issue.

- [ ] **Step 3: TDD `ValidatedCommandReadonlyRule`** — commands tagged `#[Validate]` MUST be `readonly` classes. Fixture: a non-readonly command with `#[Validate]` fires.

- [ ] **Step 4: TDD `IdempotencyKeyFieldExistsRule`** — `#[IdempotencyKey(field: 'clientRequestId')]` MUST name a property that exists on the command class AND returns `string`. Fixture: missing field fires; non-string field fires.

- [ ] **Step 5: TDD `AuthorizeBeforeValidationRule`** — `#[Authorize(before: '…')]` MUST name a canonical pipeline stage (per `PipelineStage::names()`). Fixture: `before: 'validashion'` fires; `before: 'validation'` doesn't.

- [ ] **Step 6: TDD `UnguardedExternalSideEffectRule`** — flags handlers calling external-side-effect APIs (configurable allow-list of "external" classes — `Mailer`, `HttpClient`, `PaymentGateway`, etc.) without an active `#[IdempotencyKey]` on the command. Per umbrella spec §22. The rule warns rather than errors. Fixture: a handler that calls `Mailer::send()` from a command with no `#[IdempotencyKey]` warns; same handler from a command with the attribute doesn't.

- [ ] **Step 7: TDD `MiddlewareOrderingRule`** — flags adopter-registered middlewares whose `before:` / `after:` arguments don't name a canonical `PipelineStage`. Per umbrella spec §8.5.1.

- [ ] **Step 8: Register all 7 rules in `Plugin.php`**

- [ ] **Step 9: Run plugin testsuite; all rules pass**
- [ ] **Step 10: Commit each rule as its own commit (per existing nexus-psalm convention — see Phase-5b in messaging plan)**

```bash
git commit -m "feat(psalm): CommandHandlerReturnTypeRule (void return required for command handlers)"
# … 6 more commits, one per rule
```

---

## Phase 18 — Fitness tests

**Files:**
- Create: `packages/nexus-ddd-bus/tests/Unit/Fitness/PackageDependencyFitnessTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Fitness/ForbiddenImportsFitnessTest.php`
- Create: `packages/nexus-ddd-bus/tests/Unit/Fitness/AbstractClassReadonlyOrFinalFitnessTest.php`

`PackageDependencyFitnessTest` walks `src/` and asserts no `use` statements outside the allowed set: `fp4php/functional`, `nexus-actors/ddd-core`, `nexus-actors/ddd-messaging`, `psr/log`, `psr/event-dispatcher`, `psr/clock`, `psr/container`, internal bus package. Specifically forbids: `nexus-actors/ddd-aggregate`, `nexus-persistence*`, `Symfony\*`, `Doctrine\*`, `Monolog\*`.

`ForbiddenImportsFitnessTest` mirrors the aggregate package's fitness test.

`AbstractClassReadonlyOrFinalFitnessTest` verifies all classes in `src/` are either `abstract` or `final`.

- [ ] **Step 1–3: TDD each fitness test**
- [ ] **Step 4: Commit**

```bash
git commit -m "test(ddd-bus): fitness functions (package deps, forbidden imports, final-or-abstract)"
```

---

## Phase 19 — Documentation pass

**Files:**
- Create: `packages/nexus-ddd-bus/README.md`
- Verify: every public `interface` and `class` has a `@psalm-api` docblock with a 2-paragraph description.

The README MUST:
- Reference §25.6 known limitations (causation-chain integrity across writer-id changes; snapshot-vs-event-store transactional divergence — though those are aggregate-side, the bus README mentions the cross-package implication).
- Reference §11.2 / Q8 — the `InProcessSameDbMiddleware` is best-effort runtime-fence; static analysis is the primary line.
- Document the **sync-confirmation cookbook** (per umbrella spec §8.6.1): under `SyncCommandBus`, the controller can `dispatch(PlaceOrder)` then `queryBus->ask(GetOrderById($id))` and read the new state because the read goes through the same DB connection. Document this as the recommended pattern for sync-profile UX flows.
- Document the **handler-may-read-MessageContext** rule (per umbrella spec §7.3): handlers are application services; they MAY read `MessageContextStack::current()` for diagnostics. Aggregates / VOs / specs / policies MAY NOT (the `DomainContextLeakRule` Psalm rule, deferred, enforces this at static analysis time).
- Document the **out-of-scope deferrals** clearly:
  - `AsyncCommandBus`/`AsyncEventBus` → `nexus-ddd-async` (P3)
  - `ActorCommandBus` → `nexus-ddd-actor` (P4)
  - `OutboxEventBus` (DB-backed outbox + relay) → `nexus-ddd-outbox` (P3) per §11
  - DB-backed `IdempotencyStore` impl → `nexus-ddd-bus-idempotency-doctrine`
  - Symfony bundle integration → `nexus-ddd-symfony` (P4)
  - OpenTelemetry SDK adapter → `nexus-ddd-otel-adapter` (the bus ships only the no-op slot)
  - SELECT FOR UPDATE SKIP LOCKED outbox locking discipline → `nexus-ddd-outbox` (per umbrella spec §11.4)
  - PM compensation / `CommandEmissionFailed` system event → `nexus-ddd-process-manager` (P2)

- [ ] **Step 1: Write README**
- [ ] **Step 2: Docblock sweep**
- [ ] **Step 3: Commit**

```bash
git commit -m "docs(ddd-bus): README + class docblock pass; sync-confirmation cookbook + handler-may-read-MessageContext + clear deferrals"
```

---

## Phase 20 — Final CI sweep + PR

- [ ] **Step 1: Full pipeline**

```bash
docker compose exec -T php composer dump-autoload --quiet
docker compose exec -T php vendor/bin/psalm --no-cache
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
docker compose exec -T php vendor/bin/phpunit --testsuite=psalm
docker compose exec -T php vendor/bin/phpcs packages/nexus-ddd-bus/
docker compose exec -T php vendor/bin/php-cs-fixer fix --dry-run packages/nexus-ddd-bus/src
docker compose exec -T php vendor/bin/deptrac
```

All clean.

- [ ] **Step 2: Push branch + open PR**

```bash
git push -u origin feat/nexus-ddd-bus
gh pr create --title "feat(ddd): add nexus-ddd-bus package" --body "$(cat <<'BODY'
## Summary

Adds `nexus-ddd-bus` — the central dispatch fabric for the Nexus DDD framework. P0 scope:

- `SyncCommandBus`, `SyncQueryBus`, `SyncEventBus` impls (per umbrella spec §8)
- Canonical 11-stage middleware pipeline (causation → OTel-noop → logging-start → metrics-start → validation → authorization → idempotency → OCC retry → handler → event-drain → metrics-end → logging-end → span-close), per §8.5.1
- `BusRegistry` + `CommandRouter` + composite routing (`ExplicitOnly` / `AttributeBased` / `NamespacePattern` / `Composite`), per §8.2
- `Profile` enum (Sync / Async / Actor) + boot-time profile×routing validation, per §8.2.1
- All 4 slot interfaces — `Validator`, `AuthorizationDecider`, `IdempotencyStore`, `MetricsCollector` — with default impls (`NoOpMetricsCollector`, `InMemoryIdempotencyStore`)
- `IdempotencyReservation` value object + two-phase tryReserve / markCompleted / release contract (per §13)
- 7 attributes — `Validate`, `Authorize`, `OnBus`, `IdempotencyKey`, `Idempotent`, `InProcess`, `CommandHandler`
- 11 exception classes — `BusException` root + 10 concrete (10 in this PR; `ValidationFailedException` carries `Violations` per §8.5.1.1)
- `Accepted` typed marker for `tryDispatch()` returns (per §8.6 / Q2)
- Host-aware OCC retry middleware: under `Profile::Sync`, retries per `BackoffStrategy`; under `Profile::Actor`, wraps `OptimisticLockException` as `ActorWriterInvariantViolation` and lets supervision restart, per Q9
- `CausationDepthExceededException` + depth-counter middleware (default cap 32) per §8.5.1
- `BusHeaders` + `BusHeadersStamp` for header-style metadata (drift-resolution: shipped MessageMetadata has no headers map)
- 7 new Psalm rules in nexus-psalm: `CommandHandlerReturnTypeRule`, `CommandReturnValueIgnoredRule`, `ValidatedCommandReadonlyRule`, `IdempotencyKeyFieldExistsRule`, `AuthorizeBeforeValidationRule`, `UnguardedExternalSideEffectRule`, `MiddlewareOrderingRule`
- `bin/ddd routes show [<command-class>]` CLI command (per §27.1)
- Smoke tests covering full pipeline + fitness tests + comprehensive docblocks

Reuses already-shipped: `CommandBus` / `QueryBus` / `EventBus` / `EnvelopedCommandBus` / `EnvelopedQueryBus` / `EnvelopedEventBus` / `CommandHandler` / `QueryHandler` / `EventListener` / `MessageId` / `MessageMetadata` / `MessageContext` / `MessageContextStack` / `Outbox` / `MessageInbox` / `BackoffStrategy` impls / retry policies / `TerminalFailure` / `TransientFailure` / `Envelope` / `Stamp` from nexus-ddd-messaging. `OptimisticLockException` from nexus-ddd-core. `AggregateRepository` is consumed at runtime by handlers but NOT imported by this package.

## Test plan

- [x] make psalm — clean
- [x] make test-unit — N tests pass
- [x] phpcs / php-cs-fixer — clean
- [x] deptrac — no boundary violations
- [ ] Mutation testing (deferred to follow-up PR)
- [ ] Cross-package integration via nexus-ddd-async (follow-up package)

## Notes

Spec reference: docs/superpowers/specs/2026-05-06-nexus-ddd-umbrella-design.md v7 §8 + §11 + §13 + §19a + §22 + §27.1.

## Out of scope (deferred packages)

- `AsyncCommandBus`/`AsyncEventBus` → `nexus-ddd-async` (P3)
- `ActorCommandBus`/`ActorHost` → `nexus-ddd-actor` (P4)
- `OutboxEventBus` + DB-backed outbox + relay → `nexus-ddd-outbox` (P3)
- DB-backed `IdempotencyStore` impl → `nexus-ddd-bus-idempotency-doctrine`
- Symfony bundle integration → `nexus-ddd-symfony` (P4)
- OpenTelemetry SDK adapter → `nexus-ddd-otel-adapter`
- `OneAggregatePerCommandMiddleware` (lives in `nexus-ddd-aggregate` — contributed at runtime via DI tag)

## Drift resolutions reconciled

- v7 spec uses `MessageContext::headers()`; shipped `MessageMetadata` has no headers map. Bus introduces `BusHeaders` carried as `Stamp` on the Envelope. Post-merge follow-up #1 collapses into MessageMetadata.
- v7 spec adds `tryDispatch()` to `CommandBus`; shipped interface has only `dispatchCommand(): void`. Bus introduces `RichCommandBus extends CommandBus`. Post-merge follow-up #2.
- `InProcessSameDbMiddleware` is best-effort (registration-time fence; runtime introspection deferred per Q8 implementation note). Static analysis is the primary line.
BODY
)"
```

---

## Self-review checklist

Before considering the plan complete, verify:

- [ ] Every public class in the file structure has a Phase that produces it.
- [ ] Every Phase has TDD ordering (test → fail → impl → pass → commit) with concrete code or detailed outline.
- [ ] Cross-references to v7 §8 / §11 / §13 / §19a / §22 / §27.1 are correct.
- [ ] No type/method-name drift between phases (e.g., `tryDispatch` in Phase 13 matches `RichCommandBus::tryDispatch` in the docblock).
- [ ] All 11 v7 fixes (Q1–Q11 + sub-items) have a corresponding phase or sub-step:
  - Q1 (`IdempotencyKeyResolver` reads attribute → `BusHeaders[nexus.idempotency-key]` → `messageId`) → Phase 14
  - Q2 (`tryDispatch` returns `Either<Throwable, Accepted>`; `Accepted` typed marker) → Phase 3 + Phase 13
  - Q3 (two-phase IdempotencyStore: `tryReserve` returns `Option<IdempotencyReservation>`) → Phase 7
  - Q4 (default Validate→Authorize; `#[Authorize(before:)]` flips; `MiddlewareOrderingRule` validates) → Phase 10b + Phase 17
  - Q5 (Composite routing: explicit → attribute → namespace → default) → Phase 11
  - Q6 (`Validator::validate()` returns `Violations`, never throws; middleware lifts) → Phase 4 + Phase 10b
  - Q7 (`#[Authorize(policy:, subject:)]` — string property-name OR callable) → Phase 5 + Phase 8
  - Q8 (`InProcessSameDbMiddleware` best-effort heuristic; runtime fence deferred) → Phase 10c + Phase 19 docs
  - Q9 (host-aware OCC retry: Sync retries, Actor wraps as `ActorWriterInvariantViolation`) → Phase 10c
  - Q10 (`OneAggregatePerCommandMiddleware` lives in aggregate, NOT here; bus ships only `Middleware` interface) → File Structure note + Phase 9
  - Q11 sub-items mapped:
    - degradeAsyncToSync → Phase 12
    - marker-interface canonical → Phase 8 attributes + Phase 19 docs
    - X-Nexus-Idempotency-Key HTTP header → Phase 14
    - causation-depth limit → Phase 10a
    - SELECT FOR UPDATE SKIP LOCKED → out of scope (deferred to outbox package)
    - sync-route-aware retry budget (5s/60s) → Phase 10c
    - InProcess+SharedInvocation boot error → Phase 12
    - bin/ddd routes show → Phase 15
    - sync-confirmation cookbook → Phase 19 docs
    - handler-may-read-MessageContext → Phase 19 docs
    - UnguardedExternalSideEffectRule → Phase 17
- [ ] Out-of-scope items are clearly marked deferred:
  - `AsyncCommandBus` / `AsyncEventBus` → `nexus-ddd-async` (P3)
  - `ActorCommandBus` → `nexus-ddd-actor` (P4)
  - `OutboxEventBus` (DB-backed outbox + relay) → `nexus-ddd-outbox` (P3)
  - DB-backed `IdempotencyStore` → `nexus-ddd-bus-idempotency-doctrine`
  - Symfony bundle → `nexus-ddd-symfony` (P4)
  - OpenTelemetry SDK adapter → `nexus-ddd-otel-adapter` (bus ships only the no-op slot)
- [ ] No `add()` method on the bus interface (commands/queries/events only — not aggregate persistence).
- [ ] `#[CommandHandler]` attribute is created in this package (does not exist in messaging).
- [ ] `MessageId` is reused from messaging (not redefined here).
- [ ] `EnvelopedCommandBus` / `EnvelopedQueryBus` / `EnvelopedEventBus` are reused from messaging — `SyncCommandBus` etc. implement them.
- [ ] Plan reconciles the spec/code drift on `MessageMetadata::headers()` (BusHeaders + BusHeadersStamp).
- [ ] Plan reconciles the spec/code drift on `tryDispatch()` (RichCommandBus interface in this package).
- [ ] Phase ordering follows dependency chain (no phase imports types defined later) — Phase 4's `ValidationContext` references `BusHeaders` which arrives in Phase 11 (mitigated: Phase 4 uses `array<string, scalar>`, Phase 11 includes a small migration commit).
- [ ] Every phase ends with `phpunit --testsuite=unit` passing all current tests.
- [ ] No GrumPHP overhead on `.md` plan files — the plan is markdown.
- [ ] Phase 17 ships exactly 7 Psalm rules per the brief; deferred ones are documented.
- [ ] Phase 18 ships 3 fitness tests, all enforce package boundaries.
- [ ] Phase 19 README documents the 5 deferral categories.
- [ ] Phase 20 PR title fits under 70 characters.
- [ ] No singleton classes in the implementation. `Accepted::instance()` is a stateless cached marker (per CLAUDE.md "named constructors and factories are fine") — if Psalm flags it, fall back to `new Accepted()`.
- [ ] The plan does NOT introduce `MultiAggregateTransactionException` enforcement (that's bus-middleware's job in `nexus-ddd-aggregate`'s `OneAggregatePerCommandMiddleware`, not this package's responsibility).
- [ ] All template bounds reconciled: `Either<Throwable, Accepted>` on `tryDispatch`; `Either<Throwable, TResult>` on `tryAsk`.
- [ ] No use of `?T` in framework signatures except documented exceptions (`Authorize::$subject`, `Authorize::$before`, `ValidationContext::$principal`, `AuthorizationContext::$principal` — all narrow exceptions to the no-`null` rule).
- [ ] Pre-commit GrumPHP runs in Docker (covered by project's CLAUDE.md).
- [ ] All `composer.json` constraints match the project's locked versions (PHP 8.5+, fp4php ^6, PHPUnit ^13).

---

## Execution handoff

After this plan is reviewed:

**1. Subagent-Driven (recommended)** — fresh subagent per phase; two-stage review per phase (spec compliance, then code quality). Best for keeping context clean across the 20 phases.

```
/superpowers:subagent-driven-development docs/superpowers/plans/2026-05-09-nexus-ddd-bus-plan.md
```

**2. Inline execution** — execute phases in this session via `superpowers:executing-plans`.

```
/superpowers:executing-plans docs/superpowers/plans/2026-05-09-nexus-ddd-bus-plan.md
```

The plan has been validated against:
- The aggregate plan's structure (Phase 0 → Phase 20 mirrors aggregate's 0 → 16 with 4 extra phases for the broader bus surface).
- The umbrella spec v7 (latest revision in commit `37f6832f`).
- The shipped code in `packages/nexus-ddd-messaging/src/` (verified at planning time — `MessageMetadata` has no `headers()`, `CommandBus` has only `dispatchCommand(): void`).
- The shipped Psalm plugin structure in `packages/nexus-psalm/src/Hook/{Aggregate,Messaging}/` — new bus rules live in a parallel `Bus/` subdirectory.
- The shipped deptrac layer structure — new `DddBus` layer is added; allowed deps: `DddCore`, `DddMessaging`.

For follow-up packages (`nexus-ddd-async`, `nexus-ddd-actor`, `nexus-ddd-outbox`, `nexus-ddd-bus-idempotency-doctrine`), refer to the umbrella spec v7 §11 + §12 + §16 + §26 + this plan's "Out of scope" list.
