# nexus-ddd-messaging — Design Spec

**Status:** Draft (post-brainstorm), awaiting user sign-off before plan-writing
**Date:** 2026-05-07
**Depends on:** `nexus-ddd-core`
**Inputs:** brainstorm Q1–Q6 from 2026-05-07 conversation (six locked decisions)
**Consumed by:** `nexus-ddd-process-manager` (already spec'd, plan written), `nexus-ddd-aggregate` (future), `nexus-ddd-cqrs` (future), all bus-implementation packages (Symfony Messenger adapter, actor-based bus, in-process pipeline bus)

---

## 1. Goals & non-goals

### Goals

Define the **contracts** every Nexus DDD application uses to express commands, queries, domain events, message metadata, handler shapes, message-staging units of work, and retry primitives. Provide **just enough** infrastructure (in-memory implementations of the staging contracts) for tests and single-process runtimes. Stay **bus-implementation-free** so that adapters (Symfony Messenger, actor-based, etc.) can be added without touching this package.

### Non-goals

- **Bus implementations.** No CommandBus / QueryBus / EventBus *implementations* ship here — only the interfaces. Default implementations live in adapter packages (`nexus-ddd-bus`, `nexus-ddd-messenger-symfony`, `nexus-ddd-bus-actor`).
- **Middleware abstraction.** No `Middleware` interface or pipeline base class. Each bus implementation chooses its own middleware/decorator story (Symfony Messenger has stamps + middleware; the actor adapter wires retry/idempotency at the actor layer; the in-process pipeline bus may invent its own).
- **Handler resolution mechanism.** The bus implementations decide how handlers are looked up (container, registry, attribute scan, reflection). This package only declares the handler markers and the `__invoke(ConcreteMessage, MessageContext)` convention.
- **Outbox table schema** — that's the persistence package's concern.

---

## 2. Package boundaries

```
                    nexus-ddd-core
                         ▲
                         │
              nexus-ddd-messaging
                         │
                ┌────────┼────────┐
                ▼        ▼        ▼
       psr/event-       symfony/  monadial/
        dispatcher      uid       php-duration
        (PSR-14)        (ULID)    (FiniteDuration)

  Future consumers (depend on this package):
    - nexus-ddd-process-manager
    - nexus-ddd-aggregate
    - nexus-ddd-cqrs
    - nexus-ddd-eventsourcing
    - bus adapters: nexus-ddd-bus, nexus-ddd-messenger-symfony, nexus-ddd-bus-actor
```

**Runtime dependencies:** `nexus-ddd-core`, `psr/event-dispatcher`, `symfony/uid`, `monadial/php-duration`.

**Forbidden** (Deptrac `forbidden_imports`): `Symfony\\` (except via PSR — wired by consumers), `Laravel\\`, `Illuminate\\`, `Monolog\\`, `Doctrine\\`. Same PSR-everywhere rule the package's CLAUDE.md established.

**Why these deps?**
- `nexus-ddd-core` — Identifier, DomainEvent, PublishableDomainEvent, exception roots
- `psr/event-dispatcher` — for any cross-cutting framework events (kept available even though primarily consumed by downstream packages)
- `symfony/uid` — `MessageId extends UlidValue` reuses the framework's identifier pattern
- `monadial/php-duration` — `FiniteDuration` for retry-strategy delays

---

## 3. Core contracts

### Marker interfaces — no shared parent

Three message-kind markers, each with its own bus interface. **No `Message` parent interface** — the brainstorm explicitly chose Option A from Q5 (three separate buses, no shared parent). The verbal split (`dispatchCommand` vs `publishEvent`) reinforces the distinction at every call site.

```php
namespace Monadial\Nexus\Ddd\Messaging;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Imperative message — a request that something be done. Commands target
 * exactly ONE handler. Failures propagate as exceptions; the bus contract
 * is `void` because async dispatch cannot surface a synchronous failure.
 *
 * Convention (enforced by `nexus-psalm`): concrete commands are
 * `final readonly class`.
 */
interface Command {}

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @template TResult
 *
 * Interrogative message — a request for information. Queries return a
 * typed result; `Query<TResult>` declares the result type at the call
 * site so the QueryBus's return inference works.
 *
 *   /** @implements Query<UserDto> *\/
 *   final readonly class FindUserById implements Query {
 *       public function __construct(public UserId $id) {}
 *   }
 *
 * Convention (enforced by `nexus-psalm`): concrete queries are
 * `final readonly class`.
 */
interface Query {}
```

`DomainEvent` and `PublishableDomainEvent` already live in `nexus-ddd-core` — this package does not redeclare them.

### Bus interfaces — three separate, type-specialized

```php
interface CommandBus {
    /**
     * Dispatch a command to its (single) handler. Returns void — the
     * post-handler outcome flows out via events / queries; idempotency
     * and retry are bus-impl concerns.
     */
    public function dispatchCommand(Command $command): void;
}

interface QueryBus {
    /**
     * @template TResult
     * @param Query<TResult> $query
     * @return TResult
     */
    public function dispatchQuery(Query $query): mixed;
}

interface EventBus {
    /**
     * Broadcast a domain event to all subscribed listeners.
     * "Publish" verb (not "dispatch") matches the broadcast semantics —
     * the publisher does not know who listens.
     */
    public function publishEvent(DomainEvent $event): void;
}
```

Method names spell out the *kind* of message — `$bus->dispatchCommand(...)` is greppable, distinct from `$bus->publishEvent(...)`. Adapters that wrap a single underlying transport (e.g., a Symfony Messenger adapter) implement all three by holding the configured-bus instance and casting at the seam.

### Handler interfaces — marker only, validated by Psalm

```php
/**
 * @psalm-api
 *
 * Marker for command handlers. Implementers declare:
 *
 *   public function __invoke(ConcreteCommand $command, MessageContext $ctx): void
 *
 * The `nexus-psalm` plugin's `CommandHandlerSignatureRule` enforces the
 * `__invoke` shape; PHP variance won't let us put it on the interface.
 */
interface CommandHandler {}

/**
 * @psalm-api
 *
 * @template TResult
 *
 * Marker for query handlers. Implementers declare:
 *
 *   public function __invoke(ConcreteQuery $query, MessageContext $ctx): TResult
 *
 * Validated by `QueryHandlerSignatureRule`.
 */
interface QueryHandler {}

/**
 * @psalm-api
 *
 * Marker for event listeners. Implementers declare:
 *
 *   public function __invoke(ConcreteEvent $event, MessageContext $ctx): void
 *
 * Validated by `EventListenerSignatureRule`. Multiple listeners per event
 * type are allowed (broadcast semantics).
 */
interface EventListener {}
```

**Why marker-only.** PHP cannot enforce *parameter contravariance narrowing* on an interface — declaring `handle(Command $cmd)` would force every implementation to accept any `Command`, defeating type safety. Each `__invoke` carries the *concrete* message type as its parameter, the type system enforces the right type at the dispatch seam, and the Psalm plugin checks the shape at compile time.

This mirrors the Symfony Messenger handler convention exactly, so a future Symfony adapter is straightforward.

---

## 4. Envelope, MessageMetadata, Stamps, MessageContext

The **Option C hybrid** from brainstorm Q4: typed core metadata + Symfony-style stamp extension.

### `MessageId`

```php
namespace Monadial\Nexus\Ddd\Messaging\Identity;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Framework-internal identifier for messages on the bus. Uniformly ULID-
 * backed (sortable + globally unique without a coordinator). Distinct from
 * domain identifiers — domain code creates `OrderId extends UlidValue`;
 * the framework creates `MessageId` directly.
 */
final readonly class MessageId extends UlidValue {
    public static function generate(): self {
        return new self((new Ulid())->toBase32());
    }
}
```

### `MessageMetadata`

```php
/**
 * @psalm-api
 * @psalm-immutable
 *
 * Required metadata on every Envelope. The fields are non-negotiable
 * because they're load-bearing for audit trails (causation), tracing
 * (correlation/conversation), idempotency (id), schema evolution
 * (schemaVersion), and security/audit (actor).
 *
 * Anything *not* in this list lives in a Stamp.
 */
final readonly class MessageMetadata {
    public function __construct(
        public MessageId $id,
        public DateTimeImmutable $occurredAt,
        public ?MessageId $causationId = null,
        public ?MessageId $correlationId = null,
        public ?MessageId $conversationId = null,
        public ?string $actor = null,
        public int $schemaVersion = 1,
    ) {}

    /** Derive a new metadata for a *caused* message. Causation propagates. */
    #[\NoDiscard('the derived metadata is the entire point of this call')]
    public function deriveCaused(MessageId $newId, DateTimeImmutable $now): self {
        return new self(
            id: $newId,
            occurredAt: $now,
            causationId: $this->id,
            correlationId: $this->correlationId ?? $this->id,
            conversationId: $this->conversationId ?? $this->id,
            actor: $this->actor,
            schemaVersion: $this->schemaVersion,
        );
    }
}
```

`deriveCaused()` is the canonical way to thread metadata through nested dispatches: a saga that receives `OrderPlaced` (id=A) and dispatches `ChargePayment` calls `$incomingMeta->deriveCaused($newCmdId, $clock->now())` so causation = A, correlation = the original conversation root.

### `Stamp` + `Envelope`

```php
/**
 * @psalm-api
 * @psalm-immutable
 *
 * Marker for transport/cross-cutting metadata extensions. Stamps cover
 * the long tail (serialization, retry counter, transport id, bus name,
 * dispatch attempt) that doesn't belong in the typed `MessageMetadata`.
 */
interface Stamp {}

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @template TMessage of object
 *
 * Wraps a message with its metadata + transport stamps. The brainstorm
 * settled on a generic `TMessage of object` (not `Message`) — the Envelope
 * is transport-shaped, not domain-shaped, and a Symfony adapter must be
 * able to wrap any object.
 */
final readonly class Envelope {
    /**
     * @param TMessage $message
     * @param array<class-string<Stamp>, Stamp> $stamps  keyed by stamp class
     */
    public function __construct(
        public object $message,
        public MessageMetadata $metadata,
        public array $stamps = [],
    ) {}

    /** @return self<TMessage> with the stamp added (or replacing same-class). */
    #[\NoDiscard('with() returns a new envelope; ignoring it loses the stamp')]
    public function with(Stamp $stamp): self {
        $next = $this->stamps;
        $next[$stamp::class] = $stamp;
        return new self($this->message, $this->metadata, $next);
    }

    /**
     * @template S of Stamp
     * @param class-string<S> $stampClass
     * @return S|null
     */
    public function get(string $stampClass): ?Stamp {
        return $this->stamps[$stampClass] ?? null;
    }
}
```

### `MessageContext`

```php
/**
 * @psalm-api
 * @psalm-immutable
 *
 * What every handler receives as its second `__invoke` parameter.
 *
 * Pure value object — no behavior. Handlers that want to dispatch nested
 * messages inject the bus(es) they need via constructor; the metadata in
 * the context lets them propagate causation correctly via
 * `MessageMetadata::deriveCaused()`.
 *
 * Stamps are passed through alongside metadata so middleware-aware
 * handlers can read transport-level information without coupling to the
 * Envelope shape.
 */
final readonly class MessageContext {
    public function __construct(
        public MessageMetadata $metadata,
        /** @var array<class-string<Stamp>, Stamp> */
        public array $stamps = [],
    ) {}

    /**
     * @template S of Stamp
     * @param class-string<S> $stampClass
     * @return S|null
     */
    public function stamp(string $stampClass): ?Stamp {
        return $this->stamps[$stampClass] ?? null;
    }
}
```

---

## 5. Retry primitives

These were temporarily in `nexus-ddd-core` and removed during the four-expert review. They land here, where they belong (retry timing is a delivery-layer concern that the messaging package owns).

### `BackoffStrategy` family

```php
namespace Monadial\Nexus\Ddd\Messaging\Retry;

/**
 * @psalm-api
 *
 * Decides whether and how long to wait before retrying a failed message
 * dispatch. Returns `Some<FiniteDuration>` to retry after the duration;
 * `None` to give up.
 */
interface BackoffStrategy {
    /**
     * @return Option<FiniteDuration>
     */
    public function delayFor(int $attempt, Throwable $cause): Option;
}

/** Never retry — `delayFor` always returns None. */
final class NoRetry implements BackoffStrategy { ... }

/** Constant delay between retries. */
final class FixedDelayBackoff implements BackoffStrategy { ... }

/** delay = base * attempt. */
final class LinearBackoff implements BackoffStrategy { ... }

/** delay = base * multiplier^attempt, capped at max. */
final class ExponentialBackoff implements BackoffStrategy { ... }

/** ExponentialBackoff with random jitter to prevent thundering-herd. */
final class JitteredExponentialBackoff implements BackoffStrategy { ... }

/** User-supplied closure: `(int $attempt, Throwable $cause): Option<FiniteDuration>`. */
final class CustomBackoff implements BackoffStrategy { ... }
```

These are the same six strategies that lived briefly in core. Same shape, same behavior.

### `RetryPolicy` + `RetryPolicyBuilder`

```php
/**
 * @psalm-api
 *
 * Maps exception class → BackoffStrategy. The first matching mapping
 * (in declaration order) wins. Implements `BackoffStrategy` itself so it
 * can be used wherever a strategy is expected.
 */
final readonly class RetryPolicy implements BackoffStrategy {
    public function __construct(
        /** @var array<class-string<Throwable>, BackoffStrategy> */
        public array $handlers,
        /** @var array<class-string<Throwable>, true> */
        public array $giveUpSet,
    ) {}

    /** @return Option<FiniteDuration> */
    public function delayFor(int $attempt, Throwable $cause): Option { ... }
}

final class RetryPolicyBuilder {
    #[\NoDiscard(...)]
    public static function create(): self;

    #[\NoDiscard(...)]
    public function onException(string $exceptionClass, BackoffStrategy $strategy): self;

    #[\NoDiscard(...)]
    public function giveUpOn(string $exceptionClass): self;

    #[\NoDiscard(...)]
    public function build(): RetryPolicy;
}
```

### Transient vs terminal exception axis

Per Udi's review — the bus needs to know which failures are retriable vs which mean "drop or DLQ":

```php
/**
 * @psalm-api
 *
 * Marker for exceptions the bus SHOULD retry. Implementers signal "this
 * failure is transient — try again with backoff." Without this marker,
 * the default RetryPolicy treats unknown exceptions as terminal.
 *
 * Bus implementations check `instanceof TransientFailure` and consult the
 * RetryPolicy; non-transient failures route to the DLQ immediately.
 */
interface TransientFailure {}

/**
 * @psalm-api
 *
 * Marker for exceptions that MUST NOT be retried. Use for permanent
 * failures (validation errors, type mismatches, missing handler).
 * Bus implementations route these to the DLQ on first failure.
 */
interface TerminalFailure {}
```

`DomainException` (from core) does not implement either by default — domain rule violations are usually terminal but the bus impl decides per-policy. Concrete framework exceptions implement the appropriate marker.

---

## 6. `MessageStaging` & `UnitOfWork` — shared with PMs and aggregates

**The architect's resolution from the PM spec is now executed:** these contracts live in `nexus-ddd-messaging` (shared), not in `nexus-ddd-process-manager`. Both PMs and aggregates need post-commit dispatch; both stage commands/events the same way; one shape avoids divergence.

```php
namespace Monadial\Nexus\Ddd\Messaging\Staging;

/**
 * @psalm-api
 *
 * Buffer for messages a domain object (PM, aggregate) wants to dispatch
 * after the surrounding transaction commits.
 *
 * Staging buffers commands, events, and (for PMs) deadline operations.
 * The shape is deliberately concrete — adapters extend the interface
 * with their own appendXxx methods if they stage additional kinds.
 */
interface MessageStaging {
    public function appendCommand(Command $command): void;
    public function appendEvent(DomainEvent $event): void;
    public function flush(): void;     // post-commit — buses invoked
    public function discard(): void;   // post-rollback — buffer cleared
}

interface UnitOfWork {
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function staging(): MessageStaging;
}
```

### Default in-package implementations

Ship `InMemoryMessageStaging` + `InMemoryUnitOfWork` in this package. They're sufficient for tests, single-process Fiber runtimes, and applications that don't need DB-backed outboxes. Both pass the abstract `MessageStagingContractTest`.

```php
final class InMemoryMessageStaging implements MessageStaging {
    /** @var array<int, Command> */
    private array $commands = [];

    /** @var array<int, DomainEvent> */
    private array $events = [];

    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly EventBus $eventBus,
    ) {}

    public function appendCommand(Command $command): void { $this->commands[] = $command; }
    public function appendEvent(DomainEvent $event): void { $this->events[] = $event; }

    public function flush(): void {
        foreach ($this->commands as $cmd) {
            $this->commandBus->dispatchCommand($cmd);
        }
        foreach ($this->events as $evt) {
            $this->eventBus->publishEvent($evt);
        }
        $this->commands = [];
        $this->events = [];
    }

    public function discard(): void {
        $this->commands = [];
        $this->events = [];
    }
}
```

The PM package extends `MessageStaging` with `appendDeadlineOperation(DeadlineOperation $op): void` (PMs need it; aggregates don't). The base contract stays minimal.

### `MessageStagingContractTest` — abstract

```php
namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

/**
 * Shared test class. Every MessageStaging implementation MUST extend this
 * and pass every test. Pins the four invariants:
 *
 *   1. discard() after appendCommand() → CommandBus never invoked
 *   2. flush() after appendCommand() → CommandBus invoked exactly once per command
 *   3. flush() after appendEvent() → EventBus invoked exactly once per event
 *   4. FIFO ordering preserved across staging cycles
 */
abstract class MessageStagingContractTest extends TestCase {
    abstract protected function createStaging(CommandBus $cmdBus, EventBus $evtBus): MessageStaging;
    // ... shared test methods that subclasses inherit
}
```

`InMemoryMessageStagingTest` extends this; the future `OutboxMessageStagingTest` (in downstream persistence package) does too.

---

## 7. Exception taxonomy

```php
namespace Monadial\Nexus\Ddd\Messaging\Exception;

/**
 * @psalm-api
 *
 * Root for messaging-layer faults. Distinct from `NexusDddException` (core
 * framework wiring) and `DomainException` (business rule violations) —
 * messaging failures are runtime delivery faults, neither of those.
 *
 * Catching `MessagingException` traps bus/handler/serialization issues
 * without accidentally swallowing domain rules or core wiring bugs.
 */
abstract class MessagingException extends RuntimeException {}

final class HandlerNotFoundException extends MessagingException implements TerminalFailure { ... }
final class DuplicateCommandHandlerException extends MessagingException implements TerminalFailure { ... }
final class MessageDispatchException extends MessagingException { ... }
final class MessageRejectedException extends MessagingException implements TerminalFailure { ... }
final class StagingClosedException extends MessagingException { ... }
```

Three roots are now disjoint by design:
- `NexusDddException` — framework wiring (e.g. ApplyMethod-not-found in core)
- `DomainException` — business rule violations
- `MessagingException` — runtime delivery faults

Each root extends `RuntimeException` directly (not each other). Disjointness is enforced by a test (mirroring the core `ExceptionHierarchyTest` pattern).

---

## 8. PSR-first dependency policy

Same rule as `nexus-ddd-process-manager` (and the monorepo CLAUDE.md):

| Concern | Contract | Notes |
|---|---|---|
| Logger | `Psr\Log\LoggerInterface` (PSR-3) | Bus impls inject for handler diagnostics |
| Container / DI | `Psr\Container\ContainerInterface` (PSR-11) | Bus impls use it for handler resolution (this package only declares the contracts; downstream impls use the container) |
| Event dispatcher | `Psr\EventDispatcher\EventDispatcherInterface` (PSR-14) | For framework-internal events (none in this package directly, but the contract is stable) |
| Clock | `Psr\Clock\ClockInterface` (PSR-20) | `MessageMetadata` timestamps; test-injectable |

**No `symfony/*`, `laravel/*`, `monolog/*`, `doctrine/*` runtime deps.** Adapters live in dedicated `nexus-*-adapter-*` packages. Build-failing Deptrac `forbidden_imports` rule.

---

## 9. Fitness functions (CI-enforced)

**Deptrac layer `DddMessaging`:**

```yaml
- name: DddMessaging
  collectors:
    - type: directory
      value: packages/nexus-ddd-messaging/src/.*

ruleset:
  DddMessaging:
    - DddCore

forbidden_imports:
  DddMessaging:
    - regex: ^Symfony\\.*
    - regex: ^Laravel\\.*
    - regex: ^Illuminate\\.*
    - regex: ^Monolog\\.*
    - regex: ^Doctrine\\.*
```

**Psalm rules** (in `nexus-psalm` plugin):

| Rule | Enforces |
|---|---|
| `CommandHandlerSignatureRule` | Implementers of `CommandHandler` declare `__invoke(ConcreteCommand, MessageContext): void` |
| `QueryHandlerSignatureRule` | Implementers of `QueryHandler` declare `__invoke(ConcreteQuery, MessageContext): TResult` matching `Query<TResult>` |
| `EventListenerSignatureRule` | Implementers of `EventListener` declare `__invoke(ConcreteEvent, MessageContext): void` |
| `ReadonlyMessageBodyRule` | Concrete `Command` and `Query` classes are `final readonly class` (mirrors core's `ReadonlyMessageRule` for `DomainEvent`) |
| `OneCommandHandlerRule` | A given concrete `Command` class has exactly one implementer of `CommandHandler` (commands are point-to-point) |

**PHPUnit reflection / contract tests:**
- Three-root exception disjointness test
- `MessageMetadata::deriveCaused` propagates correlation/conversation correctly
- `Envelope::with()`/`get()` round-trip stamps
- `RetryPolicy` first-match-wins and giveUpSet precedence
- `MessageStagingContractTest` — both `InMemoryMessageStaging` and any future impl pass

---

## 10. v1 deliverables

Beyond code:

- **Spec doc** (this file) committed to `docs/superpowers/specs/`
- **All Psalm rules** in §9 added to the `nexus-psalm` plugin
- **Deptrac layer + forbidden_imports** rule
- **`MessageStagingContractTest`** abstract test class (Support/)
- **`InMemoryMessageStaging`** + **`InMemoryUnitOfWork`** with full test coverage

---

## 11. Out of scope for v1

- Bus implementations (Symfony Messenger adapter, in-process pipeline bus, actor-based bus — separate packages)
- Outbox table schema and DB-backed staging (downstream persistence package)
- Middleware abstraction (each bus impl decides)
- Handler resolution mechanism (bus impl's concern)
- Serialization of envelopes (when needed, via `nexus-serialization` integration in adapter packages)
- DLQ implementation (each bus impl decides where DLQ lives)

---

## 12. Sign-off

All six brainstorm decisions (Q1–Q6) were locked during the 2026-05-07 conversation. This spec transcribes them into a contract document.

Open items the user must confirm:

- [ ] **`MessageStaging`/`UnitOfWork` here** (not in `nexus-ddd-process-manager`) — the architect's resolution from the PM-spec review. Confirm.
- [ ] **In-memory staging implementations ship here** with `MessageStagingContractTest`. Confirm.
- [ ] **`TransientFailure` / `TerminalFailure` markers** on exceptions to drive retry decisions. Udi flagged the axis; this spec adds the markers. Confirm.
- [ ] **Three exception roots** (`NexusDddException`, `DomainException`, `MessagingException`) — disjoint, each extends `RuntimeException` directly. Confirm.
- [ ] **`MessageId extends UlidValue`** as the framework-internal id type. Confirm.
- [ ] **`MessageMetadata::deriveCaused()`** as the canonical causation-propagation method on the metadata VO itself (rather than a separate helper). Confirm.

After sign-off → invoke `superpowers:writing-plans` to produce the implementation plan. After messaging plan is written and (optionally) executed, the `nexus-ddd-process-manager` plan's Phase 2 (temporary `Contract/Messaging` stubs) is replaced with a real `nexus-actors/ddd-messaging` composer dependency.
