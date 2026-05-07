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

    // Correlation introspection
    public function startedBy(): ?DomainEvent;                          // which event triggered this instance
    protected function correlateOn(string $field, mixed $value): void;  // add secondary correlation key
    protected function removeCorrelation(string $field): void;          // forget a temporary secondary key

    // Replay-mode awareness
    final public function isReplaying(): bool;                          // set by runtime during load
}
```

**`correlateOn()` semantics.** Call exactly **once per secondary key**, at the moment the PM first observes the secondary id (typically inside an `#[OnEvent]` handler that introduces a new identifier — e.g., `$event->shipmentId` after the order is shipped). Idempotent re-registration with the *same value* is a no-op; calling it with a *different value* for an existing field throws `CorrelationConflictException`. The call is **state-mutating** and is recorded as an internal event (`PmCorrelationAdded(field, value)`) for ES PMs so replay rebuilds the secondary index correctly. For stateful PMs the field is part of the snapshot.

**`removeCorrelation()`** is the inverse — used when a PM "forgets" a temporary key (e.g., a quote-id once the quote becomes an order). Recorded as `PmCorrelationRemoved(field)`. Without removal the secondary index grows unboundedly per PM and stale keys redirect future events to wrong instances.

### `Reason` value object

`terminate()` and `terminationReason()` traffic in a typed `Reason`, not a free string:

```php
final readonly class Reason {
    public static function of(string $code, ?string $detail = null): self;
    public function code(): string;
    public function detail(): ?string;
}
```

`code` is a stable machine-readable identifier (`'payment-not-received-within-24h'`); `detail` is optional human-friendly context. Reasons go into the persisted `PmTerminated(Reason)` event, so changing `code` values for already-terminated PMs is a schema migration.

**`code` vs `detail` recipe.** Include `detail` only when dynamic context cannot be encoded into the `code` itself. `Reason::of('payment-not-received-within-24h')` — code is fully self-describing, no detail needed. `Reason::of('shipping-failed', detail: $event->reason)` — the upstream system supplied a string the PM could not have known statically; detail captures it. Default to no-detail; reach for detail only when the runtime context adds genuine information ops or auditors will need.

### `ProcessManagerEventStore` — stream contract

For ES PMs, persistence adapters must satisfy this contract. Per-PM-type physical tables are the user's chosen layout; the *contract* below is what every adapter implements regardless:

```php
/**
 * @template TInternalEvent of DomainEvent
 */
interface ProcessManagerEventStore {
    /**
     * Append events to a PM's stream with optimistic concurrency.
     * @param iterable<int, TInternalEvent> $events
     * @throws OptimisticLockException when expected version mismatches.
     */
    public function append(
        ProcessManagerId $streamId,
        int $expectedVersion,
        iterable $events,
        WriterId $writerId,
    ): void;

    /**
     * Load the full stream (or from-snapshot+delta when a snapshot store is present).
     * @return iterable<int, TInternalEvent>
     */
    public function load(ProcessManagerId $streamId): iterable;

    public function streamExists(ProcessManagerId $streamId): bool;
}
```

**Stream invariants** (every adapter must guarantee):
- Stream id = `ProcessManagerId` value
- Sequence numbers are per-stream, monotonically increasing from 1
- Each row is exactly one `DomainEvent` (internal to the PM)
- Writer-id stamping (`WriterId` from core's single-writer principle) on every row
- Optimistic concurrency on `(stream_id, expected_version)` at append

### `ProcessManagerSnapshotPayload`

For ES PMs that ship snapshots, the snapshot payload MUST round-trip the full PM state — not just the user-defined fields, but everything replay would have rebuilt. A snapshot loaded at version 42 plus events 43..N MUST produce identical state to a full replay from event 1.

```php
final readonly class ProcessManagerSnapshotPayload {
    public function __construct(
        public ProcessManagerId $id,
        public string $pmClass,
        public int $version,                                          // last applied event's seq
        public array $userState,                                      // serialized public properties
        public bool $isCompleted,
        public bool $isTerminated,
        public ?Reason $terminationReason,
        public ?DomainEvent $startedBy,                               // first event reference
        /** @var array<string, true> */ public array $consumedEventIds, // dedup set rebuilt on replay
        /** @var array<string, mixed> */ public array $correlations,    // secondary correlation index
        /** @var array<int, ScheduledDeadlineSnapshot> */
        public array $scheduledDeadlines,                             // name + fireAt for each
    ) {}
}

final readonly class ScheduledDeadlineSnapshot {
    public function __construct(
        public DeadlineName $name,
        public DateTimeImmutable $fireAt,
    ) {}
}
```

A snapshot store implementation reads/writes this payload; the adapter is free to serialize it however (JSON, MessagePack, native PHP) but the *shape* is invariant across adapters so two implementations can interoperate.

**Recommended physical schema** (informative, not normative):

```sql
CREATE TABLE order_fulfillment_process_events (
    stream_id    UUID    NOT NULL,
    sequence_no  INT     NOT NULL,
    event_type   TEXT    NOT NULL,
    payload      JSONB   NOT NULL,
    occurred_at  TIMESTAMPTZ NOT NULL,
    writer_id    UUID    NOT NULL,
    PRIMARY KEY (stream_id, sequence_no)
);
CREATE INDEX ON order_fulfillment_process_events (stream_id, occurred_at);
```

Persistence adapters MAY choose a different shape (e.g., one-events-table-per-app vs per-PM-type) but the contract above is what `EventSourcedProcessManager.replay()` and the runtime call against.

### `ProcessManagerInspector` — observability contract

A v1 contract for ops/tooling. Implementation defers to a future tooling package, but the contract ships now so persistence adapters design schemas with inspection in mind:

```php
interface ProcessManagerInspector {
    public function findById(ProcessManagerId $id): ?ProcessManagerSnapshot;

    /**
     * PMs that are alive but have made no progress (no recorded internal
     * events) for at least $idleFor, and have no pending deadlines.
     * @return iterable<int, ProcessManagerSnapshot>
     */
    public function findStuck(FiniteDuration $idleFor): iterable;

    /**
     * Lookup by primary or secondary correlation key.
     * @return iterable<int, ProcessManagerSnapshot>
     */
    public function findByCorrelation(string $field, mixed $value): iterable;
}

final readonly class ProcessManagerSnapshot {
    public function __construct(
        public ProcessManagerId $id,
        public string $pmClass,
        public bool $isCompleted,
        public bool $isTerminated,
        public ?Reason $terminationReason,
        public int $version,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $lastEventAt,
        /** @var array<int, DeadlineName> */
        public array $pendingDeadlines,
        /** @var array<string, mixed> */
        public array $correlations,
    ) {}
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

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class OnLateArrival {
    // Handler signature: (ConcreteEventClass|DomainEvent $event, MessageContext $ctx): void
    // The framework dispatches by reflection on the parameter type — pass a typed
    // event class to handle a specific late event, or DomainEvent for a catch-all.
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class LateArrivalPolicy {
    public function __construct(public Policy $policy = Policy::DeadLetter) {}
}

enum Policy {
    case DeadLetter;   // route to DLQ (default — strictest)
    case LogAndDrop;   // structured log + metric, no DLQ entry
    case Reject;       // throw RejectedMessageException — upstream bus decides retry/drop
}
```

### Multiple `#[StartsOn]` rules (MUST)

A single PM class may have multiple methods marked `#[StartsOn]`, each starting an instance from a different triggering event. The runtime invariants are MUST-strength:

- The framework MUST enforce a unique constraint on `(pm_type, correlation_key)` at the persistence layer — first-write-wins under concurrent starts.
- The race-loser's event MUST fall through to `#[OnEvent]` handling against the now-existing instance.
- If the race-loser's event has no matching `#[OnEvent]` handler on the now-existing instance, it MUST be dead-lettered with reason `"race-loser-no-on-event-match"`.
- Every `#[StartsOn]` handler MUST establish the PM's invariants — i.e., set whatever fields downstream `#[OnEvent]` handlers depend on.
- Only the *first* event arriving for a given correlation key fires a start handler. Subsequent matches of `#[StartsOn]` for the same correlation key MUST be treated as `#[OnEvent]` if a matching `#[OnEvent]` exists; otherwise dead-lettered.

### `#[OnLateArrival]` discipline rules (DOCUMENTED + Psalm-enforced where possible)

- A `#[OnLateArrival]` handler MUST NOT call `recordThat()` (the PM is already terminal — its event stream is closed). Enforced by Psalm rule `OnLateArrivalSemanticsRule`.
- A `#[OnLateArrival]` handler MUST NOT call `complete()` / `terminate()` (state already terminal). Enforced by the same Psalm rule.
- A `#[OnLateArrival]` handler SHOULD only emit *compensating* commands (refund, notify, audit-log). It MUST NOT dispatch commands that create new domain state — that is a `#[StartsOn]` on a different PM, not a late-arrival follow-up.
- The handler signature accepts either a typed event class (preferred) OR `DomainEvent` (catch-all). The catch-all is intended for forwarding to a DLQ-enrichment listener; do not let it become a junk drawer for "events I haven't modeled yet."
- Class-level `#[LateArrivalPolicy(Policy)]` defaults to `DeadLetter`. Method-level `#[OnLateArrival]` overrides the class policy when present (handler runs instead of policy).

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

Routing decision (in order):

1. If the PM class declares a typed `#[OnLateArrival(...)]` method matching the event class → invoke it.
2. Else if the PM class declares a catch-all `#[OnLateArrival]` (parameter type `DomainEvent`) → invoke it.
3. Else fall back to the class-level `#[LateArrivalPolicy(Policy)]` (default `DeadLetter`):
   - `DeadLetter` → route to DLQ with full routing trace
   - `LogAndDrop` → structured log entry + metric, no DLQ
   - `Reject` → throw `RejectedMessageException`; upstream bus decides retry semantics

`DeadLetter` is the default because silent drops produce production mysteries that survive postmortems. Domains that have *expected* late-arrival noise (e.g., `PaymentRefunded` after subscription cancellation) opt into `LogAndDrop` consciously — the Psalm rule reminds the team that this is a deliberate choice, not the path of least resistance.

### Event arriving for a non-existent PM

- If a matching `#[StartsOn]` exists on any registered PM class → start a new instance (subject to the multi-StartsOn race rules above).
- Otherwise → dead-letter (do not silently drop, do not auto-create empty PMs).

### DLQ entry shape

Every DLQ entry MUST include:

| Field | Purpose |
|---|---|
| `originalEvent` | The full event payload — needed for replay-from-DLQ |
| `originalEventId` | Stable id (from `MessageMetadata`) — needed for idempotency on DLQ-replay |
| `correlationKey` | Value the framework computed from `correlateBy` — answers "what did the framework think this was about?" |
| `attemptedAt` | Timestamp when routing was attempted (NOT the event's `occurredAt`) |
| `routingTrace` | List of `{pmClass, declaration, rejectionReason}` — every `#[StartsOn]` and `#[OnEvent]` declaration the framework considered, and why each was rejected |
| `nonRoutingReason` | Single canonical code: `no-instance-no-startson`, `completed-no-onlatearrival`, `terminated-no-onlatearrival`, `race-loser-no-on-event-match` |
| `pmClass` | The PM class the framework would have routed to, if it had matched |
| `correlationField` | The field name from the `correlateBy` declaration |

The trace + canonical reason code MUST allow ops to answer "why didn't this event fire?" in under five minutes by looking at the DLQ entry alone (Udi's on-call ask).

### Replay (event-sourced PMs)

Inputs: persisted internal event stream + optional snapshot.
Outputs: fully-rehydrated state object. **Nothing else.**

During replay (`isReplaying() === true`) the PM behaves as a **pure state-rebuilder** — it touches its own in-memory state and *only* its own in-memory state:

- `applyXxx` methods run (driven by core's `ApplyDispatcher`)
- `dispatchCommand()` and `publishEvent()` are no-ops
- `scheduleDeadline()` / `rescheduleDeadline()` / `cancelDeadline()` update the in-memory scheduled-deadline set (so `hasDeadline()` returns correct values), do NOT enqueue with the physical scheduler, AND do NOT emit `PmDeadlineScheduled`/`PmDeadlineCancelled` events (those events are already in the stream being replayed; re-emitting would corrupt it)
- `correlateOn()` / `removeCorrelation()` update the in-memory secondary index, do NOT publish to any external registry, AND do NOT emit `PmCorrelationAdded`/`PmCorrelationRemoved` events
- `complete()` / `terminate()` set the flags, do NOT emit `PmCompleted`/`PmTerminated` events
- `#[OnEvent]` / `#[StartsOn]` / `#[OnLateArrival]` handlers do NOT run — only `applyXxx`

The rule is: during replay, every method that would emit an internal event during live execution emits NOTHING. The events are already on disk and being walked. Live-mode wrappers around `correlateOn`, `scheduleDeadline`, `complete`, etc. check `isReplaying()` and bypass the emit step.

**Internal event categories persisted in the stream** for ES PMs:

| # | Event family | Owner | Recorded by |
|---|---|---|---|
| 1 | State-mutation events (`PmPaymentRecorded`, `PmShipmentDispatched`, ...) | Subclass | `recordThat()` from inside `#[OnEvent]`/`#[StartsOn]` handlers |
| 2 | `PmDeadlineScheduled` / `PmDeadlineRescheduled` / `PmDeadlineCancelled` / `PmDeadlineFired` | Framework | Framework wraps `scheduleDeadline()` / `rescheduleDeadline()` / `cancelDeadline()` and emits these |
| 3 | `PmCorrelationAdded(field, value)` / `PmCorrelationRemoved(field)` | Framework | Framework wraps `correlateOn()` / `removeCorrelation()` and emits these |
| 4 | `PmConsumedExternalEvent(externalEventId)` | Framework | Recorded after a successful handler dispatch — used for both live and replay dedup |
| 5 | `PmStarted(triggeringEventId, startMethodName)` | Framework | Recorded as the FIRST event in the stream when a `#[StartsOn]` fires |
| 6 | `PmCompleted` / `PmTerminated(Reason)` | Framework | Framework wraps `complete()` / `terminate()` and emits these as the LAST event |

Categories 2–6 are framework-emitted under the `Monadial\Nexus\Ddd\ProcessManager\Internal\Event` namespace. Subclasses MUST NOT `recordThat` events from that namespace directly — enforced by `PmInternalEventNamespaceRule` Psalm rule. The framework supplies `applyXxx` for all category 2–6 events on `EventSourcedProcessManager`.

### Per-instance serialization (MUST)

Each PM instance MUST process at most one external event at a time. The actor-adapter package supplies this serialization via mailbox-per-instance (one inbox per `ProcessManagerId`). Without single-event-at-a-time per instance, the deterministic `MessageId(pmId, sequenceNo)` contract for outbound commands is unenforceable, and concurrent state mutations would race.

This is a runtime guarantee, not a code-level constraint — the framework cannot enforce it via Psalm. Adapters that do not provide per-instance serialization (e.g., a hand-rolled bus that delivers in parallel) violate the contract; teams using them are on their own for outbound dedup.

### Gate ordering (MUST)

When an external event arrives for an existing PM correlation key, the framework checks gates in this order:

1. **Live-redelivery dedup gate** — is `event.id` in the consumed set rebuilt during PM load? If yes, ack and stop.
2. **Multi-`#[StartsOn]` race resolution** — only checked for genuinely new event ids. A redelivered starting event hits the dedup gate above and is absorbed without re-running race resolution; this is correct because the original delivery already won the race.
3. **Late-arrival routing** — only for completed/terminated PMs.
4. **Handler dispatch** — `#[StartsOn]` / `#[OnEvent]` / `#[OnLateArrival]`.

The dedup gate firing first is the property that makes the deterministic `MessageId(pmId, sequenceNo)` contract self-consistent across redeliveries — the same external event id never produces a second handler run, so the same command sequence number is never re-issued.

### Crash semantics

If the transaction commit fails after the handler ran (DB error after the bus already published, or after the `PmConsumedExternalEvent` row was prepared but before the COMMIT statement returned):

- The rolled-back `PmConsumedExternalEvent` is NOT persisted.
- The external broker never gets `ack`'d (because `staging.flush()` is post-commit and ack is post-flush).
- Redelivery causes the handler to re-run.
- The redelivery's commands carry the **same** `MessageId(pmId, sequenceNo)` as the failed run's commands (because `sequenceNo` is determined by the next-available stream slot, which the failed commit didn't advance).
- The downstream command bus dedups on `MessageId` — duplicate effects from the failed run are absorbed.

The deterministic command-id is what protects downstream from duplicate effects in the crash-before-ack case. Without it, a crash between handler-run and commit produces silent duplicates downstream.

### Idempotency: live in-flight redelivery vs replay

These are **two distinct dedup paths** with the same dedup-set source-of-truth.

**Live in-flight redelivery** (same external event arrives twice while PM is alive in memory or after recent eviction):

```
external event arrives
  ↓
runtime computes correlation key
  ↓
runtime loads PM (replay rebuilds in-memory dedup set from PmConsumedExternalEvent stream)
  ↓
runtime checks: is event.id in dedup set?
  ↓ YES                                ↓ NO
ack the message, no handler invoked.   begin transaction
                                       ↓
                                       invoke handler
                                       ↓
                                       handler emits commands/events/deadlines via staging
                                       ↓
                                       framework appends PmConsumedExternalEvent(event.id) to stream
                                       ↓
                                       commit transaction (PM events + outbox rows in same TX)
                                       ↓
                                       staging.flush() → buses dispatch
                                       ↓
                                       ack the message
```

The dedup check fires **after PM load** but **before handler dispatch**. This is the only correct ordering: you need the dedup set to be reconstructed, and you need the check to fire before any side effect. In practice, hot PMs stay loaded across events (cache), so the load is cheap.

**Replay** (PM being rehydrated from stream during load):

```
runtime reads stream
  ↓
for each persisted event:
  - applyXxx runs (state mutation)
  - if PmConsumedExternalEvent(id) → add id to in-memory dedup set
  - all side-effect methods (dispatch, publish, schedule physical) are no-ops
  ↓
PM is loaded with full dedup set
```

Replay does NOT re-trigger handler logic and does NOT re-record `PmConsumedExternalEvent`. It just rebuilds the dedup set as a state projection.

**DLQ-replay**: when ops re-injects an event from the DLQ, the runtime treats it as a new external delivery — the same dedup gate fires. If the original event was never processed (the reason it's in the DLQ), the dedup set won't contain its id, and the handler runs normally. If ops accidentally re-injects the same DLQ entry twice, the second injection dedups correctly. The DLQ-replay path MUST be the same code path as live ingestion to preserve this property.

### Outbound command idempotency

A PM dispatches a command. The transaction commits. The bus publishes. Ops retries; PM reloads (live, not replay); handler fires again on a redelivered external event. Will the same command be dispatched twice?

**Inbound dedup prevents the handler from running twice for the same external event** (above). So in normal flow, the same command is not re-emitted.

**Failure mode**: the handler dispatched the command, but the transaction commit failed (DB error AFTER the bus already published). The external event is unacked, redelivered, the handler runs again — but the dedup set wasn't updated (transaction rolled back), so the handler dispatches the command **a second time**.

To make this safe, commands emitted by a PM carry a deterministic `MessageId` computed as:

```
MessageId = hash(pmId, baseStreamSeq, withinStagingOrdinal)
```

where:
- `pmId` — the PM instance's identifier
- `baseStreamSeq` — the next-available sequence number in the PM's stream at the moment the surrounding handler began (the seq where the next persisted event would land if commit succeeds)
- `withinStagingOrdinal` — 0-indexed position of this command within the staging buffer for the current handler invocation. The first `dispatchCommand()` call gets ordinal 0, the second gets 1, etc. Reset per handler invocation.

This composite is stable across crash-replay because:
- The same external event redelivered hits the dedup gate (above) — the handler does not re-run.
- For the crash-before-commit case where the handler does re-run: the `baseStreamSeq` is the same (the failed commit didn't advance it), and the ordinals within the handler are reproducible (the handler is deterministic given its input event), so commands get the same composite id.

The runtime stamps this id on the command's `MessageMetadata` at staging time. Downstream command handlers MUST dedup on `MessageId` (the messaging layer's idempotency contract).

`publishEvent()` follows the same pattern — events get a deterministic id from the same composite. Downstream subscribers dedup on `MessageId` if they care about exactly-once.

The per-instance serialization MUST (above) is what makes `withinStagingOrdinal` meaningful — without it, two parallel handlers staging commands would race, and the same logical command could land at different ordinals across redeliveries.

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

**Shared contract test class.** This package ships an abstract `MessageStagingContractTest` that both `InMemoryMessageStaging` and downstream `OutboxMessageStaging` MUST pass. The shared test pins the discard semantics (`discard()` after `appendCommand()` → buses never see the command), the flush semantics (`flush()` invokes the bus exactly once per appended message), and ordering invariants (FIFO within a single staging cycle). Without this, two implementations drift and the "drop on rollback" guarantee becomes implementation-dependent.

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
final readonly class PmOrderRegistered implements OrderFulfillmentEvent {
    public function __construct(public OrderId $orderId) {}
}
final readonly class PmPaymentRecorded implements OrderFulfillmentEvent {
    public function __construct(public OrderId $orderId) {}
}
final readonly class PmShipmentDispatched implements OrderFulfillmentEvent {
    public function __construct(public OrderId $orderId, public ShipmentId $shipmentId) {}
}
final readonly class PmShippingFailed implements OrderFulfillmentEvent {
    public function __construct(public OrderId $orderId, public string $failureReason) {}
}

#[ProcessManager(deleteOnComplete: false)]
#[LateArrivalPolicy(Policy::DeadLetter)]    // explicit; matches default
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
        // Establish PM invariants — every #[StartsOn] handler MUST do this.
        $this->recordThat(new PmOrderRegistered($event->orderId));
        $this->scheduleDeadline(DeadlineName::of('payment-deadline'), FiniteDuration::ofHours(24));
    }

    #[OnEvent(PaymentReceived::class, correlateBy: 'orderId')]
    #[WithRetry(maxAttempts: 5, strategy: ExponentialBackoff::class)]
    public function onPaymentReceived(PaymentReceived $event, MessageContext $ctx): void
    {
        $this->recordThat(new PmPaymentRecorded($this->orderId));
        $this->cancelDeadline(DeadlineName::of('payment-deadline'));    // recorded as PmDeadlineCancelled
        $this->dispatchCommand(new ShipOrder($this->orderId));
        $this->publishEvent(new OrderFulfillmentPaymentConfirmed($this->orderId));
    }

    #[OnEvent(OrderShipped::class, correlateBy: 'orderId')]
    public function onOrderShipped(OrderShipped $event, MessageContext $ctx): void
    {
        // recordThat first so the secondary correlation is event-sourced via
        // applyPmShipmentDispatched — replay-safe.
        $this->recordThat(new PmShipmentDispatched($this->orderId, $event->shipmentId));
        $this->complete();
    }

    /** Failure-path branch — what happens when shipping breaks. */
    #[OnEvent(OrderShippingFailed::class, correlateBy: 'orderId')]
    public function onOrderShippingFailed(OrderShippingFailed $event, MessageContext $ctx): void
    {
        $this->recordThat(new PmShippingFailed($this->orderId, $event->reason));
        $this->dispatchCommand(new RefundPayment($this->orderId));
        $this->terminate(Reason::of('shipping-failed', detail: $event->reason));
    }

    #[OnDeadline('payment-deadline')]
    public function onPaymentDeadline(MessageContext $ctx): void
    {
        if (!$this->paid) {
            $this->dispatchCommand(new CancelOrder($this->orderId));
            $this->terminate(Reason::of('payment-not-received-within-24h'));
        }
    }

    /**
     * Optional: late-arrival handler. Without this method, late events
     * follow the class-level #[LateArrivalPolicy] (DeadLetter). Defining
     * a typed handler lets the PM react to specific late events (e.g.,
     * refund a payment that arrived after timeout-cancellation).
     */
    #[OnLateArrival]
    public function onLatePaymentReceived(PaymentReceived $event, MessageContext $ctx): void
    {
        // Compensating side effect — the PM is terminal, so we cannot
        // recordThat() or terminate() here; we can only emit compensating
        // commands.
        $this->dispatchCommand(new RefundPayment($event->orderId));
    }


    private function applyPmOrderRegistered(PmOrderRegistered $e): void
    {
        $this->orderId = $e->orderId;
    }

    private function applyPmPaymentRecorded(PmPaymentRecorded $e): void
    {
        $this->paid = true;
    }

    private function applyPmShipmentDispatched(PmShipmentDispatched $e): void
    {
        $this->shipmentId = $e->shipmentId;
        // Replay-safe secondary correlation: emitted as a state-mutation
        // event so replay rebuilds the index.
        $this->correlateOn('shipmentId', $e->shipmentId->value());
    }

    private function applyPmShippingFailed(PmShippingFailed $e): void
    {
        // No state field here — the failure reason is captured in the
        // termination Reason. The applyXxx exists so replay walks the event.
    }
}
```

### The complete persisted stream (happy path)

For an `OrderFulfillmentProcess` instance that goes through payment → shipment → completion, the stream contains:

```
seq | event class                                       | emitted by
----+---------------------------------------------------+--------------------------
  1 | PmStarted(orderPlacedEventId, 'onOrderPlaced')    | framework (start handler)
  2 | PmOrderRegistered(orderId)                        | onOrderPlaced (recordThat)
  3 | PmDeadlineScheduled('payment-deadline', 24h)      | framework (scheduleDeadline)
  4 | PmConsumedExternalEvent(orderPlacedEventId)       | framework (post-handler)
  5 | PmPaymentRecorded(orderId)                        | onPaymentReceived (recordThat)
  6 | PmDeadlineCancelled('payment-deadline')           | framework (cancelDeadline)
  7 | PmConsumedExternalEvent(paymentReceivedEventId)   | framework (post-handler)
  8 | PmShipmentDispatched(orderId, shipmentId)         | onOrderShipped (recordThat)
  9 | PmCorrelationAdded('shipmentId', '01HK...')       | framework (correlateOn from applyXxx)
 10 | PmConsumedExternalEvent(orderShippedEventId)      | framework (post-handler)
 11 | PmCompleted                                       | framework (complete)
```

Notice three things:
- The stream contains **all** persisted events including framework-emitted ones, not just the subclass's `recordThat`s. A developer who reads "I only persist what I `recordThat`" has the wrong mental model.
- `correlateOn('shipmentId', ...)` is invoked from inside `applyPmShipmentDispatched`, NOT from `onOrderShipped`. This is the replay-safe pattern: secondary correlation must happen during state mutation so it re-runs on replay.
- `PmConsumedExternalEvent` is appended after every successful handler dispatch and is the source of truth for the live-redelivery dedup check (§7).

### Replay trace

Loading this PM from its stream:

```
runtime: isReplaying = true
  apply(PmStarted)              → set startedBy = OrderPlaced(eventId=...)
  apply(PmOrderRegistered)      → orderId = ...
  apply(PmDeadlineScheduled)    → record 'payment-deadline' as scheduled internally
  apply(PmConsumedExternalEvent(orderPlacedEventId))  → add to dedup set
  apply(PmPaymentRecorded)      → paid = true
  apply(PmDeadlineCancelled)    → remove 'payment-deadline' from internal scheduled set
  apply(PmConsumedExternalEvent(paymentReceivedEventId))  → add to dedup set
  apply(PmShipmentDispatched)   → shipmentId = ...; correlateOn('shipmentId', ...) updates in-memory index
  apply(PmCorrelationAdded)     → idempotent re-confirmation of the index entry
  apply(PmConsumedExternalEvent(orderShippedEventId))  → add to dedup set
  apply(PmCompleted)            → isCompleted = true
runtime: isReplaying = false; PM is loaded
```

`#[OnEvent]` / `#[StartsOn]` handlers do not run during replay. `dispatchCommand` / `publishEvent` are no-ops if accidentally called from `applyXxx` (which is itself a Psalm violation — the `ProcessManagerStateRule` catches it).

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

These are testable architectural assertions. Wire them now, before code exists, so violations are impossible rather than discouraged.

**Deptrac layers (added to `deptrac.yaml`):**

```yaml
- name: DddProcessManager
  collectors:
    - type: directory
      value: packages/nexus-ddd-process-manager/src/.*

- name: PmInternalEventNamespace
  collectors:
    - type: classLike
      regex: ^Monadial\\Nexus\\Ddd\\ProcessManager\\Internal\\Event\\.*$

ruleset:
  DddProcessManager:
    - DddCore
    - DddMessaging
    # PSR & Duration deps allowed via vendor whitelist below

forbidden_imports:
  DddProcessManager:
    # Build fails if any of these vendor namespaces are imported.
    - regex: ^Symfony\\.*
    - regex: ^Laravel\\.*
    - regex: ^Illuminate\\.*
    - regex: ^Monolog\\.*
    - regex: ^Doctrine\\.*
```

The forbidden-imports rule promotes the PSR-everywhere policy from CLAUDE.md docblock to a build-failing CI gate.

**Psalm rules** (in `nexus-psalm` plugin):

| Rule | Enforces |
|---|---|
| `ProcessManagerStateRule` | PM property mutations only inside `applyXxx` (for ES PMs) or inside `#[StartsOn]`/`#[OnEvent]`/`#[OnDeadline]` handlers (for stateful PMs) |
| `StartsOnUniqueRule` | Within a single PM class, no two methods may carry `#[StartsOn(SameEvent::class)]` |
| `HandlerSignatureRule` | Methods with `#[StartsOn]` / `#[OnEvent]` / `#[OnDeadline]` / `#[OnLateArrival]` have signature `(ConcreteEvent\|DomainEvent\|nothing, MessageContext): void` |
| `ProcessManagerInternalEventReadOnlyRule` | Listeners on `ProcessManagerLifecycleEvent` must not call mutators on the event or the PM |
| `PmInternalEventNamespaceRule` | Subclass `recordThat()` calls MUST NOT pass an event from `Monadial\Nexus\Ddd\ProcessManager\Internal\Event\` — that namespace is framework-only (`PmDeadlineScheduled`, `PmConsumedExternalEvent`, `PmCompleted`, etc.) |
| `OnLateArrivalSemanticsRule` | `#[OnLateArrival]` handlers MUST NOT call `recordThat()`, `complete()`, or `terminate()` (the PM is already terminal); MAY call `dispatchCommand()` for compensating effects |

**PHPUnit reflection / contract tests:**

- Drain semantics — `pullPending*` returns N then 0
- `discard()` after `dispatchCommand()`/`publishEvent()` → buses never see the staged messages
- `flush()` after `dispatchCommand()` → bus invoked exactly once per command
- FIFO ordering preserved across staging cycles
- ES PM with `applyXxx` for every recorded event class — fail if a `recordThat(X)` has no `applyX` method
- `isReplaying()` flag suppresses side effects (call `dispatchCommand()` during replay → bus NOT invoked)
- Replay reconstructs full state including secondary correlations and dedup set
- Live in-flight redelivery of the same external event id → handler invoked exactly once

**Shared `MessageStagingContractTest`** — abstract test class that both `InMemoryMessageStaging` and downstream `OutboxMessageStaging` MUST pass. Pins discard/flush/FIFO/idempotency invariants so implementations cannot drift.

**Mutation testing (Infection):** target 90% MSI on `AbstractProcessManager`, `EventSourcedProcessManager`, `StatefulProcessManager`, `MessageStaging` implementations, and the `ProcessManagerDefinitionCompiler`. Attribute classes are essentially data — exclude or accept lower MSI.

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
| `MessageStaging` shape diverges between PM and aggregate | Med | High | **Plan-time decision**: define in `nexus-ddd-messaging` (shared), not here; both packages depend on it. Tracked as the first decision the writing-plans skill must resolve. |
| Static dispatcher in `EventSourcedProcessManager` shared across coroutines | Low | Med | Same `setDispatcher()` injection seam as `EventSourcedAggregateRoot` |
| `MessageContext` shape drift between messaging and PM | Low | Med | PM extras (PM id, lifecycle phase) carried as Stamps on the envelope, NOT as a richer context |
| Internal lifecycle events become an undocumented control plane | Med | Med | Psalm rule (`ProcessManagerInternalEventReadOnlyRule`) + readonly events + listener test |
| Per-PM-type table schema migration burden | Med | Low | Document; per-table is the user's chosen tradeoff |
| `#[OnLateArrival]` becomes a junk drawer | Med | Med | `OnLateArrivalSemanticsRule` Psalm rule; explicit discipline in §5; typed event params discouraged-but-allowed for catch-all |
| Outbound command duplicate after crash-before-ack | Med | High | Deterministic `MessageId` from `(pmId, sequenceNo)`; downstream handlers dedup |

---

## 14. v1 deliverables — not just code

Beyond the code, v1 ships:

- **Spec doc** (this file) committed to `docs/superpowers/specs/`
- **"Writing Process Managers in an Async World" guide** at `docs/superpowers/guides/process-managers-async-discipline.md` — separate document, NOT a section bolted onto this spec. Covers at-least-once delivery, idempotency keys, late-arrival vs out-of-order vs duplicate (three different problems, three different mechanisms — `#[OnLateArrival]`, ordering invariants in handlers, dedup set), explicit anti-patterns (no clock-based ordering, no "wait for both events" without timeout, no `Command` from `#[OnLateArrival]` that creates new domain state). Linked from every PM-related Psalm-error message.
- **`ProcessManagerInspector` contract** (interface only — implementation defers to a tooling package)
- **`MessageStagingContractTest`** abstract test class (shared between in-memory and future outbox impls)
- **All Psalm rules** in §12 added to the `nexus-psalm` plugin
- **Deptrac layer + forbidden_imports** rule for framework vendors

## 15. Out of scope for v1

- Outbox table schema and DB-backed staging (downstream package)
- Saga compensation primitives (compensation = a `Command` dispatch; no separate `compensate()` API)
- Runtime DSL configuration
- DB-backed deadline scheduler runtime (timer adapter — separate package)
- **`pm-inspect` CLI implementation** — only the `ProcessManagerInspector` *contract* is in v1; the CLI tool that uses it is a separate tooling package
- **Stuck-PM detection runtime** — only the `findStuck(idleFor)` *contract* is in v1; the polling implementation is downstream
- Process-manager-as-actor adapter (separate package wiring PMs as actors in the actor framework)

---

## 16. Sign-off

All seven user-facing decisions confirmed (§13). Round-2 four-expert review (Mark/Udi/Vaughn/Greg) found 17 additional gaps; this spec revision addresses every one of them.

Next step: re-run the four-expert review board against this updated spec. Iterate until all four reviewers pass. After board approval → invoke `superpowers:writing-plans` to produce the implementation plan.
