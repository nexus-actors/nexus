# Nexus DDD Framework — Umbrella Architecture Design

- **Date:** 2026-05-06
- **Version:** v5.4 (FINAL meta-architecture; feature-completeness pass; ready for P0 brainstorming)
- **Status:** Draft (awaiting review)
- **Scope:** Meta-architecture for the Nexus DDD/CQRS/ES framework. Locks cross-cutting decisions, package boundaries, philosophical position, and phasing. Each package gets its own follow-up design spec.

This document is a *blueprint*. It exists to align cross-cutting rules so per-package specs can stay focused.

> **What changed from v2.** The round-2 review surfaced 24 issues. v3 locks 18 of them with single-answer decisions (no choice of substance) and resolves the 5 contentious ones with explicit user input. Headline changes: (1) Async dispatch is a **bus-level** choice, not a command-class concern — multiple bus instances coexist (sync/async/actor), routing config picks per command class. (2) Event store has a **configurable stream strategy** (Single vs PerAggregateType, Prooph-style); SingleStream is default. (3) Outbox and idempotency tables are **single shared resources** (context-tagged); event store stream strategy is the only per-aggregate variation. (4) **Idempotency is pluggable and configurable on/off** per handler; default is on for async, off for sync (in-tx already-exactly-once). (5) **Process managers run on actor OR sync host**, are DB-backed, opened on demand with per-instance locking (DB row lock for sync, actor mailbox for actor). (6) Event store schema is **columnar** with payload+headers as JSONB. (7) `MessageContext` supports both **injected-parameter** and **coroutine-aware ambient** access. (8) ACL preserves `correlationId`, severs `causationId` (OTel/W3C Trace Context model). (9) Test-kit is its own P0 package (`nexus-ddd-testkit`). (10) Outbox relay HA via **lease+heartbeat** partition ownership. Plus 14 smaller locks documented in §32.

> **What changed from v3 → v3.1.** Round-3 review surfaced 6 critical/major issues that were locked inline: (1) **`dispatchApply`/`applyXxx` resolution convention** locked in §6.4.1 — short-name match, boot-time validation, cached reflection. (2) **Test-kit phasing fixed** — split into `nexus-ddd-testkit-core` (P0), `nexus-ddd-testkit-aggregate` (P1), `nexus-ddd-testkit-pm` (P2). Total 20 packages. (3) **Per-type stream + shared outbox** must live in the same database; XA configurations rejected at boot. (4) **`OccEventStore` subinterface** introduced for OCC-aware conditional append; the existing `nexus-persistence` `EventStore` interface is preserved unchanged. (5) **Pure-CQS command bus** locked — `CommandBus::dispatch()` returns `void` regardless of sync/async/actor; commands are tell-and-forget intent declarations; reads happen on `QueryBus`. (6) **Profile × routing validation** — boot fails (no silent fallback) when a route specifies a bus not available in the active profile.

> **What changed from v5.3 → v5.4.** Feature-completeness pass — closed 6 gaps vs mature DDD/CQRS/ES frameworks: (1) **Continuous projection runner** — new `nexus-ddd-projection` (P3) package with `Projection` interface, `ddd_projection_position` tracking, `bin/ddd projection {run,rebuild,status,pause,resume}` CLI, failure-stops-not-skips semantics, schema-migration rebuild pattern. Closes the gap that Marten / Axon / EventStoreDB fill in their respective frameworks. (§18.1) (2) **Validation middleware slot** — `#[Validate]` attribute + project-supplied `Validator` interface; ValidationFailedException with field-level violations. (§8.5.1.1) (3) **Authorization middleware slot** — `#[Authorize(policy:, subject:)]` attribute + project-supplied `AuthorizationDecider`; subject resolves from message property; principal from MessageContext header. Fail-closed if attribute used without registered decider. (§8.5.1.2) (4) **Health checks** — `HealthCheck` interface + 6 built-in checks (outbox lag, DLQ depth, replay failures, projection status, idempotency table size, relay lease expiration); `bin/ddd health` CLI; `/ddd/health` HTTP endpoint via Symfony bundle. (§23.5) (5) **Operations introspection commands** — 11 new CLI commands in §27.1: aggregate inspection, PM inspection, event sequence validation, snapshot inspection, stuck PM listing, projection management, health. (6) **Published Language recipe** — JSON Schema-based contract pattern in §14.3; framework provides recipe + future `nexus-ddd-schema-registry` package deferred. Plus 14 explicit out-of-scope additions in §30 calibrating adopter expectations. Package count: 20 → 21.

> **What changed from v5.2 → v5.3.** Added `NoGettersSettersOnAggregateRule` (user-requested): aggregates, process managers, and sub-entities cannot expose `get*` / `set*` methods or pure-read state accessors. Framework-required accessors (`id()`, `version()`, `equals()`, `isFinished()`, etc.) and `#[FrameworkAccessor]`-tagged escapes are exempt. Tell-don't-ask is now enforced at static analysis time. Reading aggregate state for queries goes through `QueryBus → QueryHandler → ProjectionTable`; reading for tests uses `AggregateTestFixture::expectState(...)`. Locked at §6.4.0.0; rule listed in §22.

> **What changed from v5.1 → v5.2.** Round-6 review locked four implementation-precision contracts: (1) **`ApplyMethodCoverageRule` strengthened (user-requested):** every `recordThat()` call in an event-sourced aggregate or PM is checked at static analysis time against declared `applyXxx` methods on the same class — missing methods are reported as type errors. Catches the most common ES'd-aggregate mistake. (§6.4.1) (2) **`#[SnapshotConstructor]` snapshot hydration pattern locked** — aggregates with private constructors declare a `#[SnapshotConstructor]` static method that Valinor calls during snapshot rehydration; missing methods on private-ctor aggregates flagged by `SnapshotConstructorRequiredRule`. Same pattern for sub-entities and PMs. (§6.4.1.1) (3) **Canonical bus middleware pipeline order locked** — fixed sequence: causation → OTel span → logging-start → metrics-start → idempotency → OCC retry → handler → metrics-end → logging-end. Critical invariants: idempotency outside retry; OCC preserves messageId across attempts; logging start before idempotency. `MiddlewareOrderingRule` enforces. (§8.5.1) (4) **Replay × idempotency conflict resolved** — `bin/ddd events replay` sets `nexus.replay = true` header; idempotency middleware bypasses dedup when header present; `#[ReplaySafe]` handlers MUST be application-level idempotent (locked semantic); `ReplaySafeIdempotentRule` warns about non-idempotent operations. (§18.2)

> **What changed from v5 → v5.1.** Round-5 review found 5 spec bugs and 5 significant gaps; all fixed inline. Bugs: (1) Replay-failure type mismatch — `load()` keeps `Option<T>`, replay throws `ReplayFailedException` (§6.4.0). (2) Postgres partitioned PK syntax fixed — `PRIMARY KEY (handler_class, message_id, handled_at)` (§13.1). (3) Stale `§6.4.0` cross-references after renumbering corrected to `§6.4.1`. (4) Profile table verified accurate (no fix needed — was a false positive). (5) Schema column-naming note added — `aggregate_*` columns read as "event-sourced entity's *" (§9.4). Gaps locked: (A) `Identifier::fromString(string): static` factory for stored-value rehydration (§6.1). (B) **PM events live in separate table** `ddd_pm_events`, distinct from `ddd_events`; different lifecycles, retention, query patterns (§16.1.4). (C) §33 auto-locks table updated cumulatively across all rounds (now L1–L70). (D) Coroutine-context fallback for non-Swoole environments — `MessageContextScope` adapter pattern; framework ships static + Swoole; ReactPHP/Amphp adapters via extension hook (§7.3). (E) Aggregate-internal replay does NOT publish events; only explicit `bin/ddd events replay` does (§18).

> **What changed from v4 → v5.** Round-4 issues 1–23 folded in: (1) **`EventSourceable` interface unification** — both `AggregateRoot` and `AbstractProcessManager` implement `EventSourceable`; `PersistenceStrategy::persist(EventSourceable $entity)` accepts both. The architecture now compiles (§6.3, §9.2). (2) **`CommandEmissionFailed` system event** wires PM compensation: when a PM-emitted command exhausts retries, this event is published; PMs auto-subscribe; application handlers may also subscribe (§16.1.2). (3) **Compensating commands get fresh retry budget** by default; `#[RetryBudget(inherited: true)]` opts into parent inheritance (§16.1.3). (4) **Aggregate creation pattern** locked: static factory methods (e.g., `Order::placeNew(...)`) record the first event; `repo->save()` is upsert-style; OCC + unique-id constraints catch duplicates (§9.1.1). (5) **Replay failure recovery** locked: fail-loud, never silent; `ReplayFailedException`; aggregate becomes unloadable; `ddd.aggregate.replay_failures` metric (§6.4.0 — same `dispatchApply` convention applies during replay). (6) **`OrderRepository::activeFor` example replaced** with `inBatch()` to honor the command-side rule; lists/queries explicitly route through `QueryBus`+ProjectionTable (§9.1). (7) **`CommandBus` API simplified to two methods:** `dispatch(): void` (pure CQS) and `tryDispatch(): Either<Throwable,Identifier>` (covers tracking via right-side messageId). `dispatchTracked` removed (§8.6). (8) **PM emissions terminology** clarified — "outbox" is the abstract dispatch-deferral; concrete transport is DB outbox (`async`) or actor mailbox (`actor`); semantics identical (§16.1.1). (9) **Per-type stream + per-context table-naming convention** locked: `{context_prefix}ddd_events_{aggregate_short}` (§9.3). (10) **ACL `translate(): iterable<object>`** supports 0..N output messages; cross-context fan-out is at-least-once + idempotent at target (§14.2). (11) **PM snapshot strategy**: default `SnapshotStrategy::none()`; long-lived PMs opt in (§16.0). (12) **Causation through `#[InProcess]` and `#[SharedInvocation]`** explicit in propagation table (§7.4). (13) **`Identifiable`/`Entity`/`EventSourceable` hierarchy** explicit, with PM as `Identifiable` but not `Entity` (§6.3). (14) **Profile ⊃ Host** clarified — profile is a richer preset than just host choice (§4.2). (15) **Clock and `IdGenerator` mandatory** at config; missing throws at boot (§19). (16) **CLI command reference §27.1** consolidates all commands previously scattered across §10/§11/§14/§15. (17) **Sub-entity readonly enforcement** via Psalm rule. (18) **Test-kit covers PM compensation** with `commandFails(...)->expectCompensation(...)` API. Plus internal cross-references updated.

> **What changed from v3.1 → v4.** Round-3 follow-up review (issues 7–27) folded in: (1) **PMs are a distinct type from aggregates** — `AbstractProcessManager` is its own base class, no longer extending `EventSourcedAggregateRoot` (§16.0). Concept clarity over class reuse. (2) **PM emissions always go through outbox** — Saga semantics; `ctx::tell()` from a PM defers via outbox, never sync-dispatched (§16.1.1). (3) **`#[InProcess]` × `#[SharedInvocation]` orthogonality** locked with explicit four-combination table (§11.2.1). (4) **Per-context infrastructure isolation DSL surface** shown — `->isolatedInfrastructure(connection:, eventStorePrefix:, ...)` (§14.1). (5) **`OccEventStore` is the universal contract** — `EventSourcingStrategy` always uses it, even in actor mode (§9.2.1). (6) **`MessageContext` lifecycle and nesting** locked — stack-based, child contexts on nested dispatch, domain-layer access forbidden (§7.3). (7) **`ctx::tell()` boundary** — application-layer only; Psalm rule `DomainContextLeakRule` flags violations from aggregates/VOs/specs/policies (§3 + §7.3). (8) **Outbox row-volume mitigation** — daily range partitioning locked; per-subscriber tables documented as opt-in escape hatch (§11.3.1). (9) **Outbox lease-expiry polling** — 5s with ±1s jitter, recovery latency bounded ~36s (§11.4). (10) **Aggregate ID column length** configurable per-aggregate via `withIdColumnLength()` (§9.4). (11) **Sub-entity Valinor hydration** — public/protected constructor or `#[StaticFactory]` (§6.4.1). (12) **`CompositeIdentifier` canonical format** specified (§6.1). (13) **PHP generics caveat** documented for `Specification`/`Policy` templates (§6.5). (14) **Reverse upcasters** explicitly not supported (§10.2). (15) **Cross-aggregate-type query mitigation** under `PerAggregateTypeStreamStrategy` — `ddd_event_index` parallel index (§9.3). (16) **`IdempotencyKey::for(Envelope)`** signature locked (§13.3). (17) **Redis TTL contract** — default 30 days; longer than max DLQ retry window (§13.1). (18) **OpenTelemetry bidirectional propagation** locked — extract on inbound, inject on outbound, span per handler, ACL preserves trace context (§23.4). (19) **Global retry budget** — `MaxTotalRetryDuration` (default 60s) caps multiplicative retry composition; per-command `#[RetryBudget]` override; `RetryBudgetExhaustedException` on exhaustion (§19a). (20) **Profile-mixing implications** — actor-system process-wide; capacity-planning notes (§4.2). (21) **Doctrine ORM OCC** — strategy translates Doctrine's exception to framework's `OptimisticLockException` (§9.5). (22) **`#[InProcess]` same-DB constraint** — Psalm rule `InProcessHandlerSameDbRule` (§11.2). (23) **Bus-name typo validation** — `BusNameNotRegisteredException` at boot (§8.2.1).

---

## 1. Goals

A production-grade tactical DDD/CQRS/ES toolkit for PHP 8.5+ that runs in three deployment shapes without changes to domain code:

- **Plain PSR-11 / Symfony app** — Doctrine ORM or DBAL persistence, Symfony Messenger as async transport, Symfony Console as the consumer harness.
- **Nexus actor system** — single-writer aggregates hosted in actors, actor-system mailbox as transport, integrated with `nexus-persistence` for event/state stores.
- **Hybrid** — sync handlers in the request path with async consumers for slower work, or actor-hosted aggregates with sync read-model projections.

Inspirations: PF `Server.Shared.DddBundle`, Prooph (PHP CQRS, stream strategies), Ecotone (declarative messaging), Axon Framework (DDD/ES/CQRS), Akka Persistence, EventStoreDB, Marten (.NET), Equinox (F#), Microsoft Orleans (virtual actors). Canonical references: Evans, Vernon, Verraes, Young, Fowler, Khononov.

## 2. Non-Goals

- Not a read-model / projection framework. Apps build read models with regular event handlers; the framework provides the messaging plumbing, not the projection engine.
- Not a workflow engine. Process managers handle DDD coordination, not generic ETL or DAG orchestration.
- No exactly-once delivery. At-least-once + idempotent handlers is the model.
- No backwards-compatibility shims. The project is WIP; breaking changes are accepted.
- No fancy transports in v1 (HTTP/3, QUIC, file-upload streaming, gRPC bridges, network bus federation).
- No cross-machine actor clustering in v1. Single-machine multi-thread (Swoole worker pool). Cross-machine is `nexus-cluster`'s scope.
- No built-in audit log; apps build their own with event handlers.
- No visual workflow / process manager designer.
- No GDPR crypto-shredding implementation in v1; sketched in §11.5 as a P5 concern.

## 3. Philosophy and System Properties

The framework's design is shaped by five non-negotiable properties. Every decision below traces to one of these.

1. **Eventual consistency is the substrate.** Cross-aggregate consistency is *eventual*, never transactional. The framework defaults to outbox-based async event delivery so this property is not surprising — it is the contract. Strong consistency is available only inside a single aggregate.
2. **Aggregate as the consistency boundary.** A command modifies *exactly one aggregate*. Events from that aggregate may trigger work on other aggregates, but those updates are eventual. Apps that ignore this rule will encounter ordering anomalies, optimistic-lock cascades, and torn-read pathologies — and the framework will offer no help. This is the Evans canon, restated explicitly because it is the most-violated DDD rule in real codebases.
3. **Domain code is independent of infrastructure.** Domain payloads (`Command`, `Query`, `DomainEvent`), aggregates, value objects, specifications, and policies are pure data + pure domain logic — no transport, no metadata, no framework concerns. Infrastructure carriers (`Envelope`, `MessageMetadata`) wrap them at the bus/store boundary. *Application services* (command/query/event handlers) live one layer up — they orchestrate domain objects and DO use bus/context APIs (`ctx::tell()`, `MessageContext::current()`, etc.). The Psalm rule `DomainContextLeakRule` enforces the boundary by flagging framework-API calls inside any aggregate, VO, specification, or policy class.
4. **Location transparency.** Handler code is identical regardless of `PersistenceStrategy` and `ConcurrencyHost` selection. Switching axes is a configuration change, never a code change.
5. **Failure-first design.** Every async hop is at-least-once. Handlers SHOULD be idempotent (the framework provides primitives, opt-in or opt-out per handler). Every transaction commit is a recoverable unit. The Evans/Vernon "let it crash and reconcile" mindset is the operating posture.

## 4. Architectural Axes and Deployment Profiles

### 4.1 The Two Axes

The framework is organized around two orthogonal concerns. Every aggregate (and every process manager) makes one independent choice on each axis.

| Axis | Values | Concern |
|---|---|---|
| `PersistenceStrategy` | `EventSourcing`, `DoctrineOrm`, `Dbal`, `InMemory` | Where state/events live |
| `ConcurrencyHost` | `Sync`, `Actor` | Where the handler runs |

The handler code is identical across all combinations.

### 4.2 Deployment Profiles

Three named deployment profiles. Each is a **preset** bundling {`ConcurrencyHost`, available `CommandBus` implementations, default event dispatch model, default `IdempotencyStore` policy} into a coherent operational shape. Profiles never *replace* the underlying axes (§4.1) — they configure them. **Profile ⊃ Host:** a profile is a richer concept than just a host choice; it includes bus availability, dispatch model, and operational defaults.

| Profile | Available command buses | Event dispatch | Concurrency host | Recommended for |
|---|---|---|---|---|
| **`sync`** | `SyncCommandBus` only | Sync in same transaction (no outbox, no relay) | `SyncHost` | **Development and tests only** |
| **`async`** | `SyncCommandBus` (default) + `AsyncCommandBus` (opt-in per route) | **Outbox + relay** (default; `#[InProcess]` opt-in for in-tx subscribers) | `SyncHost` | **Production default** — most apps |
| **`actor`** | `SyncCommandBus`, `AsyncCommandBus`, `ActorCommandBus` (per-route choice) | Outbox + relay; actor mailbox available as alt transport | `ActorHost` | Production with single-writer per aggregate; high-contention domains |

> **`sync` is a development convenience, not a production posture.** The framework will not pretend it provides production guarantees. The `nexus-ddd-debug` web UI flags `sync` mode prominently.

Profiles are presets. Specific routing (which command goes to which bus) and specific axes (per-aggregate persistence/host) are configured after profile selection. Mixing profiles across bounded contexts in the same process is supported.

**Profile-mixing implications (locked):** if any context in the process uses `actor` profile, the Nexus actor system runs process-wide. Non-actor contexts coexist alongside but do not consume actor resources beyond the framework's bridge layer. Capacity planning: actors take Swoole worker threads; an `actor`-profile context with N aggregate types and high write volume should size the worker pool accordingly. Startup time grows: actor-system boot takes longer than sync-bus boot. Apps that mix profiles should evaluate whether the operational complexity is worth the per-context flexibility — usually one profile per process is the right granularity.

## 5. Domain Layer vs Transport Layer Separation

> **Foundational rule:** Domain payloads are pure. Metadata lives on the envelope.

Two layers, two responsibilities:

| Layer | Types | Responsibility |
|---|---|---|
| **Domain** | `Command`, `Query`, `DomainEvent`, aggregates, value objects, specifications, policies | Pure data and pure domain logic. No knowledge of metadata, transport, time, or correlation. |
| **Transport** | `Envelope`, `MessageMetadata`, `HeaderBag`, buses, stores, outbox, transport adapters | Carries domain payloads with metadata. Performs delivery, persistence, retry, dead-lettering. |

```php
// Domain — pure
interface Command {}
interface Query {}
interface DomainEvent {}

readonly class PlaceOrder implements Command
{
    public function __construct(
        public OrderId $orderId,
        public CustomerId $customerId,
        public OrderLines $lines,
    ) {}
}

// Transport — wrapper
final readonly class Envelope
{
    public function __construct(
        public object $payload,
        public MessageMetadata $metadata,
    ) {}

    public static function root(object $payload, IdGenerator $gen, Clock $clock, HeaderBag $headers = new HeaderBag()): self;
    public function caused(object $newPayload, IdGenerator $gen, Clock $clock): self;
    public function withHeaders(HeaderBag $headers): self;
}
```

Aggregates record pure events:
```php
final class Order extends EventSourcedAggregateRoot
{
    public function place(...): void {
        $this->recordThat(new OrderPlaced(...));   // pure DomainEvent
    }
}
```

The repository wraps in `Envelope` on the way to the event store. The bus middleware injects metadata. Handlers accept the pure type.

## 6. Tactical Building Blocks (`nexus-ddd-core`, P0)

Five primitives plus identity and backoff. No external dependencies beyond `fp4php/functional`, `symfony/uid`, and `psr/clock`.

### 6.1 Identity

```php
interface Identifier
{
    public function value(): string;                           // canonical serialization
    public function equals(Identifier $other): bool;           // type AND value match

    /**
     * Reconstruct an instance from its canonical string form.
     * Used by event store / outbox / snapshot rehydration to materialize
     * Identifier subclasses from stored VARCHAR columns.
     *
     * @throws InvalidIdentifierException on parse failure (malformed input)
     */
    public static function fromString(string $value): static;
}

interface CompositeIdentifier extends Identifier
{
    /** @return array<string, scalar> components by name */
    public function components(): array;

    /**
     * Canonical serialization for storage. Default impl in `AbstractCompositeIdentifier`
     * returns components joined by `:` in declared order, with values URL-encoded:
     *   ['tenant' => 'acme', 'order' => '01HXX...'] => "acme:01HXX..."
     * Override for custom formats. The string MUST be deterministic (same components → same string)
     * and round-trippable to components by the parser the impl provides.
     */
    public function value(): string;
}

interface Identifiable
{
    public function id(): Identifier;
}

interface IdGenerator
{
    public function next(): Identifier;
}

final class UlidGenerator implements IdGenerator { /* default */ }
final class UuidGenerator implements IdGenerator { /* drop-in alt */ }
```

`Identifier::equals()` requires both runtime type AND value match — `OrderId('A1')` does not equal `CustomerId('A1')`. The Psalm plugin enforces this distinction. `CompositeIdentifier` is for genuinely composite identities like `(tenantId, orderId)`; storage uses canonical string serialization while the components are accessible for indexing and queries.

The generator is bound once in the container; flipping the binding switches all generated identities atomically.

### 6.2 Value Objects

```php
/** @template T */
abstract class WrappedValue
{
    /** @param T $value */
    final protected function __construct(private mixed $value) {}

    /** @return T */
    public function value(): mixed;

    /** @template U @param callable(T): U $fn @return WrappedValue<U> */
    public function map(callable $fn): static;

    /** @template U @param callable(T): WrappedValue<U> $fn @return WrappedValue<U> */
    public function flatMap(callable $fn): static;

    public function equals(WrappedValue $other): bool;
}

abstract class ObjectValue
{
    public function equals(ObjectValue $other): bool;   // structural via reflection
}
```

Concrete bases: `StringValue`, `IntValue`, `FloatValue`, `BoolValue`, `UlidValue`, `UuidValue`, `ArrayValue`. All `WrappedValue` subtypes are mappable/flatMappable functors. `UlidValue` and `UuidValue` implement `Identifier`.

Composite VOs (`ObjectValue`) are equatable + Valinor-serializable; they do not implement Functor.

### 6.3 Entity, EventSourceable, and the Identity Hierarchy (locked)

The framework's identity-and-state hierarchy is explicit:

```php
interface Identifiable
{
    public function id(): Identifier;
}

interface Entity extends Identifiable
{
    public function equals(Entity $other): bool;   // get_class match AND id->equals
}

/** Anything the framework persists via EventSourcingStrategy implements this. */
interface EventSourceable extends Identifiable
{
    public function pullRecordedEvents(): array;
    public function replay(iterable $events): void;
    public function version(): int;
    public function stateVersion(): int;
}
```

| Type | Implements | Notes |
|---|---|---|
| `AggregateRoot` (abstract) | `Entity`, `EventSourceable` | Both domain-entity semantics AND event-sourcing machinery |
| `EventSourcedAggregateRoot` | (inherits) | `replay()` drives state via `applyXxx` |
| `StatefulAggregateRoot` | (inherits) | Mutates state directly; `recordThat()` still emits to event bus |
| Sub-entity (e.g., `OrderLine`) | `Entity` | Domain entity; NOT event-sourceable on its own — events flow through the aggregate root |
| `AbstractProcessManager` | `Identifiable`, `EventSourceable` | Has identity (associationId) AND event-sourcing machinery, but is **not** a domain `Entity` |

**Key distinction:** PMs are `Identifiable` but not `Entity`. They have id-based identity but are workflow coordinators, not domain objects. The `PersistenceStrategy::persist(EventSourceable $entity)` signature accepts both aggregates and PMs uniformly.

Sub-entities owned by an aggregate extend `Entity`. They MUST be `readonly class`es (or have only `readonly` properties) — the Psalm rule `SubEntityImmutabilityRule` enforces. State changes to sub-entities happen by the aggregate root's `applyXxx` *replacing* the entity instance, not by mutating it. Identity is mandatory; equality is by *type and id*, never by value.

### 6.4 AggregateRoot

```php
abstract class AggregateRoot implements Entity
{
    private int $version = 0;
    private array $recordedEvents = [];

    final protected function recordThat(DomainEvent $event): void
    {
        $this->dispatchApply($event);    // applyXxx invoked synchronously
        $this->recordedEvents[] = $event;
    }

    /** @internal */
    final public function pullRecordedEvents(): array;
    final public function version(): int;

    /** Override per aggregate when state shape changes (defaults to 1). */
    public function stateVersion(): int { return 1; }
}

abstract class EventSourcedAggregateRoot extends AggregateRoot
{
    final public function replay(iterable $events): void
    {
        foreach ($events as $event) {
            $this->dispatchApply($event);   // NO recording on replay
            $this->version++;
        }
    }
}

abstract class StatefulAggregateRoot extends AggregateRoot
{
    // mutable state; applyXxx still mandatory because recordThat() applies through it
}
```

**Locked semantics:**

- `recordThat($event)` invokes the corresponding `applyXxx($event)` synchronously, then appends to `$recordedEvents`. The next aggregate method observes the mutated state.
- `applyXxx()` methods MUST be pure: no I/O, no logging, no `recordThat()`, no `tell()`, no clock access. The Psalm plugin enforces this (`ReplaySafeApplyRule`).
- Replay invokes `applyXxx()` directly without recording.
- **Aggregates contain no command handlers.** Mutations happen via aggregate methods called from the command handler in the application layer.
- `version()` semantics: for `EventSourcedAggregateRoot`, version equals the count of applied events (including replayed). For `StatefulAggregateRoot`, version is a counter incremented at each persist.
- `stateVersion()` is the declared schema version of the aggregate's *state*. When state shape changes (a private field added/removed), bump `stateVersion()`. Snapshots store the version; `SnapshotUpcaster` (§10.4) handles upgrades.
- **Aggregates expose behavior, not state. No getters, no setters.** Tell-don't-ask is enforced (§6.4.0.0). Application code interacts with aggregates by sending commands; reading aggregate state for queries goes through `QueryBus → QueryHandler → ProjectionTable`, never by inspecting aggregate fields.

#### 6.4.0.0 No Getters, No Setters on Aggregates (locked)

Aggregates encapsulate their state. Public methods on aggregates / process managers / sub-entities are **commands** (returning `void` or `void`-equivalent), not state queries. The Psalm rule `NoGettersSettersOnAggregateRule` enforces:

**Forbidden** on `AggregateRoot` subclasses, `AbstractProcessManager` subclasses, and `Entity` implementors (sub-entities):
- Methods named `get*` (e.g., `getStatus()`, `getCustomerId()`)
- Methods named `set*` (e.g., `setStatus()`, `setLines()`)
- Public methods that return state without contributing to a behavior — i.e., pure-read accessors

**Exempt** (framework-required, declared by interface contracts above):
- `id(): Identifier` — required by `Identifiable`
- `version(): int`, `stateVersion(): int`, `pullRecordedEvents(): array`, `replay(...)` — required by `EventSourceable`
- `equals(Entity $other): bool` — required by `Entity`
- `isFinished(): bool` — required by `AbstractProcessManager`
- Any method explicitly tagged `#[FrameworkAccessor]` (escape hatch; reserved for narrow framework integration cases — must be justified in the attribute argument)

**Why this matters:**
- Aggregates exposing getters become anemic — application code reads state and decides; aggregate becomes a data bag.
- Setters break invariants — application code can mutate state to invalid combinations the aggregate's commands would have rejected.
- Reads belong on the read side. Read models are projections updated by event handlers; they live separately from aggregates and serve query needs without leaking aggregate internals.

**Reading aggregate state for testing:** `AggregateTestFixture::expectState(...)` (P1 testkit) accesses private state via reflection — tests need to verify state, but production code does not.

**Reading aggregate state for queries:** wire a `QueryHandler` against a projection table (`ListActiveOrdersHandler` etc.). Never inject `AggregateRepository` into a query handler.

#### 6.4.0 Replay Failure Recovery (locked)

If `applyXxx()` throws during replay (a bug, unexpected null, missing case after event-schema evolution), the framework's response is **fail-loud, never silent**:

1. `EventSourcingStrategy::load()` **throws** `ReplayFailedException(eventsApplied, failingEvent, lastThrowable)` — `load()` keeps its `Option<T>` signature for the absence case (None when no events exist for the id); replay failure is a thrown exception, not a returned `Either::left`.
2. The bus middleware catches `ReplayFailedException` at the boundary and surfaces it as `CommandFailedException` to the caller; original cause preserved.
3. Metric `ddd.aggregate.replay_failures{aggregate_class, event_name}` increments — alerts ops.
4. The aggregate is **unloadable** until the bug is fixed; subsequent commands for that id fail identically. Silent state corruption is worse than an outage.
5. Recovery: fix `applyXxx` → redeploy → retry. Data-corruption cases (malformed event persisted) require an emergency upcaster or manual repair via `bin/ddd events repair --aggregate-id=X`.

The Psalm rule `ApplyMethodTotalityRule` warns when `applyXxx` has unhandled payload-value paths.

#### 6.4.1 `dispatchApply` Resolution Convention (locked)

The `recordThat`/`replay` machinery resolves which method to invoke for a given event class via a **fixed convention**:

1. **Method-name rule.** Method name = `'apply' + (event class short name)`. Resolution is by short class name (no namespace), case-sensitive.
   ```php
   namespace App\Orders;
   readonly class OrderPlaced implements DomainEvent { /* ... */ }

   final class Order extends EventSourcedAggregateRoot
   {
       protected function applyOrderPlaced(OrderPlaced $e): void { /* ... */ }
   }
   ```
2. **Resolution scope.** Each aggregate's `applyXxx` methods are private to that class — no traversal of parent classes, no inheritance lookup beyond declared methods on the concrete aggregate. Parent classes that wish to share apply logic must use `protected static` helper methods called from the concrete `applyXxx`.
3. **Cross-namespace short-name collision.** If two events with the same short name (e.g., `App\Orders\OrderPlaced` and `App\Shipping\OrderPlaced`) are both routed to the same aggregate, boot fails with `ApplyMethodAmbiguousException`. The resolution: rename one event class. (In practice, a single aggregate emits events from a single bounded context, so collisions are rare.)
4. **Boot-time validation.** At container build time, the framework reflects every `EventSourcedAggregateRoot` subclass and every `DomainEvent` it could record (via static analysis of `recordThat()` call sites + declared `#[Event]` registry). Any event without a matching `applyXxx` method throws `ApplyMethodNotFoundException` at boot — never at runtime.
5. **Reflection caching.** The dispatcher is cached as a precomputed map: `(aggregateClass, eventClass) → ReflectionMethod`, built once at boot, retained for the process lifetime. Per-call cost is one array lookup, not a reflection.
6. **Stateful aggregates.** Same convention. `StatefulAggregateRoot::recordThat()` also invokes `applyXxx()` synchronously to keep state consistent. Stateful aggregates can additionally mutate state outside `applyXxx` (direct field assignment), but events MUST be recorded via `recordThat()`, which means `applyXxx()` runs.
7. **Psalm enforcement (locked).** The Psalm plugin's `ApplyMethodCoverageRule` enforces the rule **"every recorded event in an event-sourced aggregate has a corresponding `applyXxx` method"** at static analysis time. Concretely: every call to `$this->recordThat(new SomeEvent(...))` inside an `EventSourcedAggregateRoot` (or `AbstractProcessManager`) is checked against the declared `applyXxx` methods on the same class; missing methods are reported as type errors. This catches the "added a new event but forgot the apply method" bug — the most common ES'd-aggregate mistake — without waiting for boot or runtime. Stateful aggregates are also covered: `recordThat()` invokes `applyXxx` for state mutation, so the rule applies uniformly.

#### 6.4.1.1 Snapshot Hydration Pattern (locked)

Aggregates with private constructors (the canonical pattern, since creation goes through static factories like `Order::placeNew(...)`) need a stable hydration contract for snapshot rehydration via Valinor. Lock the convention:

```php
final class Order extends EventSourcedAggregateRoot
{
    private function __construct(private OrderId $id) {}    // factories go through here

    public static function placeNew(OrderId $id, CustomerId $customer, OrderLines $lines): self
    {
        $order = new self($id);
        $order->recordThat(new OrderPlaced($id, $customer, $lines));
        return $order;
    }

    /** Snapshot hydration entry point. Valinor calls this when rehydrating from a snapshot. */
    #[SnapshotConstructor]
    private static function fromSnapshot(
        OrderId $id,
        CustomerId $customer,
        OrderStatus $status,
        OrderLines $lines,
    ): self
    {
        $order = new self($id);
        $order->customer = $customer;
        $order->status = $status;
        $order->lines = $lines;
        // version is restored by EventSourcingStrategy after construction
        return $order;
    }

    private function applyOrderPlaced(OrderPlaced $e): void { /* mutate state */ }
}
```

Rules:
- The `#[SnapshotConstructor]` static method receives every snapshot-stored field as a parameter (Valinor maps the snapshot blob to method parameters by name).
- Aggregates without `#[SnapshotConstructor]` AND with a private constructor fail snapshot hydration with `SnapshotConstructorMissingException`.
- Aggregates with a public constructor that accepts all state fields don't need `#[SnapshotConstructor]` — Valinor uses the public ctor directly. (Discouraged for domain-purity reasons.)
- The Psalm rule `SnapshotConstructorRequiredRule` flags any `EventSourcedAggregateRoot` or `StatefulAggregateRoot` subclass with a private constructor and no `#[SnapshotConstructor]` static method.

The same pattern applies to sub-entities (§6.3) and process managers (§16).

#### 6.4.2 Sub-Entities Inside Aggregates (Axon-style `@AggregateMember`)

Aggregates may contain entity collections (e.g., `Order` has `OrderLine[]`). The pattern:

```php
final class Order extends EventSourcedAggregateRoot
{
    /** @var array<int, OrderLine> */
    private array $lines = [];

    public function addLine(ProductId $product, int $qty, Money $price): void
    {
        $this->recordThat(new OrderLineAdded($product, $qty, $price));
    }

    private function applyOrderLineAdded(OrderLineAdded $e): void
    {
        $this->lines[] = new OrderLine($e->lineId, $e->product, $e->qty, $e->price);
    }
}

final class OrderLine implements Entity
{
    public function __construct(
        public readonly OrderLineId $id,
        public readonly ProductId $product,
        public readonly int $quantity,
        public readonly Money $price,
    ) {}

    public function id(): OrderLineId { return $this->id; }
    public function equals(Entity $other): bool { /* type + id */ }
}
```

Sub-entities never record events themselves — only the aggregate root does. State changes on sub-entities flow through events recorded by the root. Valinor serializes the entity graph for snapshots.

**Snapshot serialization contract for sub-entities (locked):** entities exposed in snapshots MUST provide a Valinor-callable constructor (public or protected with `#[Allowed]` access) — typically a public `__construct` accepting all properties as constructor parameters. Static factory methods named `from*` are also accepted (e.g., `OrderLine::fromState(...)`). Entities with private constructors that block Valinor must declare a `#[StaticFactory('methodName')]` annotation pointing to a hydration method. The Psalm rule `EntitySnapshotHydrationRule` flags entities reachable from a snapshotted aggregate state that lack a hydration path.

### 6.5 Specification and RichSpecification

```php
/** @template T */
interface Specification
{
    /** @param T $candidate */
    public function isSatisfiedBy(mixed $candidate): bool;

    /** @param Specification<T> $other @return Specification<T> */
    public function and(Specification $other): Specification;
    /** @param Specification<T> $other @return Specification<T> */
    public function or(Specification $other): Specification;
    /** @return Specification<T> */
    public function not(): Specification;
}

/** @template T */
interface RichSpecification
{
    /** @param T $candidate @return Either<NonEmptyList<Failure>, T> */
    public function evaluate(mixed $candidate): Either;
    public function asSpecification(): Specification;
}
```

Use `Specification` for invariants and query filters. Use `RichSpecification` for business rules that surface failure reasons to the user.

> **PHP generics caveat (applies to `Specification<T>`, `RichSpecification<T>`, `Policy<TIn,TOut>`):** PHP cannot enforce template parameters at the interface level — `isSatisfiedBy(mixed $candidate)` is the runtime signature; `@template T` and `@param T` are Psalm-only annotations. Concrete implementations restate types via docblock and (where appropriate) PHP narrowing inside the method body. Callers using the generic interface directly lose type information — narrow via concrete types or `instanceof` when consuming. The Psalm plugin's `ProperSpecificationTypingRule` and `ProperPolicyTypingRule` enforce that concrete impls declare correct generic types.

### 6.6 Policy

```php
/**
 * @template TIn
 * @template TOut
 */
abstract class AbstractPolicy
{
    /** @param TIn $input @return TOut */
    abstract public function apply(mixed $input): mixed;
}

final class PricingPolicy extends AbstractPolicy
{
    /** @param Cart $input @return Money */
    public function apply(mixed $input): mixed { /* ... */ }
}
```

Subclassing forces type declarations because PHP can't enforce template parameters at the interface level.

### 6.7 Domain Service

The framework does not provide a `DomainService` interface — domain services in DDD are stateless service classes registered in DI. They live in the project's domain namespace and are called from aggregates or application handlers.

### 6.8 BackoffStrategy

Foundational primitive for retry timing. Used by OCC retry middleware (§9.4), outbox relay (§11.4), async transport retries (§18), process manager retries (§16.3), and any application-level retry need.

```php
interface BackoffStrategy
{
    /** @return Option<Duration> none = give up; some = wait this long before next try */
    public function delayFor(int $attempt, \Throwable $cause): Option;
}
```

Implementations: `NoRetry`, `FixedDelayBackoff`, `LinearBackoff`, `ExponentialBackoff`, `JitteredExponentialBackoff` (recommended for high-fan-in retries — avoids thundering-herd), `Custom`. `RetryPolicyBuilder` composes per-exception mappings.

## 7. Messaging Layer (`nexus-ddd-messaging`, P0)

### 7.1 Domain Payloads

```php
interface Command {}
interface Query {}
interface DomainEvent {}
```

Marker interfaces only. Domain payloads are pure data — no metadata accessor. They are `readonly class`es by convention; the Psalm plugin enforces immutability.

### 7.2 Envelope and Metadata

```php
interface MessageMetadata
{
    public function messageId(): Identifier;
    public function correlationId(): Identifier;
    public function causationId(): Identifier;
    public function timestamp(): \DateTimeImmutable;
    public function headers(): HeaderBag;
}

final readonly class Envelope { /* §5 */ }
```

`HeaderBag` is an immutable user-extensible map for tenant id, user id, OpenTelemetry `traceparent`, ACL metadata, etc. The framework reserves the `nexus.*` prefix for its own keys; user keys must avoid that prefix.

### 7.3 MessageContext: Both Injection and Coroutine-Aware Ambient

Bus middleware maintains a `MessageContext` for the dispatch scope. Two equivalent access patterns:

```php
// Pattern 1 — injected parameter (preferred for purity, testability)
final class PlaceOrderHandler implements CommandHandler
{
    public function __invoke(PlaceOrder $cmd, MessageContext $ctx): void
    {
        $ctx->tell(new ChargePayment(...));   // explicit context
    }
}

// Pattern 2 — coroutine-aware ambient (sugar)
final class OrdersService
{
    #[CommandHandler]
    public function place(PlaceOrder $cmd): void
    {
        ctx::tell(new ChargePayment(...));    // resolves via Swoole\Coroutine::getContext()
    }                                          // when on Swoole; static otherwise
}
```

**Safety semantics per profile:**
- `sync` profile: ambient context is process-local, naturally safe.
- `async` profile: ambient context is per-handler-invocation scope; safe.
- `actor` profile (Swoole worker pool): ambient context binds to the Swoole coroutine via `Swoole\Coroutine::getContext()`. Each actor message runs in a fresh coroutine, isolated from other actors on the same worker.

**Non-Swoole coroutine environments (locked):** when running under ReactPHP, Amphp, or pcntl-forked workers, the framework's default static-fallback `MessageContextScope` is unsafe — concurrent coroutines on these libraries share the static. Apps in these environments MUST register a `MessageContextScope` adapter via the framework's extension hook:

```php
$ddd->messageContextScope(new ReactPhpMessageContextScope());   // or AmphpMessageContextScope, etc.
```

Adapters bind context to the library's native fiber/coroutine local. The framework ships:
- Default static-scope (PHP-FPM-safe; correct when one request = one process)
- `SwooleMessageContextScope` (uses `Swoole\Coroutine::getContext()`)
- Extension point for adopters of other coroutine libraries

Without an appropriate adapter, the Psalm rule `AmbientContextSafetyRule` flags `MessageContext::current()` usage as unsafe in long-running concurrent contexts.

**Lifecycle and nesting (locked):**
- Created by bus middleware on inbound dispatch (root command from HTTP / console / scheduler / consumer).
- Survives the entire dispatch scope — the handler's call, any `ctx::tell()` deferred dispatches, any `QueryBus::ask()` synchronous reads.
- Destroyed when the outermost dispatch returns / throws.
- **Nested dispatches preserve causation:** if handler A is running (with context `C_A`) and synchronously calls `QueryBus::ask()` for query Q, the bus pushes a child context `C_Q` (causation = `C_A.messageId`). When the query returns, the stack pops back to `C_A`. This is a stack-based scope, not a flat global.
- Implemented via Swoole's coroutine-local storage when on actor profile; via static fallback (process-local) otherwise.

**Domain/application boundary (locked):** `MessageContext::current()` and `ctx::tell()` are *application-layer* APIs — used by command/query/event handlers (which are application services). Aggregates, value objects, specifications, policies (the *domain layer*) MUST NOT call them. The Psalm rule `DomainContextLeakRule` flags `ctx::*` calls in any class extending `AggregateRoot`/`AbstractProcessManager`/`WrappedValue`/`ObjectValue`, or implementing `Specification`/`RichSpecification`/`Policy`.

The Psalm plugin warns when ambient access is used in handlers that may execute in non-Swoole long-running contexts where coroutine isolation isn't guaranteed.

### 7.4 Causation Chain Propagation (P0 mandatory)

Every envelope carries `messageId` / `correlationId` / `causationId` from day one. The bus enforces propagation rules at dispatch time.

| Situation | `messageId` | `correlationId` | `causationId` |
|---|---|---|---|
| Root command (HTTP request, console invocation, scheduled tick) | `idGen.next()` | `= messageId` | `= messageId` |
| Event recorded by aggregate while handling a command | `idGen.next()` | `parent.correlationId` | `parent.messageId` |
| Per-subscriber invocation in independent fan-out (outbox or `#[InProcess]`) | `idGen.next()` | `parent.correlationId` | `parent.messageId` (event's id) |
| `#[SharedInvocation]` (one shared invocation across all subscribers) | `idGen.next()` (single) | `parent.correlationId` | `parent.messageId` |
| Command emitted by a process manager from an event | `idGen.next()` | `parent.correlationId` | `parent.messageId` |
| Async-dispatched message from outbox | (preserved from outbox row) | (preserved) | (preserved) |
| Message crossing a bounded-context ACL | `idGen.next()` (fresh) | **`parent.correlationId` (preserved)** | `parent.messageId` (severs causation chain at boundary) |

ACL preserves correlation so distributed traces survive across context boundaries (matches OpenTelemetry / W3C Trace Context model). The ACL severs the *causation* link only — a new local causation chain begins in the target context, but the OTel trace remains continuous. The original event's `messageId` is also stored in `headers().get('nexus.acl.from.message_id')` for ACL-specific debugging.

When `nexus-ddd-actor` is in use, the bridge maps DDD `MessageMetadata` ↔ Nexus `Envelope` 1:1 — DDD's `messageId` corresponds to Nexus's `requestId`; `correlationId` and `causationId` are identical.

> **P0 lock:** every package in P0 carries full causation through its public APIs.

## 8. Buses, Routing, and Multi-Bus Configuration (`nexus-ddd-bus`, P0)

### 8.1 Multiple Bus Instances Per Type

> **Locked: async-vs-sync is a bus concern, not a command concern.** The framework provides multiple bus implementations per type. Routing config picks which bus handles each message class. The handler is unaware.

Available bus implementations:

| Bus interface | Implementation | Profile availability | Dispatch model |
|---|---|---|---|
| `CommandBus` | `SyncCommandBus` | all profiles | invoke handler in caller's process |
| `CommandBus` | `AsyncCommandBus` | `async`, `actor` | enqueue on async transport; consumer invokes handler later |
| `CommandBus` | `ActorCommandBus` | `actor` | route to actor by aggregate id; actor invokes handler |
| `QueryBus` | `SyncQueryBus` | all profiles | invoke handler synchronously (queries are inherently sync) |
| `EventBus` | `SyncEventBus` | `sync` only | drain recorded events and invoke subscribers in-tx |
| `EventBus` | `OutboxEventBus` | `async`, `actor` | write to outbox; relay dispatches independent fan-out per subscriber |

Naming convention is consistent: `{Sync,Async,Actor}{Command,Query,Event}Bus`. Each implements the same `CommandBus` / `QueryBus` / `EventBus` interface so handlers don't change.

#### 8.1.1 Command Bus Semantics — Tell-and-Forget, Always (locked)

> **Every command is tell-and-forget. `CommandBus::dispatch()` returns `void`. There is no `ask()` for commands. This is pure CQS.** Sync, async, actor — same rule. The bus is a *fire-and-forget intent dispatcher*, never a request-response channel.

The framework enforces this strictly:

1. **`CommandBus::dispatch(Command $cmd): void`** — always void. Whether the command runs synchronously in-process, enqueues to outbox/transport for later, or routes to an actor, the dispatcher returns *only*: success (enqueued or completed) or thrown exception (invariant violation, infrastructure failure, validation rejection). The dispatcher never returns the handler's value.

2. **Command handlers MUST declare `: void` return type.** Any `CommandHandler` returning non-void fails at boot with `CommandReturnTypeException`. Psalm's `CommandHandlerReturnTypeRule` catches this at static analysis time. This is true regardless of bus selection (sync, async, actor).

3. **No `ask()` on `CommandBus`.** The interface intentionally omits any return-value-yielding method. Symfony Messenger's `HandleTrait` pattern is rejected.

4. **Reads happen on `QueryBus`, after the fact.** When the caller needs to know the result of a command, the pattern is: emit a domain event from the command handler → projection updates a read model → caller queries via `QueryBus::ask()`. This is the eventual-consistency model (§3 property #1, §25.1).

5. **Tracking async commands via `messageId`.** Callers needing to *trace* an async command (not retrieve its result) use `tryDispatch()`:
   ```php
   $result = $bus->tryDispatch($cmd);   // Either<Throwable, Identifier>
   $result->fold(
       onLeft: fn($e) => $logger->error('dispatch failed', ['error' => $e]),
       onRight: fn($messageId) => $logger->info('dispatched', ['message_id' => $messageId->value()]),
   );
   ```
   The `messageId` flows into causation metadata; the caller can later query observability surfaces (`ddd.command.duration_ms{message_id=$id}`) or correlated read-model state. The framework provides no built-in command-status oracle — apps build their own from emitted events.

6. **Synchronous failure surfaces immediately.** `dispatch()` throws synchronously when:
   - The bus implementation rejects the command (no handler registered, profile incompatibility).
   - **Sync bus:** the handler throws (invariant violation, OCC after exhausted retries, infrastructure error).
   - **Async bus:** the *enqueue* fails (outbox write fails, transport unavailable). Handler-side failures happen later and surface via DLQ + metrics, not back to the dispatcher.
   - **Actor bus:** the actor system rejects routing (no actor for aggregate id, system shutting down). Actor-side failures during processing are out-of-band, same as async.

7. **No mid-handler synchronous orchestration that expects a response.** A handler can call `ctx::tell()` (deferred dispatch through outbox) or `QueryBus::ask()` (queries are inherently sync). It cannot call `$bus->dispatch()` and read a return value because the value doesn't exist. Psalm's `CommandReturnValueIgnoredRule` flags any attempt to assign `dispatch()` to a variable.

8. **At-least-once semantics for async paths.** Handlers MUST be idempotent or use the framework's `IdempotencyStore` (§13).

This is the Axon/Akka-Persistence/PF model — commands are pure intent declarations. The user calls "do this" and trusts the system to do it correctly. Reading the result is a separate concern, fulfilled by the read side.

### 8.2 Routing Selects the Bus

Multiple bus instances are registered in the container; routing config picks per command class:

```php
$ddd
    ->buses(fn(BusRegistry $b) => $b
        ->command('default', SyncCommandBus::class)        // default bus for commands
        ->command('long-running', AsyncCommandBus::class)  // alternate async bus
    )
    ->commands(fn(CommandRouter $r) => $r
        ->route(PlaceOrder::class, PlaceOrderHandler::class)                      // -> default (sync)
        ->route(BulkImport::class, BulkImportHandler::class, on: 'long-running')  // -> async
        ->route(GenerateReport::class, ReportHandler::class, on: 'long-running')
    );
```

When a caller invokes `$bus->dispatch($cmd)`, the routing layer resolves which concrete bus owns this command class and forwards. Application code calls a single facade `$bus`; under the hood, it's a router that delegates to the appropriate bus instance.

This design matches Symfony Messenger's "buses" concept and mirrors how Prooph organized command/event/query buses. Async vs sync becomes a *deployment* decision, not a *domain* concern.

#### 8.2.1 Profile × Routing Validation (locked)

The set of bus implementations available depends on the deployment profile (§4.2). When a route specifies `on: '<bus-name>'` but the named bus is not available in the current profile, **boot fails with `BusNotAvailableInProfileException`**. No silent fallback to default; the user must explicitly fix the configuration.

Concretely:
- `sync` profile registers only `SyncCommandBus`. Routes specifying `on: 'long-running'` (an `AsyncCommandBus`) cause boot failure.
- `async` profile registers `SyncCommandBus` and `AsyncCommandBus`. Routes specifying `on: 'actor'` cause boot failure.
- `actor` profile registers all three.

Validation runs as part of `NexusDdd::create(...)->build()` — fail-fast at container construction, never at first dispatch. Failure produces a clear diagnostic:
```
BusNotAvailableInProfileException:
  Route for `App\Orders\BulkImport` requires bus `long-running` (AsyncCommandBus),
  but the active profile is `sync`. AsyncCommandBus is unavailable in `sync` profile.
  Fix: switch profile to `async` or `actor`, or change the route to use the default sync bus.
```

A second validation pass catches **bus-name typos**: a route specifying `on: 'long-runnning'` (typo) where no bus by that name is registered fails with `BusNameNotRegisteredException` listing all registered bus names.

This is intentional friction — async vs sync is a deployment-wide property; silently demoting it to sync risks production data correctness. The trade-off is that test environments using `sync` profile must either use `sync`-only routing or have a separate routing config for tests.

### 8.3 Handler Shapes

Two equivalent shapes — both compile to the same DSL routing entry:

```php
// Invokable class
final class PlaceOrderHandler implements CommandHandler
{
    public function __invoke(PlaceOrder $cmd): void { /* ... */ }
}

// Named method on a service
final class OrdersService
{
    #[CommandHandler]
    public function place(PlaceOrder $cmd): void { /* ... */ }
}
```

Marker interfaces (`CommandHandler`, `QueryHandler`, `EventHandler`) AND attributes are both supported. Symfony bundle uses compiler-pass autoconfigure on marker interfaces; framework-agnostic mode uses an attribute scanner.

### 8.4 Bus Semantics

- **Command/Query buses**: single handler per message class. Missing handler at runtime throws `CommandHandlerNotFound` / `QueryHandlerNotFound`. Future Psalm plugin catches at static analysis time.
- **Event bus**: multi-handler. **Independent fan-out by default**: N subscribers ⇒ N transport messages, each retried/dead-lettered independently. Sync inline fan-in (one shared invocation across all subscribers) is opt-in only via `#[SharedInvocation]` on the event class.

### 8.5 Configuration Merge Order (locked)

When the same handler is registered through more than one path:

1. **DSL builder calls** are the canonical record (last-wins within a builder).
2. **Attributes** emit DSL calls before user-supplied builder code runs.
3. **Symfony bundle YAML** emits DSL calls before user-supplied builder code runs.
4. User-supplied DSL builder calls run last and may override.
5. If two attributes register conflicting handlers for the same command/query class, boot fails with `DuplicateRoutingException`.
6. For events, multiple subscribers are additive across all sources.

### 8.5.1 Canonical Middleware Pipeline (locked)

Bus implementations apply middlewares in a fixed canonical order. Diverging from this order produces incorrect runtime behavior (e.g., idempotency-vs-retry interaction); the framework ships a single canonical pipeline used by all `CommandBus` / `QueryBus` / `EventBus` implementations:

```
Inbound dispatch
  ├─ 1. Causation propagation       — establish/extend MessageContext from current scope
  ├─ 2. OpenTelemetry span          — wrap everything below in a traced span
  ├─ 3. Logging (start)             — INFO log with metadata (no payload at INFO; payload at DEBUG)
  ├─ 4. Metrics (start)             — start `ddd.{kind}.duration_ms` timer
  ├─ 5. Validation                  — runs project-supplied Validator if #[Validate] is present
  ├─ 6. Authorization               — runs project-supplied AuthorizationDecider if #[Authorize] is present
  ├─ 7. Idempotency check           — for async paths only; skip handler if already handled
  ├─ 8. OCC retry (handler wrapper) — re-invokes handler on OptimisticLockException per BackoffStrategy
  │   ├─ HANDLER (sync invocation, async enqueue, or actor route)
  │   └─ EVENT DRAIN (CommandBus only) — pull recorded aggregate events, write to outbox
  ├─ 9. Metrics (end)               — record duration, outcome (success/failure)
  ├─ 10. Logging (end)              — INFO log with outcome
  └─ 11. Span close
```

#### 8.5.1.1 Validation Slot (locked)

Commands and queries can declare validation requirements via `#[Validate]`. The framework provides the **slot**; the project supplies the validator (Symfony Validator, Respect, custom):

```php
final class PlaceOrderHandler implements CommandHandler
{
    #[Validate]                              // runs registered Validator before handler
    public function __invoke(PlaceOrder $cmd): void { /* ... */ }
}

interface Validator
{
    /** @throws ValidationFailedException with field-level violations */
    public function validate(object $message): void;
}
```

Project registers a `Validator` implementation in DI; without one, `#[Validate]` is no-op (with boot warning). `ValidationFailedException` carries a `Violations` collection (field path → reasons). Bus middleware lifts to `Either::left(ValidationFailedException)` for `tryDispatch()` callers.

The Psalm rule `ValidatedCommandReadonlyRule` ensures `#[Validate]`-tagged commands are `readonly` (no late mutation between validation and handler).

#### 8.5.1.2 Authorization Slot (locked)

Commands and queries can declare authorization requirements via `#[Authorize]`. Same slot model as validation:

```php
final class CancelOrderHandler implements CommandHandler
{
    #[Authorize(policy: 'order.cancel', subject: 'orderId')]
    public function __invoke(CancelOrder $cmd): void { /* ... */ }
}

interface AuthorizationDecider
{
    /**
     * @throws AccessDeniedException if the principal cannot perform $policy on $subject
     */
    public function decide(string $policy, mixed $subject, MessageContext $ctx): void;
}
```

`subject:` argument names a property on the message that identifies the resource (e.g., `'orderId'` resolves to `$cmd->orderId`). The principal comes from `MessageContext::current()->headers()->get('nexus.principal')` — set by inbound HTTP middleware, console identity, or scheduled-job identity.

The framework provides the slot; the project supplies the decider (Symfony Security voters, Casbin, custom). Without a registered decider, `#[Authorize]` fails closed (`MissingAuthorizationDeciderException` at boot if any handler uses the attribute without a decider registered).

`AccessDeniedException` is converted to `Either::left` for `tryDispatch()` callers.

The Psalm rule `AuthorizeAttributeSubjectRule` validates that `subject:` references an existing property on the command class.

**Critical ordering invariants:**

- **Idempotency outside retry**: deduplication runs once per dispatch, not per retry attempt. Retries within a single dispatch reuse the same `messageId` (see below) so dedup would short-circuit retries — wrong.
- **OCC retry preserves identity**: every retry attempt of the same logical command MUST reuse the same `messageId`, `correlationId`, and `causationId`. Only `headers().get('nexus.retry.attempt')` advances per attempt. Without this rule, idempotency tracking treats each retry as a fresh message and external systems double-effect.
- **Logging start before idempotency**: operators want to see all dispatch attempts, including the deduped ones — log first, dedupe second.
- **Span wraps everything**: OTel spans cover the full pipeline including pre-handler middleware, so traces show the dedup decision, the retry attempts, the metric collection.

Custom middlewares can be inserted by adopters at named pipeline positions (`before: 'idempotency'`, `after: 'occ-retry'`, etc.). The Psalm rule `MiddlewareOrderingRule` flags configurations that violate the invariants above.

### 8.6 Bus Entry Points (simplified)

```php
// CommandBus — tell-and-forget; never returns the handler's value
$bus->dispatch($command): void;                // throws on failure (sync handler error, async-enqueue error)
$bus->tryDispatch($command): Either;           // Either<Throwable, Identifier> — left = failure, right = messageId

// QueryBus — request-response, always synchronous within the bus call
$queryBus->ask($query): mixed;                 // throws on failure
$queryBus->tryAsk($query): Either;             // Either<Throwable, ResultType>

// EventBus — publish, fan-out via outbox or sync
$eventBus->publish($event): void;              // throws if outbox writes fail (sync) or in-tx subscribers throw
$eventBus->tryPublish($event): Either;         // Either<Throwable, Identifier> — right = event's messageId
```

**CommandBus surface is two methods.** `dispatch()` is `void` (pure CQS — never returns handler value, never returns metadata). `tryDispatch()` returns `Either<Throwable, Identifier>` — the right-side `Identifier` is the bus's own metadata about its dispatch (the messageId it minted), useful for observability and tracing. This satisfies both the strict CQS rule (no handler response) and the practical need for tracking. Callers wanting tracking use `tryDispatch()` and ignore the left-or-right distinction beyond logging.

There is intentionally no `dispatchTracked()` overload — `tryDispatch()` covers the use case in two methods rather than three.

## 9. Persistence Layer (`nexus-ddd-aggregate`, `nexus-ddd-dbal`, `nexus-ddd-doctrine`)

### 9.1 Repository

```php
/** @template T of AggregateRoot */
interface AggregateRepository
{
    /** @return Option<T> */
    public function find(Identifier $id): Option;
    /** @param T $aggregate */
    public function save(AggregateRoot $aggregate): void;
}

/** @template T of AggregateRoot */
final class GenericAggregateRepository implements AggregateRepository
{
    /** @param class-string<T> $aggregateClass */
    public function __construct(
        private string $aggregateClass,
        private PersistenceStrategy $strategy,
    ) {}
}
```

`find()` is the **command-side** loader — for write operations. NOT for queries (lists, search). Read-side queries go through `QueryBus`.

Aggregate-specific bulk loaders belong in dedicated `Repository` subclasses for the rare case a single command needs to load multiple aggregates as one transactional unit (typically batch processing):

```php
final class OrderRepository extends GenericAggregateRepository
{
    /** Command-side BULK load for batch processing — NOT a query.
     *  @return iterable<Order>
     */
    public function inBatch(BatchId $batchId): iterable { /* ... */ }
}
```

**Read-side queries (lists, search, filtering) NEVER go through `Repository`.** They go through `QueryBus → QueryHandler → ProjectionTable`. Apps that want "list active orders for a customer" implement `ListActiveOrdersHandler` reading from a projection table updated by event handlers — not via Repository.

#### 9.1.1 Aggregate Creation Pattern (locked)

New aggregates are created via **static factory methods** on the aggregate class. The handler creates and saves; there is no separate `repo->create()` API:

```php
final class Order extends EventSourcedAggregateRoot
{
    private function __construct(private OrderId $id) {}

    public function id(): OrderId { return $this->id; }

    public static function placeNew(OrderId $id, CustomerId $customer, OrderLines $lines): self
    {
        $order = new self($id);
        $order->recordThat(new OrderPlaced($id, $customer, $lines));
        return $order;
    }

    private function applyOrderPlaced(OrderPlaced $e): void
    {
        $this->customer = $e->customerId;
        $this->lines = $e->lines;
        $this->status = OrderStatus::Placed;
    }
}

// In the handler:
final class PlaceOrderHandler implements CommandHandler
{
    public function __invoke(PlaceOrder $cmd): void
    {
        $order = Order::placeNew($cmd->orderId, $cmd->customer, $cmd->lines);
        $this->repo->save($order);
    }
}
```

`repo->save()` is **upsert-style** — handles both first-time creation (expectedVersion=0) and subsequent updates (expectedVersion=N) via `OccEventStore::appendIfVersion()`. For ES, the unique constraint on `(aggregate_id, aggregate_version)` ensures only the first writer of a new aggregate succeeds; concurrent attempts to create the same aggregate id collide as `OptimisticLockException` and retry (which loads the existing aggregate, recognizes the conflict semantically, and decides what to do — usually return a domain error).

For stateful aggregates, the strategy issues `INSERT` for first-time and `UPDATE WHERE version = ?` for subsequent, with the unique-id constraint catching duplicate creation.

### 9.2 PersistenceStrategy

```php
interface PersistenceStrategy
{
    /** @template T of EventSourceable
     *  @param class-string<T> $entityClass
     *  @return Option<T>
     */
    public function load(string $entityClass, Identifier $id): Option;

    /** @throws OptimisticLockException */
    public function persist(EventSourceable $entity): void;
}
```

Note: parameter type is `EventSourceable`, not `AggregateRoot` — this lets the same strategy persist aggregates AND process managers (PMs are `EventSourceable` but not `AggregateRoot`, see §6.3).

Implementations:

| Strategy | Package | Aggregate kind |
|---|---|---|
| `EventSourcingStrategy(EventStore $store, SnapshotStore $snaps, StreamStrategy $stream)` | `nexus-ddd-aggregate` | `EventSourcedAggregateRoot` |
| `DoctrineOrmStrategy(EntityManager $em)` | `nexus-ddd-doctrine` | `StatefulAggregateRoot` |
| `DbalStrategy(Connection $conn, TableMap $map)` | `nexus-ddd-dbal` | `StatefulAggregateRoot` |
| `InMemoryStrategy` | `nexus-ddd-aggregate` | both (tests) |

The `EventSourcingStrategy` reuses `nexus-persistence`'s existing `SnapshotStore` interface and **extends** its `EventStore` with an OCC-aware subinterface (see §9.2.1). Mapping to nexus-persistence: `PersistenceId` is constructed as `(aggregateClassFQN, identifier->value())` at the strategy boundary; aggregates and DDD identifiers stay clean.

#### 9.2.1 `OccEventStore` — OCC-Aware Append (locked)

The existing `nexus-persistence`'s `EventStore::persist()` is unconditional — adequate for actor-based ES where the actor is single-writer. DDD's `EventSourcingStrategy` requires conditional append for OCC under `SyncHost`. We introduce a subinterface in `nexus-ddd-aggregate`:

```php
namespace Monadial\Nexus\Ddd\Aggregate\Event;

use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\PersistenceId;

interface OccEventStore extends EventStore
{
    /**
     * Atomically appends events iff the current highest sequence number for
     * $persistenceId equals $expectedVersion. Throws OptimisticLockException
     * on mismatch — no events are persisted.
     *
     * Implementations encode the version check in SQL (e.g., conditional
     * INSERT with `WHERE NOT EXISTS (... aggregate_version > ?)` or via
     * a unique constraint on (aggregate_id, aggregate_version) that fails
     * the second writer). Read-then-write across two queries is forbidden
     * (TOCTOU race).
     */
    public function appendIfVersion(
        PersistenceId $id,
        int $expectedVersion,
        EventEnvelope ...$events,
    ): void;
}
```

**Why a subinterface, not modifying `EventStore`:**
- `nexus-persistence`'s `EventStore` is consumed by actor persistence which doesn't need conditional append (single-writer guarantee from the actor). Forcing all `EventStore` implementations to support `appendIfVersion` would burden actor-only storage backends (e.g., `InMemoryEventStore`).
- Implementations that DO support conditional append (Doctrine DBAL with unique constraint on `(aggregate_id, aggregate_version)`, `InMemoryEventStore` with explicit version check) implement BOTH `EventStore` AND `OccEventStore`.
- `EventSourcingStrategy::persist()` requires an `OccEventStore`-typed parameter under `SyncHost`. Under `ActorHost`, plain `EventStore` is sufficient.

**Implementations shipped:**
- `InMemoryOccEventStore` (in `nexus-ddd-aggregate`) — for tests
- `DbalOccEventStore` (in `nexus-ddd-dbal`) — DBAL with unique constraint
- `DoctrineOccEventStore` (in `nexus-ddd-doctrine`) — DBAL impl reused; ORM-mapped state aggregates use Doctrine's own version mechanism (see §9.5)

The existing `nexus-persistence` `EventStore` impls (`InMemoryEventStore`, `DoctrineEventStore`) remain unchanged — they continue to work for actor-based ES which doesn't need OCC.

**`EventSourcingStrategy` always uses `OccEventStore` (locked).** Even in `ActorHost` mode, the strategy is constructed with an `OccEventStore`. The OCC check is no-op-equivalent in actor mode (the version always matches, since the actor is single-writer), but the unified contract simplifies the framework: one strategy class, one store interface, one persistence path. The minor extra SQL work in actor mode is negligible compared to the architectural clarity.

### 9.3 Event Store Stream Strategy (Prooph-style)

The event store has a configurable **stream strategy** that determines how events are physically organized:

```php
interface StreamStrategy
{
    public function streamFor(string $aggregateClass, Identifier $id): StreamName;
    public function tableFor(string $aggregateClass): string;
}

final class SingleStreamStrategy implements StreamStrategy { /* default */ }
final class PerAggregateTypeStreamStrategy implements StreamStrategy { /* opt-in */ }
```

| Strategy | Physical layout | Trade-off |
|---|---|---|
| `SingleStreamStrategy` (default) | One `ddd_events` table for all aggregates. Logical streams are filters on `(aggregate_type, aggregate_id)`. | Simple ops, single connection pool, single migration story. Hot-write contention possible at scale. |
| `PerAggregateTypeStreamStrategy` | One table per aggregate type: `ddd_events_orders`, `ddd_events_customers`, … | Physical isolation per type. Better hot-write distribution. More schema migrations to coordinate. |

Single-stream is the default. Per-aggregate-type is opt-in for systems with very high write volume on specific aggregates. Logical streaming via filter is always available regardless of strategy.

**Cross-aggregate-type queries under `PerAggregateTypeStreamStrategy`** (e.g., "all events with `correlationId = X` across all aggregate types") require UNION across per-type tables, scaling poorly as the number of aggregate types grows. The framework provides a parallel `ddd_event_index (correlation_id, aggregate_type, sequence_nr)` table that maintains a cross-type index for observability queries; the debug UI uses this index for the per-`correlationId` view. The index is updated transactionally with each event write (separate row, same transaction). Apps using single-stream strategy don't need this index — the main `ddd_events` table is already cross-type. Apps using per-type strategy can opt out of the index via config if they don't need cross-type queries.

**Per-aggregate-type schema migrations.** New aggregate type = new table. The framework provides `bin/ddd schema migrate-aggregate --type=NewAggregate` that generates the table. CI's `bin/ddd events check-versions` (§10.4) scans all per-type tables when this strategy is active.

**Table-naming convention (locked):** `{context_prefix}ddd_events_{aggregate_short}` where `{context_prefix}` is empty for shared infrastructure or `{context}_` for per-context isolated infrastructure (§14.1). `{aggregate_short}` is the aggregate class short name in lowercase snake_case. Examples: `ddd_events_orders`, `payments_ddd_events_charges`, `shipping_ddd_events_shipments`. If the resulting name exceeds Postgres's 63-char identifier limit, boot fails with `TableNameTooLongException`; apps override per-aggregate via `withTableName('custom_orders_table')`.

CI's `bin/ddd events check-versions` enumerates the cartesian product `(contexts × aggregate_types)` and validates upcaster chains across the full set.

### 9.4 Event Store Schema (locked: columnar)

Whatever stream strategy is used, the row layout is **columnar** with payload + headers as JSONB:

```sql
CREATE TABLE ddd_events (
    sequence_nr     BIGSERIAL    PRIMARY KEY,
    context         VARCHAR(64)  NOT NULL,                    -- bounded context tag
    aggregate_type  VARCHAR(255) NOT NULL,
    aggregate_id    VARCHAR(255) NOT NULL,
    aggregate_version BIGINT     NOT NULL,                    -- per-aggregate sequence
    event_name      VARCHAR(255) NOT NULL,
    schema_version  SMALLINT     NOT NULL,
    message_id      CHAR(26)     NOT NULL,
    correlation_id  CHAR(26)     NOT NULL,
    causation_id    CHAR(26)     NOT NULL,
    written_at      TIMESTAMPTZ  NOT NULL,
    payload         JSONB        NOT NULL,
    headers         JSONB,
    UNIQUE (message_id),
    INDEX idx_aggregate (aggregate_type, aggregate_id, aggregate_version),
    INDEX idx_correlation (correlation_id),
    INDEX idx_event_name (event_name, schema_version)
);
```

Indexable on every metadata field; the debug UI's per-`correlationId` view is a fast index lookup. Schema migrations needed for new metadata fields — managed by the `nexus-ddd-aggregate` package's Doctrine migrations.

> **Naming note:** the column names use `aggregate_*` for historical reasons (the schema was designed for aggregates). After the v5 unification (§6.3), the same `EventSourceable` machinery serves both aggregates and process managers. **PMs use a separate physical table** (`ddd_pm_events` — see Gap B resolution in §16.1.4) to keep aggregate and PM lifecycles cleanly separated, but the column names mirror this schema. The `aggregate_*` names should be read as "the event-sourced entity's *" wherever they appear.

**Aggregate ID column length (locked):** default `VARCHAR(255)` accommodates ULID (26 chars), UUID (36 chars), and most `CompositeIdentifier` canonical strings. Apps with longer composite ids (or wanting smaller indexes for ULID-only systems) override per aggregate via config:

```php
->aggregates(fn(AggregateConfig $a) => $a
    ->register(Order::class, EventSourcing::with(...)->withIdColumnLength(26))   // ULID-tight
    ->register(InternationalShipment::class, EventSourcing::with(...)->withIdColumnLength(512))   // composite tenant+region+id
)
```

The framework validates at boot that any composite ID's `value()` output fits within the configured length; overflow throws `IdentifierTooLongException`.

### 9.5 Optimistic Concurrency Control

Every aggregate carries `version()`. `persist()` enforces version match:
- **Sync host (event-sourced):** uses `OccEventStore::appendIfVersion()` (§9.2.1). Mismatch throws `OptimisticLockException`.
- **Sync host (Doctrine ORM):** delegates to Doctrine's `#[Version]` annotation on the entity. The aggregate declares its version field; Doctrine handles conditional UPDATE. Framework re-throws Doctrine's `OptimisticLockException` as the framework's own.
- **Sync host (Dbal):** `DbalStrategy` issues `UPDATE ... WHERE version = ?` and checks affected-rows count.
- **Actor host:** OCC is a no-op — the actor is single-writer, so concurrent versions cannot exist.

**Default retry policy:** `CommandBus` retry middleware catches `OptimisticLockException` and retries using `JitteredExponentialBackoff(base=50ms, cap=2s, maxAttempts=5)`. Configurable globally via profile config and per-command via `#[Retry]`:

```php
#[Retry(strategy: JitteredExponentialBackoff::class, base: '100ms', cap: '5s', maxAttempts: 10)]
final class PlaceOrder implements Command { /* ... */ }
```

Idempotency for retried commands uses the `messageId` (see §13).

For ES aggregates, `version` equals `aggregate_version` — the highest persisted event sequence number per aggregate. For Stateful aggregates, `version` is a counter column.

### 9.6 Snapshotting (P1 mandatory)

Every `EventSourcedAggregateRoot` declares a snapshot strategy. Default `EveryNEvents(100)`:

```php
final class Order extends EventSourcedAggregateRoot
{
    public static function snapshotStrategy(): SnapshotStrategy
    {
        return SnapshotStrategy::everyNEvents(100);
    }
}
```

Strategies (from `nexus-persistence`, reused): `EveryNEvents`, `OnDemand`, `Custom`. `EventSourcingStrategy::load()` reads latest snapshot, then applies events from `snapshotVersion + 1`. Snapshots store the aggregate's serialized state (Valinor-mapped) plus its `stateVersion()` and `version()`.

#### 9.6.1 Snapshot Schema-Evolution: Three-Tier Strategy

When an aggregate's state shape changes:

| Tier | Mechanism | When to use |
|---|---|---|
| **1. Compatible-version flag** | `#[SnapshotCompatibleWith(prevVersion: 3)]` on the new aggregate class — declares that snapshots from `stateVersion=3` work for the current version. | Adding optional fields with safe defaults; renaming private fields where Valinor mapping handles it. |
| **2. `SnapshotUpcaster`** | Register an upcaster from `stateVersion=N` to `N+1` that transforms the snapshot's serialized state. Loaded by `EventSourcingStrategy` before Valinor mapping. | Field renames, type changes, structural reshaping. |
| **3. Brute-force rebuild** | `bin/ddd snapshot rebuild --aggregate=Order` drops snapshots; replays events to regenerate. | Last-resort for cases the first two can't handle. Slow for large event histories. |

The framework attempts tier 1 → 2 → 3 in order; if all fail, load throws `SnapshotIncompatibleException` and ops chooses to rebuild.

## 10. Event Versioning and Schema Evolution (P1 mandatory)

### 10.1 Stable Event Identity

Each `DomainEvent` declares a stable `eventName` and `schemaVersion`:

```php
#[Event(name: 'orders.OrderPlaced', version: 1)]
readonly class OrderPlaced implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public CustomerId $customerId,
        public Money $total,
    ) {}
}
```

The event store persists `(eventName, schemaVersion, payload)`. PHP class name is **not** persisted. Renames don't affect history (provided `#[Event(name:)]` stays the same). Boot-time check asserts `(eventName, schemaVersion)` is unique across all `DomainEvent` classes; conflicts throw `EventNameCollisionException`.

### 10.2 Upcaster Pipeline

```php
#[Event(name: 'orders.OrderPlaced', version: 2)]
readonly class OrderPlaced implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public CustomerId $customerId,
        public Money $total,
        public CouponCode $coupon = new CouponCode('NONE'),
    ) {}
}

final class OrderPlacedV1ToV2 implements Upcaster
{
    public function eventName(): string { return 'orders.OrderPlaced'; }
    public function fromVersion(): int { return 1; }
    public function toVersion(): int { return 2; }

    public function upcast(array $payload, MessageMetadata $metadata): array
    {
        $payload['coupon'] = 'NONE';
        return $payload;
    }
}
```

On replay, events are upcasted in version order (`v1 → v2 → v3 → current`) before Valinor mapping. Upcasters operate on raw payload arrays.

> **Reverse upcasters / event downgrades are not supported.** The pipeline is forward-only: every replay reaches the latest schema version. In-flight events that have not yet been processed by all consumers must be processed by code that knows about the version they were written under (or a later version with an upcaster). Apps that need to gradually roll out new event versions across consumers should deploy producers AFTER all consumers have been upgraded.

### 10.3 Tombstones

```php
#[TombstoneEvent(name: 'orders.OrderShippedManually', removedAt: 'v3.2.0')]
final class OrderShippedManuallyTombstone {}
```

On replay, tombstoned events are silently skipped (with debug-level log). **Tombstoning a state-affecting event without a compensating upcaster is a violation** — Psalm plugin's `TombstonedEventNotStateAffectingRule` flags this. Tombstones are valid only for events whose effects are no longer relevant to current aggregate state (logging-only, never-applied-to-state events).

### 10.4 Versioning Discipline

Locked rules:
- **Compatible changes (no version bump):** adding optional fields with defaults; adding new event types.
- **Breaking changes (version bump + upcaster):** removing fields, renaming fields, changing types, changing semantics.
- Class-level renames are decoupled from event name; rename freely.
- `bin/ddd events check-versions` validates the upcaster chain at CI (no gaps, no duplicates, every present version reachable from every past).

### 10.5 GDPR / Personal Data Compliance (sketched, P5)

Tombstones do **not** satisfy GDPR right-to-be-forgotten — payload data remains on disk. The recommended pattern (deferred to P5 implementation) is **crypto-shredding**:

```php
#[Event(name: 'customers.AddressChanged', version: 1)]
#[CryptoShred(keyDerivedFrom: 'customerId')]
readonly class AddressChanged implements DomainEvent { /* ... */ }
```

Per-subject encryption key managed in `nexus-ddd-cryptoshred`. Subject deletion = key destruction = unreadable payloads. Out of scope for v1; documented here so adopters know the gap and can plan for it.

## 11. Event Dispatch Model — Outbox by Default (Profile-Aware)

### 11.0 Profile Behavior Summary

| Profile | Command path | Event path |
|---|---|---|
| `sync` (dev) | Sync handler call (only `SyncCommandBus`) | Sync handler call inside same transaction; no outbox |
| `async` (prod) | Sync or async (per route); `SyncCommandBus` is default | Outbox + relay; `#[InProcess]` opt-in for in-tx sync handlers |
| `actor` (prod) | Sync, async, or actor (per route) | Outbox + relay; actor mailbox optional alt transport |

### 11.1 The Outbox Path (production default)

When the command handler returns, bus middleware drains the aggregate's recorded events (`pullRecordedEvents()`) and writes each to the outbox table inside the same transaction. Atomicity guaranteed.

```
Command -> Handler -> repo.save($aggregate)
                          ↓
                    BEGIN TX
                      persist aggregate state/events
                      INSERT INTO ddd_outbox (envelope) VALUES (...)
                    COMMIT TX
                          ↓
                    [later] OutboxRelay reads outbox -> EventBus -> subscribers
```

Outbox row stores the full `Envelope` (payload + metadata, including all causation ids). Relay dispatches asynchronously; each subscriber gets its own envelope (independent fan-out, §8.4).

### 11.2 `#[InProcess]` Opt-In for Atomic Subscribers

Read-model projections that need atomic consistency with the aggregate opt into in-transaction dispatch:

```php
final class OrderReadModelProjector implements EventHandler
{
    #[InProcess]
    public function __invoke(OrderPlaced $event): void
    {
        // runs inside the source command's transaction
        // must throw to roll back the aggregate's commit
    }
}
```

Constraints (Psalm-enforced): idempotent, local (no network), fast (sub-50ms p99). **Plus a hard constraint: an `#[InProcess]` subscriber MUST write to the same database connection as the source aggregate's persistence target.** Cross-database in-tx subscriptions would require XA/2PC, which the framework rejects (§11.3). The Psalm rule `InProcessHandlerSameDbRule` flags handlers that target a different connection than the source aggregate's bound connection.

Failure model:
- If `#[InProcess]` subscriber throws, the *entire transaction rolls back* — aggregate state is not persisted, outbox rows for OTHER subscribers are not written, no event is published downstream.
- If outbox writes fail (disk full, constraint violation), the transaction rolls back — no `#[InProcess]` work persists either.
- Atomicity holds: either all in-tx work + all outbox rows commit, or nothing does.

#### 11.2.1 `#[InProcess]` and `#[SharedInvocation]` are Orthogonal

Two attributes operate on different dimensions; they compose freely:

| Attribute | Concern | Default |
|---|---|---|
| `#[InProcess]` | Transaction boundary (in-tx vs outbox) | outbox (async) |
| `#[SharedInvocation]` | Fan-out shape (one invocation across all subscribers vs N invocations) | independent fan-out (N invocations) |

The four resulting combinations:

| InProcess | SharedInvocation | Behavior |
|---|---|---|
| ✗ (default) | ✗ (default) | Outbox + independent fan-out: N subscribers each get their own outbox row, dispatched independently (R5 — the canonical production model) |
| ✓ | ✗ | In-tx + independent fan-out: N subscribers each invoked synchronously inside source tx, each isolated (rare; useful for multiple atomic projections) |
| ✗ | ✓ | Outbox + shared invocation: one outbox row, one consumer invocation runs all subscribers in sequence (rare; only when subscribers genuinely share work) |
| ✓ | ✓ | In-tx + shared invocation: one synchronous invocation in source tx (rarest; specific consistency need) |

The Psalm rule `EventDispatchAttributesRule` validates that attribute combinations are coherent and that handlers used with `#[SharedInvocation]` accept all subscribed event subclasses.

### 11.3 Single Shared Outbox + Single-Database Transactional Constraint

> **Locked: one shared outbox table across all bounded contexts** (context-tagged via a `context` column). Per-context isolation is the exception, opt-in via configuration.

> **Critical constraint (locked):** The aggregate event tables and the shared outbox MUST live in the **same database** (same connection, same transaction). This applies to all stream strategies including `PerAggregateTypeStreamStrategy`. The framework rejects configurations at boot that would require distributed transactions (XA / 2PC) — `XAConfigurationException`.

Implications:
- For `SingleStreamStrategy`: trivially satisfied (one `ddd_events` + one `ddd_outbox` in the same database).
- For `PerAggregateTypeStreamStrategy`: all `ddd_events_*` tables and `ddd_outbox` MUST be in the same database. Per-aggregate-type tables are physical-isolation-within-one-database, not isolation-across-databases.
- For per-context schema isolation (§14.1): each context's events + outbox + idempotency MUST live together in that context's database. Cross-context ACL relays bridge across databases at the message level (translation + re-publish), never at the transaction level.
- The framework provides `bin/ddd config validate` that checks every aggregate's persistence target against the configured outbox connection at boot.

```sql
CREATE TABLE ddd_outbox (
    id              BIGSERIAL    PRIMARY KEY,
    context         VARCHAR(64)  NOT NULL,
    partition_key   INTEGER      NOT NULL,             -- hash(aggregate_id) mod N
    envelope        JSONB        NOT NULL,
    subscriber      VARCHAR(255) NOT NULL,             -- one row per subscriber (independent fan-out)
    available_at    TIMESTAMPTZ  NOT NULL,             -- backoff scheduling
    attempts        INTEGER      NOT NULL DEFAULT 0,
    leased_by       VARCHAR(64),                       -- relay owning the row
    leased_until    TIMESTAMPTZ,
    INDEX idx_dispatch (partition_key, available_at) WHERE leased_by IS NULL
);
```

One outbox; one idempotency table; multiple bounded contexts read/write to them with the `context` column scoping.

#### 11.3.1 Row-Volume Partitioning (locked)

The current row layout produces N rows per event (one per subscriber, for independent fan-out R5). For high-volume systems this dominates the database. Default mitigation:

- **Daily range partitioning** on `created_at` (Postgres declarative partitioning). Each day is its own partition; `bin/ddd outbox gc --older-than=7d` drops old partitions cheaply (no row-by-row deletion).
- Lease ownership operates per-partition-scan, so partition boundaries don't affect FIFO ordering within a partition (relay scans the active partition first, then the next).
- `INDEX (partition_key, available_at) WHERE leased_by IS NULL` is local to each partition.

**Per-subscriber outbox tables are an opt-in escape hatch** for systems where one subscriber dominates volume and benefits from physical isolation (e.g., a high-write read-model projection vs low-volume notification handlers). Not the default — adds complexity for marginal benefit in most workloads.

### 11.4 Outbox Relay HA via Lease + Heartbeat

Multiple relay processes can run in parallel for HA and throughput. Each claims partition ownership via lease:

```sql
CREATE TABLE ddd_outbox_lease (
    partition       INTEGER     PRIMARY KEY,
    owner_id        VARCHAR(64) NOT NULL,
    expires_at      TIMESTAMPTZ NOT NULL
);
```

- A relay claims partition K: `INSERT ... ON CONFLICT (partition) DO UPDATE SET owner_id = EXCLUDED.owner_id, expires_at = NOW() + 30s WHERE expires_at < NOW()`.
- Relay heartbeats every 10s, extending its lease.
- If a relay dies, lease expires after 30s; another relay can claim.
- Within its partition(s), the relay drains rows in `(available_at, id)` order, preserving FIFO per aggregate id.

Default partition count = 16 (configurable). Throughput target v1: 1k–5k msgs/s/partition. Higher requires more partitions (and more relay processes).

**Lease-expiry polling (locked):** each surviving relay polls the lease table for expired leases every **5 seconds** with **±1s uniform jitter** (per relay) to avoid thundering-herd on simultaneous expiry. Recovery latency from a relay death is bounded at `lease_timeout (30s) + max_poll_interval (~6s) ≈ 36s` worst-case before another relay claims the orphaned partition.

**Failure handling per row:** dispatch failure increments `attempts` and schedules `available_at = NOW() + BackoffStrategy::delayFor(attempts, $cause)`. Default `JitteredExponentialBackoff(base=1s, cap=10m, maxAttempts=10)`. After exhausted retries, row moves to `ddd_outbox_dlq` (per-handler DLQ via the `subscriber` column). Per-event override via `#[Retry]`.

## 12. Concurrency Host (`nexus-ddd-actor`, P4)

```php
interface ConcurrencyHost
{
    public function dispatch(Identifier $aggregateId, object $command): mixed;
}
```

- **`SyncHost`** (default in `sync` and `async` profiles) — invokes handler in caller's process.
- **`ActorHost`** (`actor` profile) — routes commands by aggregate id to a Nexus actor (one actor per aggregate id). Single-writer; FIFO mailbox; persistence inside actor processing.

Handler code is **identical** in both hosts.

### 12.1 Actor Partitioning

`ActorHost` reuses `nexus-worker-pool`'s `ConsistentHashRing` (CRC32, 150 vnodes) for `(aggregateClass, aggregateId)` → worker mapping. Each Swoole worker thread hosts a subset of aggregate instances; an instance lives in exactly one worker until evicted from the actor cache (LRU).

### 12.2 Rebalancing

When the worker pool size changes (rare — fixed at boot in v1):
- Existing actors finish their current message, then unhydrate.
- New messages route to the new owner.
- No in-flight migration.

V1 does not support adding/removing workers at runtime.

### 12.3 Hot Keys

Single hot aggregate id saturates one worker. Mitigation in v1 is application-level: partition the aggregate (e.g., one `InventoryShard` per warehouse-shard combination). No automatic hot-key throttling.

### 12.4 Single-Machine Scope

V1 of `ActorHost` is single-machine multi-thread. Cross-machine clustering is `nexus-cluster`'s scope.

## 13. Idempotency (Pluggable, On/Off Configurable)

> **Locked: idempotency is pluggable and configurable** — each handler chooses its own store (Postgres / Redis) and may opt out entirely.

### 13.1 Pluggable Store

```php
interface IdempotencyStore
{
    /** @return bool true if newly recorded, false if already handled */
    public function recordHandled(string $handlerClass, Identifier $messageId): bool;
}

final class PostgresIdempotencyStore implements IdempotencyStore { /* default */ }
final class RedisIdempotencyStore implements IdempotencyStore {
    // TTL = 30 days by default; configurable per handler.
    // Constraint: TTL MUST be longer than the maximum DLQ-retry window for any handler
    // routed through this store, otherwise a TTL-expired key may slip a duplicate
    // through during late retry. Framework validates this at boot
    // (RedisTtlTooShortException if TTL < max retry window).
}
final class NoOpIdempotencyStore implements IdempotencyStore { /* opt-out */ }
```

The default `PostgresIdempotencyStore` uses range partitioning by `handled_at`:

```sql
CREATE TABLE ddd_message_handled (
    handler_class   VARCHAR(255) NOT NULL,
    message_id      VARCHAR(26)  NOT NULL,
    context         VARCHAR(64)  NOT NULL,
    handled_at      TIMESTAMPTZ  NOT NULL,
    -- Partition column MUST be in the primary key for Postgres declarative partitioning.
    PRIMARY KEY (handler_class, message_id, handled_at)
) PARTITION BY RANGE (handled_at);
-- daily partitions; old partitions dropped by `bin/ddd idempotency gc`
```

### 13.2 Per-Handler Configuration

Handlers opt in/out and pick the store via attribute:

```php
// Default: idempotency on for async handlers via PostgresIdempotencyStore
final class NotifyCustomer implements EventHandler { /* ... */ }

// Override: high-volume handler uses Redis
#[Idempotent(store: 'redis')]
final class HighVolumeProjector implements EventHandler { /* ... */ }

// Override: opt-out (handler is provably idempotent in code, no need for table)
#[Idempotent(off)]
final class OrderCacheInvalidator implements EventHandler { /* ... */ }
```

Profile defaults:
- `sync` profile: idempotency off everywhere (handlers run in-tx, retry semantics make it irrelevant).
- `async` and `actor` profiles: idempotency on for async handlers; off for `#[InProcess]` handlers (in-tx).

### 13.3 Application-Level Idempotency

For handlers whose work isn't database-local (sending an email, calling an API), the framework offers `IdempotencyKey::for(Envelope $envelope): string` derived from `$envelope->metadata->messageId` so external systems can dedupe consistently. The signature is locked to `Envelope` (not raw payload) — the messageId lives on metadata, not on the domain payload. Application code calls this helper inside the handler, passing the inbound envelope received via `MessageContext::current()->envelope()` (or the parameter-injected `MessageContext`).

### 13.4 Garbage Collection

`bin/ddd idempotency gc --older-than=30d` drops old partitions cheaply. Retention window is application policy.

## 14. Bounded Contexts and Anti-Corruption Layer

### 14.1 One Bus Per Context, Shared Infrastructure

Each bounded context instantiates its own `NexusDdd` instance with its own `CommandBus`/`QueryBus`/`EventBus`/routing table. **Shared infrastructure (single outbox table, single idempotency table, single event store) is the default**; rows are tagged with `context VARCHAR(64)`.

Per-context schema isolation (separate Doctrine connections, separate tables) is opt-in — used when compliance or operational concerns require strong physical separation.

```php
// Default: shared infrastructure
$ordersContext = NexusDdd::create($container)
    ->context('orders')
    ->build();

$shippingContext = NexusDdd::create($container)
    ->context('shipping')
    ->build();

// Opt-in: per-context isolation (separate database)
$paymentsContext = NexusDdd::create($container)
    ->context('payments')
    ->isolatedInfrastructure(
        connection: 'payments_db',                    // separate Doctrine connection
        eventStorePrefix: 'payments_',                // ddd_events → payments_ddd_events
        outboxTable: 'payments_ddd_outbox',
        idempotencyTable: 'payments_ddd_message_handled',
    )
    ->build();
```

By default, all contexts share the same `ddd_outbox`, `ddd_message_handled`, `ddd_events` tables; rows are scoped by `context`. With `isolatedInfrastructure(...)`, the context gets its own physical tables and connection — strong separation, but ACL relays must connect to BOTH contexts' databases at the message level (translate + re-publish across boundary).

### 14.2 Anti-Corruption Layer (`nexus-ddd-context`, P4)

When `shipping` consumes events from `orders`, the foreign event is **not** dispatched directly into `shipping`'s bus. An ACL translator runs first:

```php
final class OrderPlacedToShipmentRequest implements ContextTranslator
{
    /** @param OrdersContext\OrderPlaced $foreign
     *  @return iterable<object> 0..N translated messages; empty = drop source
     */
    public function translate(object $foreign, MessageMetadata $foreignMetadata): iterable
    {
        // 1-to-1 translation
        yield new ShipmentRequested(
            shipmentId: ShipmentId::generate(),
            destination: Address::fromOrderAddress($foreign->shippingAddress),
            items: ShippableItems::fromOrderLines($foreign->lines),
        );
        // Filter (drop) returns empty iterable; 1-to-N translation yields multiple
    }
}

$shippingContext->acl()
    ->translate(OrdersContext\OrderPlaced::class, OrderPlacedToShipmentRequest::class);
```

The translator is a subscriber on the source context's event bus that publishes 0..N translated messages to the target context's bus. **The signature `translate(): iterable<object>`** supports filtering (empty iterable), 1-to-1 (single yield), and 1-to-N (multiple yields). **Causation propagation across ACL** (locked):
- each target message's `messageId` = fresh
- each target message's **`correlationId` = source's `correlationId`** (preserved for distributed tracing — OpenTelemetry / W3C Trace Context model)
- each target message's `causationId` = source's `messageId` (causation chain links across boundary)
- original event's id stored in `headers().get('nexus.acl.from.message_id')`

**Cross-context delivery atomicity (locked):** when a translator produces N messages, all N are published to the target context's bus inside a single transaction (target context's outbox tx). If publishing any one fails, none commit; the source event is re-translated on the next ACL relay tick. Translation is at-least-once + idempotent at the target (target's `IdempotencyStore` dedupes via target messageId). For cross-DATABASE ACL (target context has isolated infrastructure), the relay performs the source-read + target-write as two-step at-least-once (no XA), with target idempotency catching duplicates on retry.

### 14.3 Published Language (recipe)

Cross-context events should use a stable JSON-schema versioned format — the contract that consumer contexts can safely depend on without knowing the producer's PHP class internals.

**Recommended pattern:**
1. Each producing context maintains JSON Schema files under a stable path: `acl/published/{event-name}.v{N}.json`. The schema is checked into the producer's repo and (for multi-service deployments) published at a versioned URL.
2. Consumer contexts deserialize via Valinor against the JSON Schema, not against the producer's PHP class. This decouples consumer from producer's runtime types.
3. The schema includes the same `eventName` + `schemaVersion` fields as the producer's `#[Event]` attribute (§10.1), so the version contract is explicit.
4. Breaking changes follow producer-first deployment (§10.2): publish v2 schema → consumers upgrade → producer starts emitting v2 → eventually v1 schema deprecated.

**Why the framework doesn't ship a schema registry in v1:**
- Multi-service registries (Confluent Schema Registry, AWS Glue) are external infrastructure choices apps make based on their broader stack.
- Single-process multi-context apps don't need a registry — JSON Schemas in the source tree suffice.
- Building a registry in PHP would re-invent existing infrastructure poorly.

A future package `nexus-ddd-schema-registry` (deferred) could provide adapter integrations for Confluent / AWS Glue. For v1, the recipe above keeps adopters consistent without locking in to a specific registry.

### 14.4 Cross-Process Boundaries Out of Scope

The framework intentionally does not provide cross-process / cross-machine context federation in v1. Outbound to other services is via the project's chosen transport; inbound is via subscribers that translate via the ACL pattern.

## 15. Schema Migration Ownership

Each `nexus-ddd-*` package that owns infrastructure tables ships its Doctrine migrations under a package-namespaced directory:

| Package | Owns tables |
|---|---|
| `nexus-ddd-aggregate` | `ddd_events`, `ddd_snapshots` (event-sourced aggregates) |
| `nexus-ddd-outbox` | `ddd_outbox`, `ddd_outbox_lease`, `ddd_outbox_dlq` |
| `nexus-ddd-bus` | `ddd_message_handled` (idempotency); only the schema, store impl is in adapter |
| `nexus-ddd-process-manager` | `ddd_pm_state`, `ddd_pm_locks`, `ddd_pm_dlq` |
| `nexus-ddd-scheduling` | `ddd_scheduled`, `ddd_deadlines` |

A central command `bin/ddd schema migrate` runs all framework migrations in dependency order. Adopters' app migrations remain separate (Doctrine `migrations.yaml` namespacing).

## 16. Process Managers (`nexus-ddd-process-manager`, P2)

> Sagas-as-a-term is dropped. "Process manager" is the single concept.

> **Locked:** (1) PMs are a distinct type from aggregates — `AbstractProcessManager` is its own base class, not `EventSourcedAggregateRoot`. (2) PMs run on actors OR without; state in DB; opened on demand with locking. (3) PM-emitted commands ALWAYS go through the outbox (Saga semantics) — never sync-dispatched inside the PM's transaction.

### 16.0 `AbstractProcessManager` — Distinct Type from Aggregates

Aggregates and process managers serve different purposes (Vernon's distinction):
- **Aggregate:** consistency boundary in a single bounded context, encapsulating invariants over its state.
- **Process Manager:** workflow coordinator across aggregates, holding workflow state and orchestrating commands.

They share machinery (event recording, replay, snapshot) but the type hierarchy keeps them distinct:

```php
abstract class AbstractProcessManager
{
    private int $version = 0;
    private array $recordedEvents = [];

    final protected function recordThat(DomainEvent $event): void
    {
        $this->dispatchApply($event);   // same applyXxx convention as §6.4.1
        $this->recordedEvents[] = $event;
    }

    final public function pullRecordedEvents(): array;
    final public function version(): int;

    /** Override per PM. Returns true when the PM has reached terminal state. */
    abstract public function isFinished(): bool;

    /** PM state schema version for snapshot upgrade (§9.6.1). Defaults to 1. */
    public function stateVersion(): int { return 1; }

    /**
     * Override per PM. Defaults to `SnapshotStrategy::none()` — full event replay always.
     * Long-lived PMs (e.g., subscription billing PMs) may opt into snapshotting.
     */
    public static function snapshotStrategy(): SnapshotStrategy
    {
        return SnapshotStrategy::none();
    }
}
```

PMs default to **no snapshotting** because most PMs are short-lived (start on association event, finish on terminal state, total event count typically <100). Long-lived PMs (subscriptions, multi-stage orchestrations) override `snapshotStrategy()` to opt in. The same `bin/ddd snapshot rebuild --pm=...` command applies for force-regeneration.

`AbstractProcessManager` does NOT extend `AggregateRoot` or `EventSourcedAggregateRoot` — distinct concepts get distinct types. The framework's `EventSourcingStrategy` works on either (interface-based, not class-based — both implement an internal `EventSourceable` mixin or are matched structurally).

### 16.1 PM Lifecycle (Both Hosts)

A PM is logically a virtual actor: at most one instance per association id is active at any time. The lifecycle:

1. Event arrives at the PM router.
2. Router resolves association (e.g., `orderId = X`) → PM instance id.
3. **Acquire instance lock** (host-specific: §16.2).
4. **Load PM state from event store** (replay events, possibly via snapshot — same machinery as aggregates).
5. Invoke the PM's `#[OnEvent]` handler.
6. PM may emit **commands and events** via `ctx::tell()` / `ctx::publish()`. **All emissions are deferred to the outbox** — they are queued in the PM's own transaction and dispatched by the relay after commit. The PM never holds locks across direct command execution; the Saga pattern is the only emission model. (See §16.1.1.)
7. Persist new PM events to event store + emitted-command/event rows to outbox in the same transaction.
8. Commit transaction. **Release lock.**

This is the "virtual actor" / "actor on demand" pattern (Microsoft Orleans, Akka cluster sharding). The PM is event-sourced; state is always durable in DB; in-memory presence is opportunistic.

#### 16.1.1 PM Emissions are Always Outboxed (Saga semantics)

**Critical lock:** When a PM handler calls `ctx::tell($command)` or `ctx::publish($event)`, the framework does NOT dispatch synchronously through the bus. Instead, it appends an outbox row inside the PM's transaction. The outbox relay later dispatches the command through the appropriate bus.

Reasons:
- **Lock duration.** A PM in `SyncHost` holds a DB row lock during processing. Synchronous emission would extend the lock duration to include downstream handlers' work — escalating contention and causing cascading slowdowns.
- **Atomicity.** PM state mutation + outbox writes commit atomically. If commit fails, no events recorded AND no commands emitted.
- **Decoupling.** Downstream command failures do not roll back PM state; they're independent retries via outbox dispatch.
- **Compensation timing.** `OnCommandFailure` handlers (§16.3) fire when the relay reports exhaustion — naturally async, never holding the original PM's lock.

> **Terminology note (locked):** "outbox" in this section refers to the abstract dispatch-deferral mechanism — the durable hand-off point where a message is committed atomically with the source state and dispatched asynchronously by a relay. Concrete implementation depends on profile:
> - `async` profile: DB outbox table (`ddd_outbox`) + relay process (§11.4)
> - `actor` profile: actor system's persistent mailbox + actor scheduler — same atomicity semantics, different transport
>
> The PM's contract is identical regardless: commit aggregate state + emission record together; relay/scheduler dispatches asynchronously; failures are independent of source PM state.

The Psalm rule `PMSyncDispatchRule` flags any direct `$bus->dispatch()` calls from within a PM handler — apps MUST use `ctx::tell()` so the framework can route through the outbox.

#### 16.1.2 Compensation Wiring via `CommandEmissionFailed` (locked)

When a PM-emitted command exhausts its retry budget (or the `compensateAfter` threshold on `OnCommandFailure`), the framework publishes a **system event** on the source PM's bus:

```php
readonly class CommandEmissionFailed implements DomainEvent
{
    public function __construct(
        public string $pmClass,
        public Identifier $associationId,
        public object $originalCommand,         // the failed command
        public MessageMetadata $originalMetadata,
        public \Throwable $finalException,
    ) {}
}
```

The PM is auto-subscribed to `CommandEmissionFailed` events whose `pmClass` matches its own. The `OnCommandFailure(handlesCommand: ChargePayment::class, ...)` attribute is sugar over `EventHandler<CommandEmissionFailed>` filtered by `originalCommand instanceof ChargePayment`. The framework synthesizes the subscription at boot.

This event is **observable to application handlers** as well — useful for centralized alerting, metrics, audit logs:
```php
final class CompensationAlerter implements EventHandler
{
    public function __invoke(CommandEmissionFailed $event): void
    {
        $this->slack->notify("PM {$event->pmClass}/{$event->associationId} failed: {$event->finalException->getMessage()}");
    }
}
```

The Psalm rule `OrphanedCommandEmissionRule` warns when a PM emits commands of class C but has no `OnCommandFailure(handlesCommand: C::class, ...)` handler — silent failures are flagged.

#### 16.1.3 Compensating Commands Get a Fresh Retry Budget (locked)

When a compensator (`OnCommandFailure` handler) emits its own commands via `ctx::tell()`, those commands start with a **fresh** `RetryBudget` (default 60s) rather than inheriting the parent's exhausted budget. Compensation paths are recovery — they need their own time to succeed.

Apps that want strict end-to-end deadlines override per-compensator:
```php
#[OnCommandFailure(handlesCommand: ChargePayment::class, ...)]
#[RetryBudget(inherited: true)]                  // inherit parent budget instead
public function compensate(...): void { /* ... */ }
```

The metric `ddd.compensation.budget_used_pct{pm_class, original_command}` tracks compensator budget consumption.

### 16.1.4 PM Events Live in a Separate Table (locked)

Process managers and aggregates share `EventSourceable` machinery but **do NOT share the event store table**. PM events live in `ddd_pm_events` (separate from `ddd_events`):

- Different conceptual lifecycles: aggregates are long-lived (years); PMs are typically short-lived (days/hours/minutes) and reach terminal state.
- Different retention policies: aggregate events are usually kept forever; PM events follow `RetainFor` / `SnapshotOnly` / `HardDelete` per `#[ProcessManager(retention: ...)]`.
- Different query patterns: aggregate events are joined for read-model rebuilds; PM events are queried for "what's happening with PM instance X".
- Different stream strategies: aggregates use `SingleStreamStrategy` or `PerAggregateTypeStreamStrategy`; PMs always use single-stream (`ddd_pm_events`).

Schema for `ddd_pm_events` mirrors `ddd_events` (same columns, same indexes), with `aggregate_*` columns reading as `pm_*` semantically (the column-naming note in §9.4 applies). The `EventSourcingStrategy` accepts a `tableTarget` parameter at construction; the framework's PM router constructs strategies pointing at `ddd_pm_events`.

`bin/ddd schema migrate` creates both tables. Per-context isolation (§14.1) creates `{context}_ddd_pm_events` alongside `{context}_ddd_events`.

### 16.2 Locking by Host

| Host | Lock mechanism |
|---|---|
| `SyncHost` | DB row lock on `ddd_pm_locks (pm_class, association_id)` via `SELECT ... FOR UPDATE NOWAIT`. Held for the duration of the event handler + outbox writes. Released on transaction commit/rollback. |
| `ActorHost` | Implicit via single-threaded actor: one actor per `(pm_class, association_id)`; FIFO mailbox naturally serializes events for the same PM instance. No explicit lock. PM emissions still go through outbox (consistent with §16.1.1) for the same atomicity reasons. |

Lock contention in `SyncHost`: failed lock acquisition throws `PMLockContentionException`; the event delivery is retried via `BackoffStrategy` (default same as outbox: `JitteredExponentialBackoff(base=1s, cap=10m, maxAttempts=10)`).

### 16.3 PM Backoff Strategies (Three Surfaces)

PMs have three distinct retry surfaces:

| Surface | What fails | Default strategy | How to override |
|---|---|---|---|
| **Inbound event handling** | The PM's `#[OnEvent]` method throws while reacting to an incoming event. | `JitteredExponentialBackoff(base: 1s, cap: 10m, maxAttempts: 10)` | `#[Retry]` on the method or `#[ProcessManager(retry: ...)]` on the class |
| **Outgoing command emission** | A command emitted by the PM fails (OCC exhausted, business validation rejected, transport failure). | `JitteredExponentialBackoff(base: 5s, cap: 30m, maxAttempts: 12)` | `#[OnCommandFailure(retry: ..., compensateAfter: int)]` on the PM class |
| **Deadline action** | A `#[OnDeadline]` method throws when the deadline fires. | `JitteredExponentialBackoff(base: 30s, cap: 1h, maxAttempts: 5)` | `#[Retry]` on the deadline method |

```php
#[ProcessManager(host: SyncHost::class)]   // or ActorHost::class — host is per-PM choice
final class OrderFulfillmentProcess extends AbstractProcessManager
{
    #[OnEvent, AssociateBy('orderId')]
    #[Retry(strategy: JitteredExponentialBackoff::class, base: '500ms', cap: '5m', maxAttempts: 8)]
    public function whenOrderPlaced(OrderPlaced $event): void
    {
        $this->scheduleDeadline('payment-timeout', Duration::minutes(15));
        ctx::tell(new ChargePayment($event->orderId, $event->total));
    }

    #[OnCommandFailure(
        retry: ExponentialBackoff::class, base: '10s', cap: '10m', maxAttempts: 6,
        compensateAfter: 6,                          // after N failed retries, compensator runs
    )]
    public function compensate(ChargePayment $failedCmd, MessageMetadata $failedMeta, \Throwable $cause): void
    {
        // $failedMeta->causationId() links back to PM state for correlation
        ctx::tell(new CancelOrder($failedCmd->orderId, reason: 'payment-failed'));
    }

    #[OnDeadline('payment-timeout')]
    #[Retry(strategy: FixedDelayBackoff::class, delay: '1m', maxAttempts: 3)]
    public function paymentTimedOut(): void
    {
        ctx::tell(new CancelOrder($this->orderId, reason: 'payment-timeout'));
    }
}
```

After all retries are exhausted, the failed event/command/deadline lands in `ddd_pm_dlq` with full causation chain and the last exception. `bin/ddd pm dlq replay --process=OrderFulfillmentProcess --association-id=X` re-injects.

PM-internal state mutations are not retried via the surfaces above — they go through the same OCC retry middleware as aggregates (§9.5). Backoff in PMs concerns the *side-effecting* surfaces.

### 16.4 PM Archival and Retention

Each `#[ProcessManager]` declares retention:

```php
#[ProcessManager(retention: RetainFor::class, days: 90)]
final class OrderFulfillmentProcess extends AbstractProcessManager { /* ... */ }
```

Retention strategies:
- `KeepForever` (default) — completed PMs stay in event store indefinitely.
- `RetainFor::days(N)` — completed PMs older than N days are archived (move to cold store).
- `SnapshotOnly` — drop history, keep latest state for audit.
- `HardDelete` — physically remove (with audit metadata logged separately).

`bin/ddd pm archive` enforces retention policies. Default is `KeepForever`; opt into shorter retention as compliance/operational requirements dictate.

## 17. Scheduling (`nexus-ddd-scheduling`, P2)

Out of scope for this umbrella spec — gets its own design. Locked principles:

- `Scheduler` for arbitrary scheduled messages (any payload type)
- `EventScheduler` specialization for events
- `DeadlineManager` for PM deadlines (uses `EventScheduler` underneath)
- Cancellation tokens are `Identifier`-typed; cancellation is idempotent
- Doctrine adapter ships in P2 alongside the engine
- Scheduler reuses outbox infrastructure for at-least-once delivery of fired messages

## 18. Async Transport Guarantees (locked)

`nexus-ddd-async` (P3) defines:

```php
interface AsyncBus
{
    public function dispatch(Envelope $envelope): void;
}

interface Consumer
{
    public function consume(): void;
    public function name(): string;
}

interface ConsumerRunner
{
    public function run(Consumer ...$consumers): never;
}
```

**Locked guarantees system-wide:**

- **Delivery: at-least-once.** Exactly-once is not offered. Handlers must be idempotent.
- **Ordering: FIFO per aggregate id.** Messages with the same `(aggregateClass, aggregateId)` partition key are delivered in enqueue order. Holds for outbox dispatch, scheduler dispatch, ACL translation. No ordering across aggregates.
- **DLQ:** after `BackoffStrategy` gives up (`Option::none()`), message moves to per-handler DLQ. Default `JitteredExponentialBackoff(base: 1s, cap: 10m, maxAttempts: 10)`. Re-injectable via `bin/ddd dlq replay --handler=X --message-id=Y`. Per-handler override via `#[Retry]`.
- **Backpressure:** consumers stop pulling when handler queue is full; transport blocks publishers (or, for outbox, just doesn't drain).
- **Replay:** `bin/ddd events replay --aggregate-id=X` re-publishes historical events for read-model rebuild. Subscribers tagged `#[ReplaySafe]` participate; others are skipped.

> **Aggregate-internal replay does NOT publish events.** When `EventSourcingStrategy::load()` replays events to reconstruct aggregate or PM state during `repo->find()` (or PM router lookup), the framework does NOT dispatch those events to the EventBus — they were dispatched at original commit time. Aggregate/PM load is purely state-reconstruction. Only the explicit operator action `bin/ddd events replay` re-publishes events, and only to `#[ReplaySafe]` subscribers.

### 18.1 Continuous Projections (`nexus-ddd-projection`, P3)

> A dedicated package + runtime pattern for long-running read-model maintenance.

The EventBus + `#[ReplaySafe]` cover the *delivery* mechanism for projections. What's needed in addition is an **operational pattern** for read-model rebuild and steady-state maintenance — the slot that Marten's `IProjectionDaemon`, Axon's `TrackingEventProcessor`, and EventStoreDB's persistent subscriptions fill in their respective frameworks.

#### 18.1.1 Projection Contract

```php
interface Projection
{
    /** Stable name; used as the position-tracking key. */
    public function name(): string;

    /** @return array<class-string<DomainEvent>> events this projection consumes */
    public function handles(): array;

    /** Apply a single event to the projection's read model, in transaction. */
    public function project(Envelope $event, ProjectionTransaction $tx): void;

    /** Optional: cold-start initialization (truncate tables, etc.) */
    public function reset(ProjectionTransaction $tx): void;
}
```

Projections are registered alongside aggregates and PMs:
```php
->projections(fn(ProjectionConfig $p) => $p
    ->register(OrderListProjection::class)
    ->register(CustomerSpendProjection::class)
)
```

#### 18.1.2 Position Tracking

The framework manages `ddd_projection_position`:
```sql
CREATE TABLE ddd_projection_position (
    projection_name VARCHAR(255) PRIMARY KEY,
    last_sequence   BIGINT       NOT NULL,
    last_applied_at TIMESTAMPTZ  NOT NULL,
    status          VARCHAR(32)  NOT NULL,        -- 'running', 'paused', 'failed', 'rebuilding'
    last_error      TEXT
);
```

Each `Projection::project()` call commits the read-model transaction AND advances `last_sequence` atomically. Crashes resume from `last_sequence`.

#### 18.1.3 Daemon Lifecycle

```bash
# Steady-state: tail the event stream from last position, apply forward
bin/ddd projection run --name=order-list

# Cold start: reset the projection, replay from sequence 0
bin/ddd projection rebuild --name=order-list

# Status: print position, status, lag
bin/ddd projection status

# Pause / resume (e.g., during deploys that change projection schema)
bin/ddd projection pause --name=order-list
bin/ddd projection resume --name=order-list
```

The daemon pulls events from the event store ordered by global `sequence_nr`; when caught up, it polls every 100ms (configurable). Per-projection metric `ddd.projection.lag_seconds{name}` exposes the gap between latest event and last-applied; ops alerts on threshold.

#### 18.1.4 Failure Handling

If `Projection::project()` throws:
1. The transaction rolls back; `last_sequence` is unchanged.
2. The daemon increments `attempts` for the failing event; sleeps per `BackoffStrategy` (default `JitteredExponentialBackoff(1s, 5min, 10)`).
3. On exhausted retries, the projection's `status` flips to `failed`; `last_error` records the exception. The daemon stops processing (does not skip the event).
4. Ops investigate, fix the projection code or the event, then `bin/ddd projection resume`.

This is intentional: a projection that silently skips events is worse than one that stops. Operations sees the alert, fixes the bug, resumes — no data loss.

#### 18.1.5 Schema Migrations and Rebuilds

When a projection's schema changes (added a new column, denormalized differently), the safe pattern is:
1. Deploy projection code with the new schema (the projection's own migration creates the new tables).
2. Run `bin/ddd projection rebuild --name=order-list` in parallel with the old projection still running.
3. When rebuild reaches caught-up status, atomically swap (drop old table; rename new to canonical). Apps automate this swap in their deployment pipeline.

#### 18.1.6 Relationship to `bin/ddd events replay`

`bin/ddd events replay --aggregate-id=X` (§18) re-publishes events to `#[ReplaySafe]` event handlers — a one-shot operation. `bin/ddd projection rebuild` is a continuous, position-tracked rebuild for a specific projection. The two are complementary:
- Use `events replay` for ad-hoc cross-handler replay (e.g., resending events to a third-party integration after a brief downtime).
- Use `projection rebuild` for resetting a specific read model with full history.

### 18.2 Replay × Idempotency Interaction (locked)

When `bin/ddd events replay` re-publishes a historical event, the event's `messageId` is the same as it was at original dispatch time — already recorded in `ddd_message_handled` for every subscriber that saw it the first time. The default idempotency middleware would therefore **skip the handler**, making replay a no-op. Wrong behavior.

**Locked resolution:** the replay command sets `headers().get('nexus.replay') = 'true'` on each republished envelope. The idempotency middleware **bypasses dedup** (does not check `ddd_message_handled`, does not insert) when this header is present. The handler runs even if the `(handler, messageId)` pair already exists in `ddd_message_handled`.

This means handlers tagged `#[ReplaySafe]` MUST be application-level idempotent — typically by using `INSERT ... ON CONFLICT DO UPDATE` on the projection table they own. The `#[ReplaySafe]` attribute semantically declares "this handler tolerates re-execution with the same input". Handlers without `#[ReplaySafe]` are skipped during replay entirely (the replay relay never enqueues them), preserving correctness for handlers that have non-idempotent side effects (sending emails, calling external APIs).

The Psalm rule `ReplaySafeIdempotentRule` warns when a handler tagged `#[ReplaySafe]` performs non-idempotent operations (e.g., calls `Mailer::send()`).

Adapter packages (`nexus-ddd-actor`, `nexus-ddd-symfony`) implement `AsyncBus`/`Consumer` against their respective transports while preserving these guarantees.

## 19. Configuration (`nexus-ddd-config`, P0)

DSL-canonical bootstrap:

```php
$ddd = NexusDdd::create($container)
    ->profile(Profile::async)                       // mandatory
    ->idGenerator(UlidGenerator::class)             // mandatory; missing => IdGeneratorNotConfiguredException
    ->clock(SystemClock::class)                     // mandatory; missing => ClockNotConfiguredException
    ->context('orders')                             // mandatory; bounded context name
    ->buses(fn(BusRegistry $b) => $b
        ->command('default', SyncCommandBus::class)
        ->command('long-running', AsyncCommandBus::class)
    )
    ->commands(fn(CommandRouter $r) => $r
        ->route(PlaceOrder::class, PlaceOrderHandler::class)
        ->route(BulkImport::class, BulkImportHandler::class, on: 'long-running')
    )
    ->queries(fn(QueryRouter $r) => $r->route(/* ... */))
    ->events(fn(EventRouter $r) => $r->subscribe(/* ... */))
    ->aggregates(fn(AggregateConfig $a) => $a->register(
        Order::class,
        EventSourcing::with($eventStore, $snapshotStore)
            ->stream(StreamStrategy::singleStream())
            ->withSnapshotStrategy(SnapshotStrategy::everyNEvents(100)),
    ))
    ->processManagers(fn(PMConfig $p) => $p
        ->register(OrderFulfillmentProcess::class, host: ActorHost::class)
    )
    ->acl(fn(AclConfig $c) => $c->translate(/* ... */))
    ->build();
```

Attribute scanner reads `#[CommandHandler]`, `#[QueryHandler]`, `#[EventHandler]`, `#[InProcess]`, `#[Event(name:, version:)]`, `#[Outbox]`, `#[AssociateBy]`, `#[Retry]`, `#[ReplaySafe]`, `#[SharedInvocation]`, `#[Idempotent]`, `#[ProcessManager]`, `#[OnEvent]`, `#[OnDeadline]`, `#[OnCommandFailure]`, `#[Sensitive]`, `#[Async]` (alias for routing to async bus), and emits DSL calls at boot or via compile-time cache.

Symfony adapter (`nexus-ddd-symfony`, P4) provides bundle config schema, compiler-pass autoconfigure, console wiring, value resolver, and Symfony Messenger transport adapter for `nexus-ddd-async`.

## 19a. Retry Budget — Composing Retry Surfaces (locked)

The framework has retry at multiple layers — OCC, PM lock contention, outbox relay, async transport, PM event handling, PM command emission, PM deadlines. Without a global cap, retries can compose multiplicatively (5 OCC × 10 PM-lock × 10 transport × ... = pathological tail latency).

**Locked:** every root request has a **global retry budget** (default 60 seconds) carried in `MessageContext`. Each retry layer consults the remaining budget before scheduling its next attempt; when the budget is exhausted, the layer gives up regardless of its own retry strategy's `maxAttempts`. The remaining budget propagates into causally-derived messages via `MessageMetadata::headers().get('nexus.retry.budget_remaining_ms')`.

```php
// Configure per-profile or per-aggregate
$ddd->retryBudget(MaxTotalRetryDuration::of(Duration::seconds(60)));

// Or per-command override
#[RetryBudget(seconds: 120)]
final class ImportLargeFile implements Command { /* long-running, can wait longer */ }

// Or unlimited (for offline / scheduled / migration commands)
#[RetryBudget(unlimited: true)]
final class MonthlyBatchJob implements Command { /* ... */ }
```

When the budget is exhausted, the message lands in DLQ with a final exception annotated `RetryBudgetExhaustedException(originalCause: ...)`. The DLQ entry preserves the entire causation chain for forensic analysis. The Psalm rule `RetryBudgetCoherenceRule` warns when a single command class declares retry strategies whose minimum total time exceeds the budget.

## 20. Error Model

| Kind | Mechanism | Examples |
|---|---|---|
| Aggregate invariant violation | `throw` (extends `NexusDddException`) | "Order cannot be shipped — already shipped" |
| Infrastructure failure | `throw` | DB down, event store unreachable |
| Framework misuse | `throw` | Missing handler, malformed routing, duplicate `eventName` |
| Optimistic-lock conflict | `throw` `OptimisticLockException` (auto-retried) | concurrent writes |
| PM lock contention | `throw` `PMLockContentionException` (auto-retried) | concurrent PM events on same association in `SyncHost` |
| Expected validation failure | `Either<L,R>` | VO factories, command preconditions, `RichSpecification::evaluate()` |

```php
$bus->dispatch($command);                  // throws on failure
$bus->tryDispatch($command): Either;       // any throwable becomes Either::left
```

Bus middleware lifts; handler authors don't manually wrap.

## 21. No-Null Rule

All public framework signatures use `Option<T>` from `fp4php/functional` for absence; `null` is forbidden in DDD code. The framework boundary may receive `null` from external libraries (Doctrine, PSR-11) and immediately lifts to `Option`.

Compound types (`Foo|Closure`) are also forbidden — use a dedicated interface or a parameter object.

The Psalm plugin's `NoNullInDddRule` flags `null` in framework-namespace signatures.

## 22. Static Analysis & Type Safety

- **Psalm Level 1** with full generics throughout.
- **Future `nexus-ddd-psalm` plugin** (independent package, ships when stable):
  - `RegisteredHandlerRule` — flag commands/queries with no registered handler at static analysis time
  - `ReadonlyMessageRule` — commands/queries/events must be `readonly` classes
  - `ImmutableValueObjectRule` — VOs must not have setters; properties `readonly`
  - `ProperPolicyTypingRule` — concrete `Policy` impls declare `TIn`/`TOut`
  - `NoNullInDddRule` — flag `null` in DDD-namespace signatures
  - `ReplaySafeApplyRule` — `applyXxx()` methods pure (no I/O, no `recordThat()`, no `tell()`)
  - `EventNameUniqueRule` — `(eventName, version)` tuples unique
  - `IdentityTypeMatchRule` — `Identifier::equals()` between different concrete types is a type error
  - `InProcessHandlerSafetyRule` — `#[InProcess]` handlers must not perform network I/O
  - `PureDomainPayloadRule` — `Command`/`Query`/`DomainEvent` must not reference `MessageMetadata` or `Envelope`
  - `TombstonedEventNotStateAffectingRule` — tombstoning a state-affecting event without a compensating upcaster is an error
  - `AmbientContextSafetyRule` — `MessageContext::current()` outside Swoole-coroutine-safe contexts is flagged
  - `ApplyMethodCoverageRule` — every recorded `DomainEvent` has a matching `applyXxx` method on the aggregate
  - `ApplyMethodAmbiguousRule` — short-name collisions across event namespaces routed to the same aggregate
  - `CommandHandlerReturnTypeRule` — **all** command handlers (sync, async, actor) must declare `: void` return type. Pure CQS — commands are intent, never request-response.
  - `CommandReturnValueIgnoredRule` — assigning `$bus->dispatch($cmd)` to a variable is flagged (the call is `void`)
  - `NoGettersSettersOnAggregateRule` — aggregates / process managers / sub-entities cannot have `get*` or `set*` methods; pure-read accessors flagged. Framework-required accessors (`id()`, `version()`, `equals()`, `isFinished()`, etc.) and `#[FrameworkAccessor]`-tagged exceptions are exempt. Tell-don't-ask enforcement.
  - `AsyncHandlerSyncDispatchRule` — async handlers cannot call `$bus->dispatch()` mid-flight expecting response (use `ctx::tell()` for deferred or `QueryBus::ask()` for synchronous reads)

## 23. Logging and Observability

### 23.1 Default Logging

The framework logs via PSR-3. Default log level INFO per dispatch, with structured fields:

```
INFO ddd.command.dispatched {
    messageType: "PlaceOrder",
    messageId: "01HXXXXXX",
    correlationId: "01HYYYYYY",
    causationId: "01HZZZZZZ",
    durationMs: 23,
    outcome: "success",
    handlerClass: "PlaceOrderHandler"
}
```

**Payload is NOT logged at INFO.** Only at DEBUG (gated, opt-in per environment).

### 23.2 PII Redaction

Domain payload fields tagged `#[Sensitive]` are excluded from any log emission:

```php
readonly class RegisterCustomer implements Command
{
    public function __construct(
        public CustomerId $id,
        #[Sensitive] public Email $email,
        #[Sensitive] public PhoneNumber $phone,
        public Country $country,
    ) {}
}
```

The framework's redactor runs before any log statement, replacing `#[Sensitive]` field values with `***` or omitting them entirely.

### 23.3 Metrics

`MetricsCollector` interface in `nexus-ddd-bus` (P0), no-op default. Adapter packages in P5 for Prometheus / StatsD / OpenTelemetry. Standard metric names (locked, prefixed `ddd.`):

```
ddd.command.count{type, outcome}
ddd.command.duration_ms{type}
ddd.command.retries{type, reason}
ddd.event.published{type, subscriber_count}
ddd.event.consumed{type, subscriber, outcome}
ddd.outbox.size
ddd.outbox.dispatch_latency_ms
ddd.dlq.depth{handler}
ddd.pm.active{class}
ddd.pm.lock_contention{class}
ddd.idempotency.duplicates{handler}
```

### 23.5 Health Checks and Readiness (locked)

The framework ships standard health surfaces for Kubernetes / load-balancer / monitoring integration:

```php
interface HealthCheck
{
    public function name(): string;
    public function check(): HealthStatus;          // OK, DEGRADED, UNHEALTHY with reason
}
```

Built-in checks (registered automatically):
- `OutboxLagHealthCheck` — UNHEALTHY if outbox `available_at < NOW() - 5min` exists
- `DlqDepthHealthCheck` — DEGRADED if any handler's DLQ exceeds threshold (configurable, default 100)
- `ReplayFailureRateHealthCheck` — DEGRADED if `ddd.aggregate.replay_failures > 0` in the last hour
- `ProjectionStatusHealthCheck` — UNHEALTHY if any projection is in `failed` status; DEGRADED if any projection's `lag_seconds > 60s` (configurable)
- `IdempotencyTableSizeHealthCheck` — DEGRADED if oldest unpartitioned row exceeds retention window (suggests GC isn't running)
- `RelayLeaseExpirationHealthCheck` — UNHEALTHY if any partition has been unowned for `> 2 × lease_timeout`

CLI access:
```bash
bin/ddd health             # JSON output of all checks; exit code 0 (OK), 1 (DEGRADED), 2 (UNHEALTHY)
bin/ddd health --check=OutboxLagHealthCheck   # single check
```

Apps register custom checks via `->healthChecks(fn(HealthRegistry $h) => $h->register(MyCheck::class))`. The Symfony bundle exposes `/ddd/health` HTTP endpoint when `nexus-ddd-symfony` is installed.

### 23.4 OpenTelemetry Integration (locked)

The framework provides bidirectional W3C Trace Context propagation:

1. **Inbound HTTP / consumer / scheduler:** the `nexus-ddd-symfony` HTTP listener and the consumer middleware extract `traceparent` and `tracestate` headers from the incoming request/message and store them in `MessageMetadata::headers()` under keys `nexus.otel.traceparent` and `nexus.otel.tracestate`. If absent, a fresh trace ID is minted.
2. **Per-handler span:** an `OpenTelemetryMiddleware` (in `nexus-ddd-bus`, no-op default; activated when `open-telemetry/sdk` is present) creates a span per command/query/event handler invocation. Span attributes include `messageType`, `messageId`, `correlationId`, `causationId`, `handlerClass`, `outcome`. Failures attach the exception.
3. **Outbound to async transport:** outbox rows persist `nexus.otel.traceparent` in their `headers` JSONB. Relay dispatch propagates the header to the consumer; the consumer reads it on receipt and resumes the trace.
4. **Outbound to external service:** when the application makes an HTTP/gRPC call from inside a handler, it should read `MessageContext::current()->headers()->get('nexus.otel.traceparent')` and inject as outbound headers. The framework provides `OtelHeaderInjector` helper.
5. **ACL preservation:** ACL translators preserve `correlationId` (§14.2) AND `nexus.otel.traceparent`/`tracestate`, so distributed traces survive bounded-context hops.

The metrics surface (§23.3) and tracing surface compose: every emitted metric carries the active trace context as exemplar (when an OTel exemplar pipeline is configured).

## 24. Testing Strategy (split across phases)

> Test-kit is split into per-phase packages aligned with the production package each tests. This avoids the phasing inconsistency where a P0 test-kit would depend on P1 production code.

| Package | Phase | Provides |
|---|---|---|
| `nexus-ddd-testkit-core` | P0 | `TestBus`, `TestClock`, `TestIdGenerator`, `MessageContextScope` (deterministic context), causation-chain assertions, `InMemoryEventStore`, `InMemoryOutbox` |
| `nexus-ddd-testkit-aggregate` | P1 | `AggregateTestFixture` (Axon-style `given/when/then`), `InMemoryStrategy`, snapshot test helpers |
| `nexus-ddd-testkit-pm` | P2 | `PMTestFixture`, deadline simulation, association-routing assertions |

Each tier depends on the corresponding production package and on the lower test-kit tier.

### 24.1 P0 Test-Kit (`nexus-ddd-testkit-core`)

```php
// TestBus — captures dispatched commands/events, replayable, causation-aware
$bus = new TestBus();
$bus->dispatch(new PlaceOrder(...));
$bus->expectCommand(PlaceOrder::class);
$bus->expectEvent(OrderPlaced::class);
$bus->expectCausation()
    ->root(PlaceOrder::class)
    ->caused(OrderPlaced::class)
    ->caused(NotifyCustomer::class);

// TestClock — controls time for scheduled events
$clock = new TestClock(new \DateTimeImmutable('2026-05-06T12:00:00Z'));
$clock->advance(Duration::minutes(5));

// TestIdGenerator — deterministic ULIDs for reproducible test IDs
$gen = new TestIdGenerator(['01HXXXXX1', '01HXXXXX2', '01HXXXXX3']);
```

### 24.2 P1 Test-Kit (`nexus-ddd-testkit-aggregate`)

```php
// AggregateTestFixture (Axon-inspired)
$fixture = AggregateTestFixture::for(Order::class);
$fixture
    ->given(new OrderPlaced(...), new OrderConfirmed(...))
    ->when(new ShipOrder(...))
    ->expectEvents(new OrderShipped(...))
    ->expectVersion(3);

// InMemoryStrategy + InMemoryEventStore = full unit-test stack
$strategy = new InMemoryStrategy();
$repo = new GenericAggregateRepository(Order::class, $strategy);
```

### 24.3 P2 Test-Kit (`nexus-ddd-testkit-pm`)

```php
// PMTestFixture — virtual-actor lifecycle assertions
$pm = PMTestFixture::for(OrderFulfillmentProcess::class);
$pm->given(new OrderPlaced(...))
   ->expectCommand(ChargePayment::class)
   ->expectDeadline('payment-timeout', within: Duration::minutes(15));

// Deadline simulation
$pm->fireDeadline('payment-timeout');
$pm->expectCommand(CancelOrder::class);

// Compensation simulation
$pm->given(new OrderPlaced(...))
   ->expectCommand(ChargePayment::class)
   ->commandFails(ChargePayment::class, new GatewayUnavailable())
   ->expectCompensation(CancelOrder::class);
```

Each module has unit tests against `InMemoryStrategy`. Integration tests run against Doctrine/DBAL via the existing nexus Docker harness.

## 25. Distributed Systems Considerations

### 25.1 Read-Your-Writes

Outbox-dispatched events arrive at projections asynchronously. After `POST /orders`, an immediate `GET /orders/123` may not see the new order if the read goes through a query handler reading a projection.

**No built-in projection-caught-up waiter in v1.** Operational implications for adopters:

| Use case | Recommended approach in v1 |
|---|---|
| "Show me what I just created" | Read from aggregate state directly via `AggregateRepository::find()` (command-side read; bypass projection) |
| "List of my orders" | Accept eventual consistency in UX (optimistic UI, polling, push notifications, WebSocket) |
| "Strict read after write across many users" | Application-level pattern: synchronous projection commit (via `#[InProcess]` handler in same transaction); aware of the constraint that synchronous projections can't span DBs |

A built-in `wait-for-projection-caught-up` primitive is deferred to v2.

### 25.2 Time and Ordering

- `MessageMetadata::timestamp()` is wall-clock from `psr/clock`. Not a logical clock.
- Cross-aggregate event ordering is **not** guaranteed by timestamp.
- Within a single aggregate, ordering is preserved by the FIFO-per-aggregate-id rule.
- Apps that need cross-aggregate ordering establish it via causation chains (`causationId`).

### 25.3 Eventual Consistency Boundary

A command's transaction commits aggregate state and outbox row atomically. Everything downstream is eventual:
- A read-model row may take 50ms, 5s, or 5min after commit.
- A process manager reacting to the event may emit compensating commands minutes later.
- A subscriber in a foreign bounded context sees the event when its consumer next pulls.

This is the contract. The framework optimizes for honest representation over the comforting fiction of synchronous determinism.

### 25.4 Hot Keys, Backpressure, Failure Domains

- **Hot aggregate id:** application-level partitioning (§12.3).
- **Outbox saturation:** if relay falls behind, outbox grows. `ddd.outbox.size` metric should alert.
- **Subscriber failure:** independent fan-out isolates one subscriber's failures. Each subscriber has its own DLQ.
- **Database failure:** source command transaction fails; nothing partially committed. Bus surfaces exception; auto-retry runs.

### 25.5 Exactly-Once Doesn't Exist Here

Exactly-once delivery is impossible without distributed 2PC. The framework explicitly does not offer it. What is offered:
- At-least-once delivery
- Idempotent handlers (framework primitive, §13)
- Effective once-per-handler outcome under correct idempotency

This is the practical "exactly-once" approximation; the framework documents it as such, not as actual exactly-once.

## 26. Adapters (P4)

`nexus-ddd-actor`:
- `ActorHost` implements `ConcurrencyHost`
- `ActorCommandBus` for actor-routed command dispatch
- Actor-hosted aggregates and process managers (single-writer per aggregate id)
- Actor-mailbox `AsyncBus` adapter (alt to outbox transport)
- Bridge between `MessageMetadata` and `nexus-core` `Envelope`

`nexus-ddd-symfony`:
- Symfony bundle (DI, autoconfigure, compiler pass, value resolver)
- Symfony Messenger consumer adapter for `nexus-ddd-async`
- Console command auto-registration

`nexus-ddd-context`:
- Anti-corruption layer scaffolding (`ContextTranslator`, registry)
- Published-language helper utilities
- Cross-context routing primitives

`nexus-ddd-idempotency-redis`:
- `RedisIdempotencyStore` for high-volume handlers

## 27. Tooling (P5)

`nexus-ddd-console`:
- Framework-agnostic CLI; each command delegates to the appropriate runner via DI

#### 27.1 CLI Command Reference (consolidated)

Full enumerated CLI surface (commands referenced throughout the spec, gathered here for adopters):

| Command | Purpose | Phase |
|---|---|---|
| `bin/ddd consume` | Run an async consumer for a named handler / queue | P3 |
| `bin/ddd route-map` | Print the canonical routing table | P0 |
| `bin/ddd config validate` | Validate config (XA constraint, profile×routing, etc.) | P0 |
| `bin/ddd schema migrate` | Run framework migrations across all packages in dependency order | P1 |
| `bin/ddd schema migrate-aggregate --type=X` | Generate per-type stream tables for a new aggregate type | P1 |
| `bin/ddd events check-versions` | Validate event upcaster chain across all aggregate types | P1 |
| `bin/ddd events replay --aggregate-id=X` | Replay an aggregate's events through the bus | P3 |
| `bin/ddd events repair --aggregate-id=X` | Manual event-store repair for corruption recovery | P3 |
| `bin/ddd snapshot rebuild --aggregate=X` | Force snapshot regeneration for an aggregate | P1 |
| `bin/ddd snapshot rebuild --pm=X` | Force snapshot regeneration for a process manager | P2 |
| `bin/ddd outbox relay` | Run the outbox relay daemon (HA via lease+heartbeat) | P3 |
| `bin/ddd outbox size` | Print current outbox row count by partition | P3 |
| `bin/ddd dlq replay --handler=X --message-id=Y` | Re-inject a DLQ message | P3 |
| `bin/ddd pm dlq replay --process=X --association-id=Y` | Re-inject a PM DLQ message | P2 |
| `bin/ddd pm archive` | Enforce PM retention policies (drop, snapshot-only, hard-delete) | P2 |
| `bin/ddd idempotency gc --older-than=30d` | Drop old idempotency partition tables | P3 |
| `bin/ddd inspect aggregate --type=Order --id=X` | Print aggregate state summary, snapshot version, event count, last commit time | P5 |
| `bin/ddd inspect pm --process=X --association=Y` | Print PM state, deadlines, last event applied, status (active/finished/failed) | P5 |
| `bin/ddd events validate --aggregate-id=X` | Check for sequence gaps, missing version-numbered events, version anomalies | P5 |
| `bin/ddd snapshot inspect --aggregate=X --id=Y` | Print snapshot age, size, stateVersion, integrity check vs last persisted version | P5 |
| `bin/ddd pm list-stuck --older-than=24h` | List PMs that started >N ago and haven't reached terminal state | P5 |
| `bin/ddd projection run --name=X` | Run continuous projection daemon | P3 |
| `bin/ddd projection rebuild --name=X` | Reset projection and replay from sequence 0 | P3 |
| `bin/ddd projection status` | List all projections with position, status, lag | P3 |
| `bin/ddd projection pause --name=X` / `resume --name=X` | Pause/resume a projection daemon | P3 |
| `bin/ddd health` | JSON output of all health checks; exit code reflects status | P5 |

`nexus-ddd-debug`:
- Web UI for routing maps (per-context view; cross-context ACL view)
- Command/event flow tracer (per-`correlationId` view, including bounded-context hops)
- PM state inspector (active PMs, deadlines, DLQ entries)
- `sync` profile mode banner (red header — not for production)
- Dev-only — gated behind a flag

## 28. Package Map

| Phase | Package | Depends on (composer) |
|---|---|---|
| P0 | `nexus-ddd-core` | `fp4php/functional`, `symfony/uid`, `psr/clock` |
| P0 | `nexus-ddd-messaging` | `nexus-ddd-core` |
| P0 | `nexus-ddd-bus` | `nexus-ddd-messaging`, `nexus-ddd-core`, `psr/log`, `psr/event-dispatcher` |
| P0 | `nexus-ddd-config` | `nexus-ddd-bus`, `psr/container` |
| P0 | `nexus-ddd-testkit-core` | `nexus-ddd-bus`, PHPUnit |
| P1 | `nexus-ddd-aggregate` | `nexus-ddd-core`, `nexus-ddd-bus`, `monadial/nexus-persistence` |
| P1 | `nexus-ddd-dbal` | `nexus-ddd-aggregate`, `doctrine/dbal` |
| P1 | `nexus-ddd-doctrine` | `nexus-ddd-aggregate`, `doctrine/orm` |
| P1 | `nexus-ddd-testkit-aggregate` | `nexus-ddd-testkit-core`, `nexus-ddd-aggregate` |
| P2 | `nexus-ddd-process-manager` | `nexus-ddd-aggregate`, `nexus-ddd-bus`, `nexus-ddd-scheduling` |
| P2 | `nexus-ddd-testkit-pm` | `nexus-ddd-testkit-aggregate`, `nexus-ddd-process-manager` |
| P2 | `nexus-ddd-scheduling` | `nexus-ddd-bus`, `nexus-ddd-core`, `psr/clock` |
| P3 | `nexus-ddd-outbox` | `nexus-ddd-messaging`, `nexus-ddd-bus`, `doctrine/dbal` |
| P3 | `nexus-ddd-async` | `nexus-ddd-bus`, `nexus-ddd-messaging` |
| P3 | `nexus-ddd-projection` | `nexus-ddd-bus`, `nexus-ddd-messaging`, `doctrine/dbal` |
| P4 | `nexus-ddd-actor` | `nexus-ddd-aggregate`, `nexus-ddd-async`, `monadial/nexus-core`, `monadial/nexus-persistence` |
| P4 | `nexus-ddd-symfony` | `nexus-ddd-bus`, `nexus-ddd-config`, `symfony/{framework-bundle,messenger,console}` |
| P4 | `nexus-ddd-context` | `nexus-ddd-bus`, `nexus-ddd-messaging` |
| P4 | `nexus-ddd-idempotency-redis` | `nexus-ddd-bus`, `predis/predis` (or `ext-redis`) |
| P5 | `nexus-ddd-console` | `nexus-ddd-async`, `nexus-ddd-scheduling`, `nexus-ddd-outbox`, `symfony/console` |
| P5 | `nexus-ddd-debug` | `nexus-ddd-bus`, `nexus-ddd-messaging` |
| P5 | `nexus-ddd-cryptoshred` | `nexus-ddd-aggregate`, `psr/cache` |

Future, separate package: `nexus-ddd-psalm` (Psalm plugin) — ships when stable.

PHP namespace: `Monadial\Nexus\Ddd\…` for every package.

**Versioning policy (locked):** mono-version with synchronized releases across all `nexus-ddd-*` packages, mirroring existing `nexus-*` mono-versioning. Inter-package compatibility guaranteed within a release.

**Total: 21 packages across 6 phases** (testkit split across P0/P1/P2; projection added P3; cryptoshred deferred to P5 as opt-in).

## 29. Phasing Roadmap

| Phase | Outcome |
|---|---|
| P0 | Minimum viable command/query/event flow with attribute+DSL config, marker interfaces, **multi-bus model with profile-validated routing**, bus entry points, error model, **causation chain propagation**, `MessageContext` (parameter + ambient), `Envelope`/payload separation, error model, **`BackoffStrategy` family**, **`IdempotencyStore` interface**, **`MetricsCollector` interface**, **logging defaults + `#[Sensitive]` redaction**, **`nexus-ddd-testkit-core` package** |
| P1 | DDD aggregates with Doctrine ORM and DBAL persistence, repositories, **OCC + retry middleware**, **`OccEventStore` contract**, **snapshotting + three-tier upgrade**, **event versioning + upcaster pipeline**, **event store stream strategies (Single + PerAggregateType, single-database constraint)**, **columnar event store schema**, **`nexus-ddd-testkit-aggregate` package** |
| P2 | Process managers (event-sourced, opened-on-demand with DB locking) and scheduling (deadlines, scheduled events), **`nexus-ddd-testkit-pm` package** |
| P3 | **Outbox with single shared table**, lease-based HA relay, async transport abstraction, idempotency table, DLQ, **continuous projection runner** |
| P4 | Actor adapter (actor-hosted aggregates and PMs, `ActorCommandBus`), Symfony bundle, ACL/context package, Redis idempotency adapter |
| P5 | CLI (consume, replay, snapshot rebuild, events check-versions, pm archive, etc.), debug UI, **crypto-shredding** |

## 30. Out of Scope (v1)

- Read-model / projection framework
- ETL / generic workflow orchestration
- HTTP/3, QUIC, file upload streaming, gRPC bridges, network bus federation
- Cross-machine actor clustering (deferred to `nexus-cluster`)
- Multi-tenant routing infrastructure (apps roll their own via `HeaderBag`)
- Built-in audit log
- Visual workflow editor / process manager designer
- Schema registry for cross-context published language
- Wait-for-projection-caught-up read primitive
- Hot-key automatic throttling
- Exactly-once delivery (impossible without 2PC)
- Cross-process actor migration
- Notify-driven outbox dispatch (`pg_notify`) — P5 enhancement
- GDPR crypto-shredding implementation (sketched; deferred to P5)
- Pattern-based event subscriptions / wildcards (e.g., `subscribe('orders.*')`) — apps register concrete event classes only
- Time-travel debugging (replay state at arbitrary historical point) — useful but large feature; v2 or never
- Field-level encryption beyond crypto-shred (transparent column encryption) — apps DIY via Doctrine encrypted types or DB-level TDE
- Change Data Capture (CDC) integration — apps integrate Debezium etc. at the DB layer, not via the framework
- Long-polling / WebSocket / SSE client subscriptions to event streams — apps build their own real-time push (Mercure, custom WebSocket gateway)
- Multi-region / cross-region replication — single-region in v1
- GraphQL integration — apps build their own resolvers using QueryBus
- Visual workflow / saga editor / process designer — defer to v2 or never
- Built-in notification framework (push, email, SMS) — apps DIY via event handlers + their notification stack
- Audit log framework — the event store IS the audit log; apps build secondary audit views with event handlers as needed
- Schema registry implementation — recipe in §14.3; future `nexus-ddd-schema-registry` package deferred
- Continuous projection runner UI — `bin/ddd projection status` CLI in v1; web UI deferred to v2
- Aggregate access-control / row-level security at the framework level — apps DIY (most use Symfony Security voters in handlers)

## 31. Open Questions (deferred to per-package designs)

- Concrete `HeaderBag` API surface (read/write semantics, namespacing, immutability rules)
- Process manager association router algorithm (in-memory hash vs SQL lookup vs dedicated index)
- Symfony bundle config schema details
- CLI command flag/output formats
- Migration story for projects already using PF's bundle (compat shims? bridge package?)
- Outbox notify-driven dispatch (`pg_notify`/equivalent)
- Specific shape of `bin/ddd events replay` filtering and projection-resume semantics

## 32. Inspirations and Differences

| Topic | PF DDD Bundle | Axon | Ecotone | Prooph | This framework |
|---|---|---|---|---|---|
| Command handlers in aggregate | No | Yes (or external) | Yes (or external) | No | **No** (locked) |
| Aggregate base | `AggregateRoot` (state-based) | `EventSourced` annotation | Attribute-driven | `AggregateRoot` | **Two bases**: `EventSourced…` + `Stateful…` |
| Process manager state | State-based | ES'd saga | Attribute-driven | State-based | **Always ES'd; "saga" term not used; opened-on-demand with locking** |
| Default event dispatch | Sync | Async via Tracking Processors | Async channels | Sync | **Outbox by default; sync opt-in** |
| Async vs sync command | Per-command | Per-handler config | Per-channel | Per-bus | **Per-bus (multi-bus model); routing config picks** |
| Event handler isolation | `IndependentHandlerEventBus` | Per-processor | Per-channel | Sync | **Independent fan-out by default** |
| Identity | UUID (`UuidValue`) | Various | String | UUID | **Pluggable `IdGenerator`** (ULID default), `CompositeIdentifier` for composites |
| VO base | `Value`, `ObjectValue` | None | None | None | **`WrappedValue` (functor) + `ObjectValue`** |
| Domain payload / metadata | Mixed | Separated | Mixed | Mixed | **Separated** (payload pure, `Envelope` carries metadata) |
| Null handling | Nullable | Nullable | Nullable | Nullable | **`Option<T>`** (no null) |
| Configuration | YAML + annotations | XML/Java config | Attributes + builder | Annotations + config | **DSL canonical + attribute sugar + Symfony YAML** |
| Serialization | JMS Serializer | Jackson | Jackson | Various | **Valinor** |
| Event versioning | Class-name based | Upcasters | Upcasters | Class-name + upcasters | **`(eventName, schemaVersion)` + upcaster pipeline + tombstones** |
| Snapshotting | None (state-based) | Configurable | None | Per-aggregate | **Mandatory in P1, default `EveryNEvents(100)`, three-tier upgrade** |
| Event store stream | One stream | One stream | Per-channel | **Configurable: single, per-type, per-instance** | **Configurable: single (default) or per-aggregate-type** |
| Event store schema | RDBMS columns | JPA mapping | Various | RDBMS columns | **Columnar with payload+headers JSONB** |
| Specification result | bool | bool | bool | bool | **bool + `RichSpecification<T>` returning `Either<Failures,T>`** |
| ACL / bounded contexts | None | Distributed | Implicit per-app | None | **`nexus-ddd-context` with explicit translators; `correlationId` preserved across boundary** |
| Idempotency | Not framework-managed | Tokens | Per-channel | Manual | **Pluggable store (Postgres / Redis / NoOp); per-handler attribute** |
| Read-your-writes | Not addressed | Tracking tokens | Not addressed | Not addressed | **Documented contract; deferred primitive in v2** |
| Outbox | Symfony Messenger transport | Built-in | Built-in | Manual | **Single shared table, context-tagged, lease-based HA relay** |

## 33. Auto-Locked Decisions (Cumulative Across All Review Rounds)

For traceability, all auto-locks applied across v3 → v3.1 → v4 → v5 → v5.1, organized by version:

### Round 2 (v3) — 18 locks

| ID | Topic | v3 Resolution |
|---|---|---|
| L1 | `MessageContext` API safety | Both: parameter-injected (preferred) and coroutine-aware ambient via `Swoole\Coroutine::getContext()`. Psalm `AmbientContextSafetyRule` flags unsafe usage. |
| L2 | ACL trace propagation | `correlationId` preserved (OTel/W3C Trace Context); `causationId` severed (set to source's `messageId`); fresh `messageId`. |
| L3 | Test-kit packaging | `nexus-ddd-testkit` as its own P0 package. |
| L4 | Composite identifiers | `CompositeIdentifier extends Identifier` with `components(): array<string,scalar>`. |
| L5 | Aggregate state versioning | `stateVersion(): int` on aggregates; `SnapshotUpcaster` pipeline parallel to event upcasters. |
| L6 | `OnCommandFailure` signature | Includes `MessageMetadata`: `compensate(Cmd, MessageMetadata, Throwable)`. |
| L7 | Snapshot upgrade tiers | Three-tier: compatibility-flag → upcaster → brute-force rebuild. |
| L8 | PM archival | `RetainFor`, `SnapshotOnly`, `HardDelete`, default `KeepForever`. |
| L9 | Schema migration ownership | Each package ships its own Doctrine migrations; `bin/ddd schema migrate` runs in dependency order. |
| L10 | GDPR crypto-shred | Sketched as P5 (`nexus-ddd-cryptoshred`); `#[CryptoShred(keyDerivedFrom:)]` attribute pattern. |
| L11 | Sub-aggregate vocabulary | Documented in §6.4.1 — Axon-style `@AggregateMember` pattern. |
| L12 | Tombstone-state rule | Forbidden via Psalm `TombstonedEventNotStateAffectingRule`. |
| L13 | Read-your-writes | Operational guidance in §25.1 (read from aggregate state; accept eventual UX; in-tx projection). |
| L14 | Profile/axis override surface | Documented in §4.2 — profiles are presets; specific routing and per-aggregate axes configurable after profile selection. |
| L15 | `PersistenceId` mapping | `(aggregateClassFQN, identifier->value())` at strategy boundary. |
| L16 | Logging defaults | INFO with metadata only; DEBUG with payload (gated); `#[Sensitive]` redaction. |
| L17 | `MetricsCollector` interface | In `nexus-ddd-bus` (P0); no-op default; adapters in P5. |
| L18 | Outbox relay HA | Lease + heartbeat partition ownership; 30s lease timeout, 10s heartbeat; partition-rebalance on owner death. |

### Round 3 (v3.1) — 6 locks

| ID | Topic | Resolution |
|---|---|---|
| L19 | `dispatchApply`/`applyXxx` convention | Method name = `apply` + event short class name; reflection cached at boot; `ApplyMethodNotFoundException` at boot validation; cross-namespace short-name collisions throw `ApplyMethodAmbiguousException`. (§6.4.1) |
| L20 | Test-kit phasing | Split into `nexus-ddd-testkit-core` (P0), `nexus-ddd-testkit-aggregate` (P1), `nexus-ddd-testkit-pm` (P2). 20 packages total. |
| L21 | Per-type stream + outbox | All event tables and shared outbox MUST live in same database; XA configurations rejected at boot via `XAConfigurationException`. (§11.3) |
| L22 | OCC at EventStore contract | `OccEventStore extends EventStore` with `appendIfVersion()`; existing `nexus-persistence` `EventStore` preserved unchanged. (§9.2.1) |
| L23 | Pure-CQS command bus | `CommandBus::dispatch()` returns `void` always; `tryDispatch(): Either<Throwable, Identifier>` for tracking. (§8.1.1, §8.6) |
| L24 | Profile × routing validation | Boot fails with `BusNotAvailableInProfileException` when route specifies bus not available in profile. (§8.2.1) |

### Round 3 follow-up (v4) — 23 locks

| ID | Topic | Resolution |
|---|---|---|
| L25 | PMs as distinct type | `AbstractProcessManager` is its own base class, NOT extending `EventSourcedAggregateRoot`. (§16.0) |
| L26 | PM emissions via outbox | `ctx::tell()` from PM defers via outbox; `PMSyncDispatchRule` Psalm enforcement. (§16.1.1) |
| L27 | `OccEventStore` universal | `EventSourcingStrategy` always uses it (incl. actor mode where check is no-op-equivalent). (§9.2.1) |
| L28 | `#[InProcess]` × `#[SharedInvocation]` | Orthogonal; explicit four-combination table. (§11.2.1) |
| L29 | Per-context isolation DSL | `->isolatedInfrastructure(connection:, eventStorePrefix:, ...)`. (§14.1) |
| L30 | `MessageContext` lifecycle | Stack-based, nested dispatches push child, destroyed on outermost return. (§7.3) |
| L31 | Domain/application boundary | `ctx::*` calls in aggregates/VOs/specs/policies forbidden; `DomainContextLeakRule`. (§3 + §7.3) |
| L32 | Outbox row-volume | Daily range partitioning default; per-subscriber tables opt-in. (§11.3.1) |
| L33 | Lease-expiry polling | 5s with ±1s jitter; recovery latency bounded ~36s. (§11.4) |
| L34 | Aggregate ID column length | `withIdColumnLength()` per-aggregate override. (§9.4) |
| L35 | Sub-entity hydration | Public/protected constructor or `#[StaticFactory]`; `EntitySnapshotHydrationRule`. (§6.4.2) |
| L36 | `CompositeIdentifier` canonical format | `:` separator, URL-encoded values, deterministic. (§6.1) |
| L37 | PHP generics caveat | Documented for `Specification`/`Policy` templates. (§6.5) |
| L38 | Reverse upcasters | Not supported; forward-only pipeline. (§10.2) |
| L39 | Cross-aggregate-type query mitigation | `ddd_event_index` parallel index. (§9.3) |
| L40 | `IdempotencyKey::for(Envelope)` | Signature locked to envelope, not raw payload. (§13.3) |
| L41 | Redis TTL contract | Default 30 days; longer than max DLQ retry window. (§13.1) |
| L42 | OpenTelemetry bidirectional propagation | Extract inbound, inject outbound, span per handler, ACL preserves trace. (§23.4) |
| L43 | Global retry budget | `MaxTotalRetryDuration` (default 60s); per-command `#[RetryBudget]`; `RetryBudgetExhaustedException`. (§19a) |
| L44 | Profile-mixing implications | Actor system is process-wide; capacity-planning notes. (§4.2) |
| L45 | Doctrine ORM OCC translation | Strategy translates Doctrine's exception to framework's `OptimisticLockException`. (§9.5) |
| L46 | `#[InProcess]` same-DB constraint | `InProcessHandlerSameDbRule` Psalm enforcement. (§11.2) |
| L47 | Bus-name typo validation | `BusNameNotRegisteredException` at boot. (§8.2.1) |

### Round 4 (v5) — 18 locks

| ID | Topic | Resolution |
|---|---|---|
| L48 | `EventSourceable` interface unification | Both `AggregateRoot` and `AbstractProcessManager` implement; `PersistenceStrategy::persist(EventSourceable)`. (§6.3, §9.2) |
| L49 | `CommandEmissionFailed` system event | Wires PM compensation; PMs auto-subscribe; apps may subscribe. (§16.1.2) |
| L50 | Compensating commands fresh budget | Default fresh budget; `#[RetryBudget(inherited: true)]` opt-in to inherit. (§16.1.3) |
| L51 | Aggregate creation pattern | Static factory + upsert save; OCC + unique-id constraint. (§9.1.1) |
| L52 | Replay failure recovery | Throws `ReplayFailedException`; aggregate unloadable until fixed; metric. (§6.4.0) |
| L53 | `OrderRepository` example fix | `inBatch()` for command-side bulk; lists go through QueryBus. (§9.1) |
| L54 | `CommandBus` API simplified | Two methods: `dispatch(): void`, `tryDispatch(): Either<Throwable,Identifier>`. (§8.6) |
| L55 | PM emissions terminology | "outbox" = abstract dispatch-deferral; transport varies by profile. (§16.1.1) |
| L56 | Per-type/per-context table naming | `{context_prefix}ddd_events_{aggregate_short}`; 63-char Postgres limit. (§9.3) |
| L57 | ACL `translate(): iterable<object>` | 0..N output; cross-context fan-out at-least-once + idempotent. (§14.2) |
| L58 | PM snapshot strategy | Default `SnapshotStrategy::none()`; long-lived PMs opt in. (§16.0) |
| L59 | Causation through `#[InProcess]`/`#[SharedInvocation]` | Explicit in propagation table. (§7.4) |
| L60 | Identity hierarchy | `Identifiable` / `Entity` / `EventSourceable`; PM is `Identifiable` not `Entity`. (§6.3) |
| L61 | Profile ⊃ Host | Profile is preset; richer than just host choice. (§4.2) |
| L62 | Clock + IdGenerator mandatory | Boot fails on missing. (§19) |
| L63 | CLI command reference | Consolidated table at §27.1 |
| L64 | Sub-entity readonly | `SubEntityImmutabilityRule` Psalm enforcement. (§6.3) |
| L65 | Test-kit PM compensation | `commandFails(...)->expectCompensation(...)`. (§24.3) |

### Round 5 (v5.1) — 5 locks

| ID | Topic | Resolution |
|---|---|---|
| L66 | Replay failure type | Throws `ReplayFailedException`; `load()` keeps `Option<T>` signature. (§6.4.0) |
| L67 | Postgres partitioned PK | `PRIMARY KEY (handler_class, message_id, handled_at)` for declarative partitioning. (§13.1) |
| L68 | `Identifier::fromString()` factory | Static factory for stored-value rehydration. (§6.1) |
| L69 | PM events separate table | `ddd_pm_events`, distinct from `ddd_events`; mirrors schema. (§16.1.4) |
| L70 | Schema column terminology | `aggregate_*` columns read as "event-sourced entity's *" semantically. (§9.4) |

---

**Next steps after approval:**
1. Brainstorm `nexus-ddd-core` (P0) — `Identifier`/`CompositeIdentifier`, VO/Entity/AggregateRoot, `Specification`/`RichSpecification`/`Policy`, `Option`/`Either` integration, `BackoffStrategy` concrete shapes, Psalm rule signatures.
2. Brainstorm `nexus-ddd-messaging` (P0) — `MessageMetadata`/`HeaderBag`/`Envelope`/`MessageContext` API surface (both injection and ambient).
3. Brainstorm `nexus-ddd-bus` (P0) — middleware pipeline, multi-bus routing, OCC retry middleware, idempotency middleware, `IdempotencyStore` interface, logging redactor, metrics interface, test-bus.
4. Brainstorm `nexus-ddd-config` (P0) — attribute scanner, DSL builder, Symfony hooks, merge order.
5. Brainstorm `nexus-ddd-testkit` (P0) — `AggregateTestFixture`, `TestBus`, `TestClock`, `PMTestFixture`.

Each becomes its own design spec → implementation plan → implementation pass.
