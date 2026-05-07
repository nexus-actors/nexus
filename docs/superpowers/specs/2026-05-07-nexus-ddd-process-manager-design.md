# nexus-ddd-process-manager — Design Spec

**Status:** Draft (post-brainstorm), awaiting user sign-off before plan-writing
**Date:** 2026-05-07
**Depends on:** `nexus-ddd-core`, `nexus-ddd-messaging` (in design)
**Inputs:** PixelFederation `Server.Shared.DddBundle` reference, four-expert design review (Mark Richards / Udi Dahan / Vaughn Vernon / Greg Young personas)

---

## 1. Goals & non-goals

### Goals

Provide the tactical primitives for **process managers** in the Nexus DDD framework — stateful coordinators that listen for `DomainEvent`s, dispatch `Command`s, publish `DomainEvent`s of their own, and schedule deadlines, with full event-sourcing replay correctness.

### Non-goals

- Bus implementations (live in `nexus-ddd-messaging` adapter packages)
- Outbox table schema and DB-backed staging (lives in downstream persistence package)
- Saga compensation primitives (compensation is just another `Command` dispatch)
- Runtime DSL configuration (deferred until a real use case forces it)
- Deadline scheduler runtime (timer/poller — separate adapter package; this package defines only the contract)

---

## 2. Package boundaries

```
                    nexus-ddd-core
                         ▲
                         │
         ┌───────────────┴───────────────┐
         │                               │
nexus-ddd-messaging                      │
         ▲                               │
         └───────────────┬───────────────┘
                         │
              nexus-ddd-process-manager
                         │
                ┌────────┴────────┐
                ▼                 ▼
     psr/event-dispatcher    monadial/php-duration
       (PSR-14 contract)     (FiniteDuration)
```

**Runtime dependencies:** `nexus-ddd-core`, `nexus-ddd-messaging`, `psr/event-dispatcher`, `monadial/php-duration`.

**Forbidden:** `symfony/event-dispatcher` (PSR-14 only — Symfony's dispatcher *implements* PSR-14 and is fine to wire externally), `doctrine/*`, persistence packages, actor-runtime packages, `nexus-ddd-aggregate`, `nexus-ddd-cqrs`, `nexus-ddd-eventsourcing`. Enforced by Deptrac layer `DddProcessManager` with explicit allow-list ruleset.

---

## 3. Core class hierarchy — mirror of aggregates

Two intent-revealing concrete bases, one abstract parent. Same shape as `AggregateRoot`/`StatefulAggregateRoot`/`EventSourcedAggregateRoot`.

```
AbstractProcessManager  (abstract; common API; lifecycle, deadlines, dispatch, publish, correlation)
   │
   ├── StatefulProcessManager           (state-stored; persisted as snapshot row; direct state mutation)
   │
   └── EventSourcedProcessManager       (event-sourced; persisted as event stream; recordThat + applyXxx)
        implements EventSourceable<TInternalEvent>
```

**Why split.** `StatefulProcessManager` and `EventSourcedProcessManager` differ in *how state changes are persisted*. Stateful PMs mutate state directly inside command handlers; event-sourced PMs route every state change through an internal event so that replay can reconstruct state from the event stream. The split mirrors `StatefulAggregateRoot` / `EventSourcedAggregateRoot` exactly — same teaching surface, same Deptrac-style discrimination via `instanceof EventSourceable`.

The user's primary intended persistence is **event-sourced with per-PM-type tables**. The stateful subclass exists for the same reason `StatefulAggregateRoot` does — some workflows are simple enough that ES is overkill; the snapshot-stored path is legitimate.

### `AbstractProcessManager` API surface

```php
/**
 * @template TId of ProcessManagerId
 */
abstract class AbstractProcessManager
{
    public function __construct(protected readonly ProcessManagerId $id) {}

    public function id(): ProcessManagerId;

    // Lifecycle — three terminal states
    public function isCompleted(): bool;
    public function isTerminated(): bool;
    public function terminationReason(): ?Reason;

    // Domain-message emission (staged; flushed post-commit).
    // Method names spell out the *kind* of message — at the call site the
    // reader sees `$this->dispatchCommand(...)` vs `$this->publishEvent(...)`
    // unambiguously, and the type system is doubly enforced.
    protected function dispatchCommand(Command $command): void;
    protected function publishEvent(DomainEvent $event): void;

    // Deadline API — name-based, no tokens
    protected function scheduleDeadline(DeadlineName $name, FiniteDuration $after): void;
    protected function rescheduleDeadline(DeadlineName $name, FiniteDuration $after): void;
    protected function cancelDeadline(DeadlineName $name): void;
    protected function hasDeadline(DeadlineName $name): bool;

    // Lifecycle transitions
    protected function complete(): void;
    protected function terminate(Reason $reason): void;

    // Correlation introspection (Udi)
    public function startedBy(): ?DomainEvent;                          // which event triggered this instance
    protected function correlateOn(string $field, mixed $value): void;  // secondary correlation index

    // Replay-mode awareness (Greg)
    final public function isReplaying(): bool;                          // set by runtime during load
}
```

### `StatefulProcessManager`

```php
/**
 * @template TId of ProcessManagerId
 * @extends AbstractProcessManager<TId>
 *
 * Snapshot-persisted PM. Each instance is one row; state is the serialized
 * properties + scheduled-deadline names + completion/termination flags.
 *
 * Use this when: workflow is simple, audit-trail of state changes is not
 * required, no need to replay decisions.
 */
abstract class StatefulProcessManager extends AbstractProcessManager
{
    // Inherits all of AbstractProcessManager.
    // No apply pattern. State mutation happens directly inside #[OnEvent]
    // handlers: `$this->paid = true;`.
    // pullStagedEvents() returns published DomainEvents only (PM-emitted),
    // never internal state-mutation events (there are none).
}
```

### `EventSourcedProcessManager`

```php
/**
 * @template TId of ProcessManagerId
 * @template TInternalEvent of DomainEvent
 * @extends AbstractProcessManager<TId>
 * @implements EventSourceable<TInternalEvent>
 *
 * Event-sourced PM. State is reconstructed by replaying internal events.
 * Each handler that mutates state must do so via recordThat() + applyXxx().
 *
 * Use this when: workflow has decision points worth auditing, you want
 * temporal queries ("what did this PM look like at T-3 days?"), or the
 * replay invariant is genuinely useful for testing.
 */
abstract class EventSourcedProcessManager extends AbstractProcessManager implements EventSourceable
{
    private static ?ApplyDispatcher $dispatcher = null;

    // Reuses core's ApplyDispatcher (closure-cache + #[AppliesTo] versioning).
    final protected function recordThat(DomainEvent $internalEvent): void;

    /** @param iterable<int, TInternalEvent> $events */
    final public function replay(iterable $events): void;

    public static function setDispatcher(?ApplyDispatcher $dispatcher): ?ApplyDispatcher;

    // Snapshot rehydration entry point — same shape as core's aggregate.
    final protected function rehydrateVersion(int $revision): void;
}
```

### `ProcessManagerInternals` interface (framework audience)

```php
/**
 * Framework-facing accessors. Same instance as the AbstractProcessManager,
 * cast to this interface by repository / unit-of-work code to drain
 * pending intent. Domain code reading the PM class sees only domain
 * operations (Vernon's segregation recipe).
 */
interface ProcessManagerInternals
{
    /** @return array<int, Command> */
    #[\NoDiscard(...)]
    public function pullPendingCommands(): array;

    /** @return array<int, DomainEvent> */
    #[\NoDiscard(...)]
    public function pullPendingEvents(): array;

    /** @return array<int, DeadlineOperation> */
    #[\NoDiscard(...)]
    public function pullPendingDeadlineOperations(): array;
}
```

`AbstractProcessManager` implements `ProcessManagerInternals`; the methods are technically public but conceptually scoped to framework consumers.

---

## 4. Deadline API — name-based

`DeadlineName` is a `final readonly class` value object backed by a string. The framework's deadline-scheduler (separate adapter package) reconstructs which deadlines are pending by replaying the PM's stream (for ES PMs) or reading the snapshot (for stateful PMs).

```php
final readonly class DeadlineName extends StringValue {
    public static function of(string $name): self { ... }
}

abstract readonly class DeadlineOperation {
    public function __construct(public DeadlineName $name) {}
}
final readonly class ScheduleDeadline extends DeadlineOperation {
    public function __construct(DeadlineName $name, public FiniteDuration $after) { parent::__construct($name); }
}
final readonly class RescheduleDeadline extends DeadlineOperation { ... }
final readonly class CancelDeadline extends DeadlineOperation { ... }
```

Handler dispatch via `#[OnDeadline(string $name)]` attribute — the string matches the `DeadlineName` value at the call site.

For event-sourced PMs, deadline operations are themselves recorded as internal events (`PmDeadlineScheduled`, `PmDeadlineCancelled`, `PmDeadlineFired`) so replay reconstructs deadline state. The `applyXxx` methods for these are provided by `EventSourcedProcessManager` itself — subclasses don't need to implement them.

---

## 5. Method-level handler attributes

```php
#[Attribute(Attribute::TARGET_METHOD, repeatable: true)]
final readonly class StartsOn {
    /** @param class-string<DomainEvent> $eventClass */
    public function __construct(public string $eventClass, public string $correlateBy) {}
}

#[Attribute(Attribute::TARGET_METHOD, repeatable: true)]
final readonly class OnEvent {
    /** @param class-string<DomainEvent> $eventClass */
    public function __construct(public string $eventClass, public string $correlateBy) {}
}

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class OnDeadline {
    public function __construct(public string $name) {}
}

#[Attribute(Attribute::TARGET_METHOD, repeatable: true)]
final readonly class WithRetry {
    /** @param class-string<BackoffStrategy> $strategy */
    public function __construct(
        public string $strategy = ExponentialBackoff::class,
        public int $maxAttempts = 3,
        public string $initialDelay = '1s',
        public string $maxDelay = '30s',
    ) {}
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ProcessManager {
    public function __construct(public bool $deleteOnComplete = true) {}
}
```

**Multiple `#[StartsOn]` allowed**: a single PM class may have multiple methods marked `#[StartsOn]`, each starting an instance from a different triggering event. Discipline:
- The framework enforces a unique constraint on `(pm_type, correlation_key)` at the persistence layer — first-write-wins under concurrent starts.
- The race-loser's event falls through to `#[OnEvent]` handling against the now-existing instance.
- Every `#[StartsOn]` handler must establish the PM's invariants (Vernon).
- Only the *first* event arriving for a given correlation key fires a start handler; subsequent events for the same correlation key are routed to `#[OnEvent]` handlers if any match, otherwise dead-lettered.

---

## 6. Configuration via attributes only

DSL alternative is **cut for v1** (Udi/Vaughn consensus, Mark scoped down). All configuration lives in PHP attributes. A future v2 may add a YAML/PHP-array compiler that produces the same `ProcessManagerDefinition` value object the attribute-compiler produces, with documented precedence and conflict-resolution rules — but only when a real use case forces it.

A boot-time `ProcessManagerDefinitionCompiler` reads attributes via reflection and builds an immutable `ProcessManagerDefinition` for each registered PM class. Reflection runs at boot; the compiled definition is what the runtime uses during message handling. No reflection on the hot path.

---

## 7. Lifecycle and replay semantics

### Three terminal states

| State | Set by | Meaning |
|-------|--------|---------|
| **Active** | (default) | PM is running, listening for events |
| **Completed** | `complete()` | Workflow reached its happy or expected ending |
| **Terminated** | `terminate(Reason)` | Domain decision to abandon (e.g., source aggregate deleted) |
| **Failed** | (framework) | Handler threw uncaught exception; PM is in error state |

The PM declares completion/termination; the framework declares failure (Vernon).

### Event arriving for a completed/terminated PM

If the PM class declares an `#[OnLateArrival]` method, the framework routes the event to it. **If no `#[OnLateArrival]` handler is declared, the event is dead-lettered** — pushed to the framework's structured DLQ with the original event payload, the correlation key, the resolved PM id, and a routing-decision marker.

Late-arrival events are NEVER silently dropped or logged-and-forgotten. The DLQ is the single off-ramp for messages the framework cannot route, and ops investigates from there. This is intentionally stricter than v1's earlier "log and drop" — silent drops produce production mysteries that survive postmortems.

### Event arriving for a non-existent PM

- If a matching `#[StartsOn]` exists on any registered PM class → start a new instance.
- Otherwise → dead-letter (do not silently drop, do not auto-create empty PMs).

The DLQ entry includes: original event, the correlation key the framework computed, the list of PM classes the framework checked, and the reason for non-routing ("no instance + no `#[StartsOn]`" vs "instance completed/terminated + no `#[OnLateArrival]`"). Ops must be able to answer "why didn't this event fire?" in under five minutes (Udi's on-call ask).

### Replay (event-sourced PMs)

Inputs: persisted internal event stream + optional snapshot.
Outputs: fully-rehydrated state object. **Nothing else.**

During replay (`isReplaying() === true`):
- `applyXxx` methods run (driven by core's `ApplyDispatcher`)
- `dispatch()` is a no-op
- `publish()` is a no-op
- `scheduleDeadline()` updates internal state (so `hasDeadline()` returns correct values) but does NOT enqueue physically with the deadline scheduler
- `complete()` / `terminate()` set the flags but do not emit lifecycle events (those are already in the stream)

**Internal event categories persisted in the stream** for ES PMs:
1. State-mutation events the PM owns (`PmPaymentRecorded`, `PmShipmentScheduled`, ...)
2. `PmDeadlineScheduled` / `PmDeadlineRescheduled` / `PmDeadlineCancelled` / `PmDeadlineFired` (deadline state changes)
3. `PmConsumedExternalEvent(externalEventId)` (idempotency tracking — replay reconstructs the dedup set)
4. `PmCompleted` / `PmTerminated(Reason)` (lifecycle transitions)

Categories 2–4 are framework-emitted; subclasses don't `recordThat` them directly. The framework wraps `scheduleDeadline()`, `complete()`, `terminate()` and emits the corresponding internal events.

---

## 8. Transaction boundaries — `MessageStaging` & `UnitOfWork`

Per the architect's synthesis: contracts in this package, in-memory implementation here, DB-backed (outbox) implementation in downstream package.

```php
interface MessageStaging
{
    public function appendCommand(Command $command): void;
    public function appendEvent(DomainEvent $event): void;
    public function appendDeadlineOperation(DeadlineOperation $op): void;
    public function flush(): void;     // post-commit — buses + scheduler invoked
    public function discard(): void;   // post-rollback
}

interface UnitOfWork
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function staging(): MessageStaging;
}
```

The PM doesn't know about transactions — it calls `dispatch()` / `publish()` / `scheduleDeadline()`. The runtime's repository wraps handler invocation in a `UnitOfWork.begin()` / `commit()` boundary, flushes staging on commit, discards on rollback.

**Default implementation in this package:** `InMemoryMessageStaging` + `InMemoryUnitOfWork`. Sufficient for tests and for users running a single-process Fiber runtime without DB-backed persistence.

**Downstream:** `OutboxMessageStaging` (writes commands/events/deadline-ops to an outbox table within the same DB transaction as PM state; a separate dispatcher polls the outbox post-commit) ships in `nexus-ddd-aggregate` or a dedicated `nexus-ddd-outbox` package.

---

## 9. Internal infrastructure events (PSR-14)

Framework-emitted events for observability/plugin hooks. **NOT** `DomainEvent`s — distinct namespace, distinct marker interface, distinct dispatcher.

```php
namespace Monadial\Nexus\Ddd\ProcessManager\Internal\Event;

interface ProcessManagerLifecycleEvent {}    // marker

final readonly class ProcessManagerStarted implements ProcessManagerLifecycleEvent {
    public function __construct(public ProcessManagerId $id, public string $pmClass, public DomainEvent $triggeredBy) {}
}
final readonly class ProcessManagerLoaded implements ProcessManagerLifecycleEvent { ... }
final readonly class ProcessManagerCompleted implements ProcessManagerLifecycleEvent { ... }
final readonly class ProcessManagerTerminated implements ProcessManagerLifecycleEvent { ... }
final readonly class TransactionStarted implements ProcessManagerLifecycleEvent { ... }
final readonly class TransactionCommitted implements ProcessManagerLifecycleEvent { ... }
final readonly class TransactionRolledBack implements ProcessManagerLifecycleEvent { ... }
final readonly class DeadlineScheduled implements ProcessManagerLifecycleEvent { ... }
final readonly class DeadlineFired implements ProcessManagerLifecycleEvent { ... }
final readonly class DeadlineCancelled implements ProcessManagerLifecycleEvent { ... }
final readonly class CommandsDispatched implements ProcessManagerLifecycleEvent { ... }
final readonly class EventsDispatched implements ProcessManagerLifecycleEvent { ... }
final readonly class HandlerInvocationStarted implements ProcessManagerLifecycleEvent { ... }
final readonly class HandlerInvocationFinished implements ProcessManagerLifecycleEvent { ... }
final readonly class HandlerInvocationFailed implements ProcessManagerLifecycleEvent { ... }
```

**Dispatcher: `Psr\EventDispatcher\EventDispatcherInterface`.** Constructor-injected on the runtime; defaults to `NullEventDispatcher` (PSR-14 no-op) when none is provided. Symfony's dispatcher implements PSR-14 and works as a drop-in.

**Listener constraints (Psalm rule):** events carry IDs and metadata only — never the PM instance itself. Listeners must NOT mutate PM state. To react with state change, dispatch a regular `Command` through the bus.

---

## 10. Worked example

```php
namespace App\Order\Process;

interface OrderFulfillmentEvent extends DomainEvent {}

// Internal events the PM owns (event-sourced state mutations)
final readonly class PmPaymentRecorded implements OrderFulfillmentEvent {
    public function __construct(public OrderId $orderId) {}
}
final readonly class PmShipmentDispatched implements OrderFulfillmentEvent {
    public function __construct(public OrderId $orderId, public ShipmentId $shipmentId) {}
}

#[ProcessManager(deleteOnComplete: false)]
/**
 * @extends EventSourcedProcessManager<OrderFulfillmentProcessId, OrderFulfillmentEvent>
 */
final class OrderFulfillmentProcess extends EventSourcedProcessManager
{
    public OrderId $orderId;
    public bool $paid = false;
    public ?ShipmentId $shipmentId = null;

    #[StartsOn(OrderPlaced::class, correlateBy: 'orderId')]
    public function onOrderPlaced(OrderPlaced $event, MessageContext $ctx): void
    {
        $this->orderId = $event->orderId;
        $this->scheduleDeadline(DeadlineName::of('payment-deadline'), FiniteDuration::ofHours(24));
    }

    #[OnEvent(PaymentReceived::class, correlateBy: 'orderId')]
    #[WithRetry(maxAttempts: 5, strategy: ExponentialBackoff::class)]
    public function onPaymentReceived(PaymentReceived $event, MessageContext $ctx): void
    {
        $this->recordThat(new PmPaymentRecorded($this->orderId));
        $this->dispatchCommand(new ShipOrder($this->orderId));
        $this->publishEvent(new OrderFulfillmentPaymentConfirmed($this->orderId));   // PM-emitted event for projections
    }

    #[OnEvent(OrderShipped::class, correlateBy: 'orderId')]
    public function onOrderShipped(OrderShipped $event, MessageContext $ctx): void
    {
        $this->recordThat(new PmShipmentDispatched($this->orderId, $event->shipmentId));
        $this->correlateOn('shipmentId', $event->shipmentId->value());
        $this->complete();
    }

    #[OnDeadline('payment-deadline')]
    public function onPaymentDeadline(MessageContext $ctx): void
    {
        if (!$this->paid) {
            $this->dispatchCommand(new CancelOrder($this->orderId));
            $this->terminate(Reason::of('payment-not-received-within-24h'));
        }
    }

    #[OnLateArrival]
    public function onLateEvent(DomainEvent $event, MessageContext $ctx): void
    {
        // Optional. If present, late-arrival events route here instead of
        // hitting the DLQ. Useful for workflows where late events are
        // expected (e.g., "PaymentReceived after timeout-cancellation —
        // refund the payment").
    }

    // State-mutation handlers (event-sourced replay invokes only these)
    private function applyPmPaymentRecorded(PmPaymentRecorded $e): void
    {
        $this->paid = true;
    }

    private function applyPmShipmentDispatched(PmShipmentDispatched $e): void
    {
        $this->shipmentId = $e->shipmentId;
    }
}
```

---

## 11. PSR-first dependency policy

This package uses **PSR contracts wherever a relevant PSR exists**, and never depends on framework-specific implementations:

| Concern | Contract used | Reason |
|---|---|---|
| Event dispatch (lifecycle events) | `Psr\EventDispatcher\EventDispatcherInterface` (PSR-14) | Symfony's dispatcher implements PSR-14; consumers wire whatever |
| Logger (handler diagnostics) | `Psr\Log\LoggerInterface` (PSR-3) | Already used across nexus packages |
| Clock (timestamps in `MessageMetadata`) | `Psr\Clock\ClockInterface` (PSR-20) | Test-injectable; production wires `\DateTimeImmutable`-backed |
| Container (handler resolution in bus impls — relevant downstream, not in this package directly) | `Psr\Container\ContainerInterface` (PSR-11) | Framework-agnostic |

**No `symfony/*`, `laravel/*`, or other framework-specific runtime deps in this package.** Implementations of the PSR contracts (Symfony's dispatcher, Monolog's logger, etc.) are wired by consumers at composition time. This rule is encoded in CLAUDE.md and enforced via Deptrac.

---

## 12. Fitness functions (CI-enforced)

**Deptrac layer `DddProcessManager`:**
- Allowed deps: `DddCore`, `DddMessaging`, `psr/event-dispatcher`, `monadial/php-duration`
- Forbidden: `symfony/*`, `doctrine/*`, persistence packages, actor-runtime packages, future P0 DDD packages

**Psalm rules** (in `nexus-psalm` plugin):
- `ProcessManagerStateRule` — PM property mutations only inside `applyXxx` (for ES PMs) or inside command-handler methods (for stateful PMs)
- `StartsOnUniqueRule` — within a single PM class, no two methods may carry `#[StartsOn(SameEvent::class)]`
- `HandlerSignatureRule` — methods with `#[StartsOn]` / `#[OnEvent]` / `#[OnDeadline]` have signature `(ConcreteEvent|nothing, MessageContext): void`
- `ProcessManagerInternalEventReadOnlyRule` — listeners on `ProcessManagerLifecycleEvent` must not call mutators on the event or the PM

**PHPUnit reflection / contract tests:**
- Drain semantics — `pullPending*` returns N then 0
- `discard()` after `dispatch()` → buses never see the staged messages
- ES PM with `applyXxx` for every recorded event class — fail if a `recordThat(X)` has no `applyX` method
- `isReplaying()` flag suppresses side effects

---

## 13. Open items & risks

### Open items resolved (user-confirmed 2026-05-07)

| # | Question | Resolution |
|---|---|---|
| 1 | In-memory `MessageStaging`/`UnitOfWork` ship here? | Yes — alongside `InMemoryDeadlineScheduler` |
| 2 | `ProcessManagerRepository<T>` contract here? | Yes — implementations in persistence packages |
| 3 | `#[OnLateArrival]` ships in v1? | Yes; default policy when no handler is declared = **dead-letter to DLQ** (NOT log+drop) |
| 4 | PSR-14 dispatcher (override of original Symfony request)? | Yes — full PSR-everywhere policy in §11 |
| 5 | Attributes-only configuration for v1? | Yes — DSL deferred until real use case forces it |
| 6 | Method names spell out kind: `dispatchCommand` / `publishEvent`? | Yes — clearer at the call site |
| 7 | Two-class hierarchy mirroring aggregates? | Yes |

### Risks for downstream packages

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| `MessageStaging` shape diverges between PM and aggregate | Med | High | Define in `nexus-ddd-messaging` (shared), not here; both packages depend on it |
| Static dispatcher in `EventSourcedProcessManager` shared across coroutines | Low | Med | Same `setDispatcher()` injection seam as `EventSourcedAggregateRoot` |
| `MessageContext` shape drift between messaging and PM | Low | Med | PM extras (PM id, lifecycle phase) carried as Stamps on the envelope, NOT as a richer context |
| Internal lifecycle events become an undocumented control plane | Med | Med | Psalm rule + readonly events + listener test |
| Per-PM-type table schema migration burden | Med | Low | Document; per-table is the user's chosen tradeoff |

---

## 14. Out of scope for v1

- Outbox table schema and DB-backed staging (downstream package)
- Saga compensation primitives (compensation = a `Command` dispatch; no separate `compensate()` API)
- Runtime DSL configuration
- DB-backed deadline scheduler runtime (timer adapter — separate package)
- `pm-inspect` CLI (Udi's on-call ask — useful but tooling, not contracts)
- Stuck-PM detection query (same — tooling)
- Process-manager-as-actor adapter (separate package wiring PMs as actors in the actor framework)

---

## 15. Sign-off

All seven items in §13 confirmed by user 2026-05-07.

Next step: re-run the four-expert review board against this updated spec. After board approval → invoke `superpowers:writing-plans` to produce the implementation plan.
