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

### Bus interfaces — strictly single-argument

```php
interface CommandBus {
    /**
     * Dispatch a command to its (single) handler. The bus internally
     * wraps the message in an Envelope, generating a fresh `MessageId`
     * and reading metadata from the ambient `CurrentMessageContext` (§5)
     * for causation/correlation propagation.
     *
     * `MessageId` is generated when the message is *created* (here, at
     * dispatch — the moment the framework first sees a fresh raw
     * Command) or *deserialized* (when an envelope comes back from the
     * wire — see `EnvelopedCommandBus` below). Domain code never thinks
     * about `MessageId`; the bus owns its lifecycle.
     *
     * Returns void — the post-handler outcome flows out via events;
     * idempotency and retry are bus-impl concerns.
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

**Strictly single-argument for everyone.** Domain code, application code, framework code — every caller passes only the raw message. No `MessageId` parameter, no `Envelope` parameter. The Envelope (§6) is constructed *inside* the bus at the moment the message is first seen. Domain code never instantiates an `Envelope` or thinks about `MessageId`.

### `EnvelopedCommandBus` / `EnvelopedQueryBus` / `EnvelopedEventBus` — framework-internal re-dispatch

For paths where the message has *already* been enveloped (staging flush after a producer-deterministic id was minted at staging time; DLQ replay; transport recovery), the bus exposes a framework-internal extension that accepts the existing `Envelope` instead of constructing a fresh one. The envelope already carries its `MessageId`; the bus respects it (per §6.1's producer-supplied-id-authoritative rule).

```php
/**
 * @psalm-api
 *
 * @internal Framework-facing — used by `MessageStaging` flush, DLQ replay,
 *           and transport recovery. Domain code uses `CommandBus` directly
 *           and never sees this interface.
 */
interface EnvelopedCommandBus extends CommandBus {
    /**
     * Dispatch a command via an envelope that already exists — the
     * envelope's `MessageId`, metadata, and stamps are honored verbatim.
     * Used when re-dispatching a previously-staged or previously-failed
     * message; never call this for fresh dispatches.
     *
     * @param Envelope<Command> $envelope
     */
    public function dispatchEnveloped(Envelope $envelope): void;
}

/** @internal */
interface EnvelopedQueryBus extends QueryBus {
    /**
     * @template TResult
     * @param Envelope<Query<TResult>> $envelope
     * @return TResult
     */
    public function dispatchEnveloped(Envelope $envelope): mixed;
}

/** @internal */
interface EnvelopedEventBus extends EventBus {
    /** @param Envelope<DomainEvent> $envelope */
    public function publishEnveloped(Envelope $envelope): void;
}
```

Bus implementations implement the `Enveloped*` subinterface; they get the simple `*Bus` contract for free (the public `dispatchCommand($cmd)` method delegates internally to `dispatchEnveloped(new Envelope($cmd, $freshMetadata))`).

**Why a subinterface and not an optional argument.** The user-facing API stays *strictly* one-argument — no MessageId param to confuse domain authors, no temptation to thread metadata manually. The framework-internal pathway is explicit and discoverable (`@internal`, distinct interface, distinct method name). Two-interfaces-one-implementation costs less than one-interface-with-domain-pollution.

### When is `MessageId` generated?

Only at two moments, never as a parameter to anything:

1. **Creation** — when the framework first sees a fresh raw message:
   - User code calls `$bus->dispatchCommand($cmd)` → bus generates `MessageId` and constructs `Envelope`
   - PM/aggregate code calls `$staging->appendCommand($cmd, Option::some($producerId))` → staging uses `$producerId` (deterministic, for crash-replay safety) and constructs `Envelope` immediately at staging time
   - PM/aggregate calls `$staging->appendCommand($cmd, Option::none())` → staging generates `MessageId` at staging time

2. **Deserialization** — when an `Envelope` is reconstituted from the wire (transport recovery, DLQ replay, outbox dispatcher, queue consumer): the `MessageId` is *restored* from the serialized form, never regenerated.

`MessageId` lives on the `Envelope`. The Envelope lives on the wire. Domain messages stay clean.

Method names spell out the *kind* of message — `$bus->dispatchCommand(...)` is greppable, distinct from `$bus->publishEvent(...)`. Adapters that wrap a single underlying transport (e.g., a Symfony Messenger adapter) implement all three by holding the configured-bus instance and casting at the seam.

### Handler interfaces — marker only, single-argument `__invoke`

```php
/**
 * @psalm-api
 *
 * Marker for command handlers. Implementers declare:
 *
 *   public function __invoke(ConcreteCommand $command): void
 *
 * Single-argument `__invoke`. Handlers do NOT receive metadata as a
 * second parameter — the framework's `CurrentMessageContext` (§5) carries
 * in-flight metadata implicitly. Handlers that need to read metadata for
 * logging/audit call `CurrentMessageContext::current()`; the typical
 * domain handler doesn't read it at all.
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
 *   public function __invoke(ConcreteQuery $query): TResult
 *
 * Validated by `QueryHandlerSignatureRule`.
 */
interface QueryHandler {}

/**
 * @psalm-api
 *
 * Marker for event listeners. Implementers declare:
 *
 *   public function __invoke(ConcreteEvent $event): void
 *
 * Validated by `EventListenerSignatureRule`. Multiple listeners per event
 * type are allowed (broadcast semantics).
 */
interface EventListener {}
```

**Why marker-only + single-argument.** PHP cannot enforce *parameter contravariance narrowing* on an interface — declaring `handle(Command $cmd)` would force every implementation to accept any `Command`, defeating type safety. Each `__invoke` carries the *concrete* message type as its parameter, the type system enforces the right type at the dispatch seam, and the Psalm plugin checks the shape at compile time.

The single-argument shape (no `MessageContext` parameter) keeps domain handlers focused on their message. Metadata threading happens automatically via the framework's `CurrentMessageContext` (§5) — when a handler dispatches a nested command, the framework reads in-flight metadata and propagates causation/correlation/conversation/trace context onto the new envelope without the handler doing anything.

This mirrors the Symfony Messenger handler convention exactly, so a future Symfony adapter is straightforward.

---

## 5. `CurrentMessageContext` — implicit metadata propagation

> **Audience for this section: bus implementers and framework contributors.** Domain handlers do NOT interact with `CurrentMessageContext` directly except through the boundary helper `within()`. If you're writing aggregates / PMs / handlers, this section is informational; if you're writing a bus adapter or runtime integration, this is your contract.

The metadata-threading discipline (causation, correlation, conversation, trace context, actor) is a **framework concern**, not a domain concern. Handlers shouldn't have to remember to call `forCausedMessage()` on every nested dispatch — the framework knows which message is currently being processed and propagates automatically.

### The ambient-context primitive

`CurrentMessageContext` is a façade over a pluggable `ContextStorage`. The default storage uses a per-process static stack (correct for synchronous PHP and Fiber runtimes); coroutine-aware adapters (Swoole, ReactPHP) replace the storage with a per-coroutine implementation.

```php
namespace Monadial\Nexus\Ddd\Messaging\Context;

/**
 * @psalm-api
 *
 * Pluggable storage for the in-flight context stack. Adapters that run
 * on coroutine runtimes (Swoole, ReactPHP) MUST provide a coroutine-keyed
 * implementation so concurrent handler chains do not see each other's
 * state. Single-threaded / Fiber runtimes use the default static-array
 * storage.
 *
 * The contract is enforced by `ContextStorageContractTest`: parallel
 * `within()` calls in N coroutines, each pushing a distinct context,
 * must each see only their own context's `current()`.
 */
interface ContextStorage {
    /** @return list<MessageContext> */
    public function snapshot(): array;
    public function push(MessageContext $ctx): void;
    public function pop(): void;

    /** @return Option<MessageContext> */
    public function current(): Option;

    /**
     * Replace the entire stack with the given snapshot. Used by
     * coroutine-bridge code that hands off control across runtime
     * boundaries (e.g., enqueueing work into a worker pool that
     * needs the same logical context restored on the consumer side).
     *
     * @param list<MessageContext> $stack
     */
    public function restore(array $stack): void;
}

/**
 * Default storage — per-process static stack. Correct for synchronous
 * PHP, Fiber-based runtimes, and any setting where one logical request
 * never yields control to another logical request mid-handler.
 */
final class StaticStackContextStorage implements ContextStorage {
    /** @var list<MessageContext> */
    private array $stack = [];
    public function snapshot(): array { return $this->stack; }
    public function push(MessageContext $ctx): void { $this->stack[] = $ctx; }
    public function pop(): void { array_pop($this->stack); }

    /** @return Option<MessageContext> */
    public function current(): Option { return Option::fromNullable(array_last($this->stack)); }
    public function restore(array $stack): void { $this->stack = $stack; }
}

/**
 * @psalm-api
 *
 * Façade over `ContextStorage`. Domain code interacts only with `current()`
 * (read-only) and `within()` (boundary entry). Bus implementations
 * additionally use `push()` / `pop()` and may install a custom storage
 * via `setStorage()` for coroutine-aware runtimes.
 *
 * The static façade is intentional — making it injectable would force
 * every handler to receive a `MessageContextProvider` parameter, which
 * defeats the entire reason this design exists. The escape valve is
 * `setStorage()`: framework integrations swap the backing once at boot.
 */
final class CurrentMessageContext {
    private static ?ContextStorage $storage = null;

    private static function storage(): ContextStorage {
        return self::$storage ??= new StaticStackContextStorage();
    }

    /**
     * Read the active storage. Used by framework integrations that need
     * to swap-and-restore (e.g., installing the ReplayingContextStorage
     * around an `EventSourcedXxx::replay()` call).
     */
    public static function getStorage(): ContextStorage {
        return self::storage();
    }

    /**
     * Replace the storage. Called by framework integrations once at boot
     * (e.g., the Swoole adapter installs a coroutine-keyed storage).
     * Tests use this to install a fresh storage per test for isolation.
     */
    public static function setStorage(ContextStorage $storage): void {
        self::$storage = $storage;
    }

    /** Reset to the default `StaticStackContextStorage`. Used at end of test. */
    public static function resetStorage(): void {
        self::$storage = null;
    }

    /** @return Option<MessageContext>  Option::none() at top-level (no in-flight message). */
    public static function current(): Option {
        return self::storage()->current();
    }

    /** @internal Bus implementations call this when entering a handler. */
    public static function push(MessageContext $ctx): void {
        self::storage()->push($ctx);
    }

    /** @internal Bus implementations call this when a handler returns. */
    public static function pop(): void {
        self::storage()->pop();
    }

    /**
     * Application-boundary helper: run $callback with $ctx as the root
     * context, then restore. Use this in HTTP middleware / CLI bootstrap
     * to establish the actor and trace identity for an incoming request.
     *
     * Discipline: ambient stack is read at *dispatch time*, not at
     * handler-invocation time. For an async dispatch (queued bus), what
     * gets stamped onto the envelope is what's on the stack when the
     * caller invokes `dispatchCommand`, NOT what's on the stack when the
     * handler eventually runs (which may be in a different process).
     * The serialized envelope carries the metadata across the boundary;
     * the consumer-side bus rebuilds the context from the envelope before
     * invoking the handler.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function within(MessageContext $ctx, callable $callback): mixed {
        self::push($ctx);
        try {
            return $callback();
        } finally {
            self::pop();
        }
    }
}
```

### Replay-mode sentinel

During event-sourced replay (PMs, aggregates rebuilding state from streams), the framework MUST install a sentinel context that **rejects dispatch attempts**. Without it, a replay-suppression bug in a consumer package could silently corrupt causation chains by stamping replayed events with whatever ambient context happened to be active.

```php
/**
 * @psalm-api
 *
 * Sentinel installed during ES replay. Throws on push/pop attempts —
 * any code that tries to dispatch during replay fails loudly instead
 * of silently corrupting the causation chain of an unrelated message.
 *
 * Consumer packages (PM, aggregate, eventsourcing) install this around
 * `EventSourcedXxx::replay()` invocations:
 *
 *     $previous = CurrentMessageContext::getStorage();
 *     CurrentMessageContext::setStorage(new ReplayingContextStorage());
 *     try {
 *         $aggregate->replay($events);
 *     } finally {
 *         CurrentMessageContext::setStorage($previous);
 *     }
 *
 * Any `applyXxx` method that accidentally calls `dispatchCommand` /
 * `publishEvent` / `scheduleDeadline` during replay throws
 * `ReplayDispatchAttemptedException` — a `MessagingException`-rooted,
 * `TerminalFailure`-marked exception that loudly surfaces the bug.
 */
final class ReplayingContextStorage implements ContextStorage {
    public function snapshot(): array { return []; }
    public function push(MessageContext $ctx): void {
        throw new ReplayDispatchAttemptedException(
            'Cannot dispatch during ES replay — a handler or applyXxx method '
            . 'attempted to dispatch a message while the framework is rebuilding '
            . 'state from a persisted event stream. This is a framework wiring '
            . 'bug; the dispatch-suppression check on the consumer-side runtime '
            . 'failed. Investigate the call site that triggered the dispatch.'
        );
    }
    public function pop(): void { /* no-op — push throws first */ }

    /** @return Option<MessageContext> */
    public function current(): Option { return Option::none(); }
    public function restore(array $stack): void { /* no-op during replay */ }
}
```

### Coroutine-isolation contract (MUST)

Bus implementations running on a coroutine runtime MUST provide a `ContextStorage` that partitions the stack by coroutine identity such that two concurrently-active handler chains never see each other's `current()`. The default `StaticStackContextStorage` does NOT satisfy this — it's safe under PHP Fibers (cooperatively scheduled within a single thread, no preemption between explicit yield points the framework controls) but unsafe under Swoole coroutines (which share static state across coroutines within a worker process).

The `ContextStorageContractTest` (in `tests/Support/`) is the cross-implementation invariant pin:

```php
abstract class ContextStorageContractTest extends TestCase {
    abstract protected function createStorage(): ContextStorage;

    public function testIsolatesConcurrentHandlerChains(): void {
        // Spawn N parallel coroutines/fibers, each pushing a distinct
        // MessageContext, yielding random microseconds, then asserting
        // current() returns its own context. Test FAILS with shared
        // static storage; PASSES with coroutine-keyed storage.
    }
}
```

The `nexus-ddd-bus-actor` adapter spec MUST document its per-fiber/per-coroutine stack-swap discipline. An actor handler observing the wrong context across an `await` is a correctness bug, not a performance one.

### Top-level orphan-dispatch policy

When `dispatchCommand` is called with no surrounding `within()` (no in-flight context), the bus falls back to `MessageMetadata::root()` with `actor: null`. Bus implementations MUST log this fallback at WARNING level — silent root-fallback is how production systems end up with messages that have no audit trail. Teams that legitimately dispatch from a non-boundary context (e.g., test setup, ops scripts) opt in via an explicit `MessageMetadata::root(actor: ActorRef::system('ops-cli'))` inside `within()`.

### How a bus implementation uses it

Bus implementations (Symfony Messenger adapter, in-process pipeline bus, actor-based bus) follow this pattern when dispatching:

```php
final class InProcessCommandBus implements CommandBus {
    public function dispatchCommand(Command $command): void {
        // 1. Read ambient context to derive child metadata.
        $parent = CurrentMessageContext::current();
        $childMeta = $parent === null
            ? MessageMetadata::root()                                       // root-level
            : $parent->metadata->forCausedMessage(MessageId::generate(), $this->clock->now());

        // 2. Wrap in transport envelope.
        $envelope = new Envelope($command, $childMeta);

        // 3. Find handler, push context, invoke, pop.
        $handler = $this->locator->locate($command);
        CurrentMessageContext::within(new MessageContext($childMeta), fn() => $handler($command));
    }
}
```

Domain code never sees `CurrentMessageContext::push/pop`; only the bus implementations call them. Domain handlers stay one-argument:

```php
public function __invoke(PaymentReceived $event): void {
    // No metadata threading — framework handles it.
    $this->commandBus->dispatchCommand(new ShipOrder($this->orderId));
}
```

The `dispatchCommand($cmd)` call internally reads `CurrentMessageContext::current()` (which the bus pushed before invoking *this* handler), derives causation = `PaymentReceived.id`, wraps `ShipOrder` in a fresh envelope with proper correlation/conversation/trace context, and sends to transport.

### Application-boundary entry point

Code at the application boundary establishes the root context:

```php
// HTTP controller — extract authenticated user, propagate trace context, dispatch.
public function placeOrder(Request $request, CommandBus $bus): Response {
    $rootMeta = MessageMetadata::root(
        actor: ActorRef::user($request->user()->id),
        traceParent: $request->headers->get('traceparent'),
        traceState:  $request->headers->get('tracestate'),
    );

    CurrentMessageContext::within(new MessageContext($rootMeta), function () use ($bus, $request) {
        $bus->dispatchCommand(new PlaceOrder(
            OrderId::generate(),
            ProductId::fromString($request->input('product_id')),
        ));
    });

    return new Response(201);
}
```

Most teams will hide this in a small middleware so controllers don't see it.

### Why no manual envelope construction

The user-facing API is **dispatch a raw message; the framework figures out the envelope**. This:
- Keeps domain code free of metadata threading
- Makes nested dispatch ergonomically identical to top-level dispatch
- Centralizes causation/correlation/trace propagation in the bus, where it's testable
- Mirrors PixelFederation's PM-emits-raw-message-then-framework-stamps-metadata pattern, but without their mutable `Command::appendMetadata()` setter (we keep messages `final readonly`)

The `Envelope`, `MessageMetadata::forCausedMessage()`, and `MessageContext` value objects still exist — but their *audience* is the framework, not the domain code.

---

## 6. Envelope, MessageMetadata, Stamps, MessageContext

> **Audience.** Domain code does NOT instantiate any of these — the bus does. The Envelope is constructed by the bus internally before crossing transport. `MessageMetadata::forCausedMessage()` is called by the bus when reading the ambient `CurrentMessageContext`. `MessageContext` is what the bus pushes onto the stack before invoking a handler. Handlers that want to *read* metadata for logging/audit reach into `CurrentMessageContext::current()`.

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

### `ActorRef`

`MessageMetadata` carries a typed actor reference, not a free string. The kind/id split makes audit trails machine-readable and prevents the "is `'system'` a username?" ambiguity that plagues string-based audit fields.

```php
namespace Monadial\Nexus\Ddd\Messaging\Identity;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Identifier of the actor responsible for a message. `kind` is the
 * actor category (`'user'`, `'system'`, `'service'`); `id` is the
 * stable identifier within that kind.
 *
 *   ActorRef::user('01HK...')             // human user, ULID id
 *   ActorRef::system('payments-worker')   // background process
 *   ActorRef::service('shipping-svc')     // external service
 */
final readonly class ActorRef {
    public function __construct(
        public string $kind,
        public string $id,
    ) {}

    public static function user(string $id): self    { return new self('user', $id); }
    public static function system(string $id): self  { return new self('system', $id); }
    public static function service(string $id): self { return new self('service', $id); }
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
 * (correlation/conversation/W3C trace context), idempotency (id), schema
 * evolution (schemaVersion), and security/audit (actor).
 *
 * Anything *not* in this list lives in a Stamp.
 *
 * **`schemaVersion` semantics.** This is the *wire-payload version* of
 * the message — the format of the serialized payload, NOT the event
 * class version. A producer running with `OrderPlaced` v3 may still emit
 * `schemaVersion: 1` if the v3 class serializes to the v1 wire shape (no
 * field changes). Consumers use this to drive upcaster selection without
 * relying on the class name alone, which can lie across deserialization
 * round-trips. Upcasting itself is implementation-detail of the future
 * `nexus-serialization`/`nexus-ddd-eventsourcing` packages; this package
 * only carries the version field.
 *
 * **`traceParent` / `traceState`.** W3C Trace Context propagation —
 * non-negotiable for distributed CQRS observability. They live as core
 * metadata (not stamps) because every operator running this in
 * production needs them within six months of go-live.
 */
final readonly class MessageMetadata {
    /**
     * @param Option<MessageId> $causationId
     * @param Option<MessageId> $correlationId
     * @param Option<MessageId> $conversationId
     * @param Option<ActorRef> $actor
     * @param Option<string> $traceParent           W3C traceparent header value
     * @param Option<string> $traceState            W3C tracestate header value
     * @param Option<DateTimeImmutable> $expiresAt  optional TTL — bus impls SHOULD drop expired
     */
    public function __construct(
        public MessageId $id,
        public DateTimeImmutable $occurredAt,
        public Option $causationId,
        public Option $correlationId,
        public Option $conversationId,
        public Option $actor,
        public int $schemaVersion,
        public Option $traceParent,
        public Option $traceState,
        public Option $expiresAt,
    ) {}

    /**
     * Application-boundary factory: synthesize a root MessageMetadata for
     * the first message in a chain (HTTP controller, CLI, scheduled job).
     * All optional fields default to `Option::none()`; use the `with*`
     * builder methods to attach them.
     */
    #[\NoDiscard('the constructed metadata is the entire point of this call')]
    public static function root(ClockInterface $clock): self {
        return new self(
            id: MessageId::generate(),
            occurredAt: $clock->now(),
            causationId: Option::none(),
            correlationId: Option::none(),
            conversationId: Option::none(),
            actor: Option::none(),
            schemaVersion: 1,
            traceParent: Option::none(),
            traceState: Option::none(),
            expiresAt: Option::none(),
        );
    }

    /** @return self with the actor set. */
    #[\NoDiscard(...)]
    public function withActor(ActorRef $actor): self;

    /** @return self with W3C trace context set. */
    #[\NoDiscard(...)]
    public function withTrace(string $traceParent, Option $traceState): self;

    /** @return self with the TTL set. */
    #[\NoDiscard(...)]
    public function withExpiresAt(DateTimeImmutable $expiresAt): self;

    /**
     * Derive metadata for a message *caused by* this one. The current
     * message becomes the new message's causation; correlation and
     * conversation propagate (initialized to the original id if absent —
     * the very first message in a chain is its own correlation root);
     * actor and trace context flow forward unchanged.
     *
     * **@internal — called by the framework, not by domain code.** Bus
     * implementations call this when reading `CurrentMessageContext` to
     * stamp a child message's envelope. Handlers never call this directly.
     */
    #[\NoDiscard('the derived metadata is the entire point of this call')]
    public function forCausedMessage(MessageId $newId, DateTimeImmutable $now): self {
        return new self(
            id: $newId,
            occurredAt: $now,
            causationId: Option::some($this->id),
            // If we already have a correlation, propagate it; else this is the
            // root and the new message's correlation is our own id.
            correlationId: $this->correlationId->orElse(fn() => Option::some($this->id)),
            conversationId: $this->conversationId->orElse(fn() => Option::some($this->id)),
            actor: $this->actor,
            schemaVersion: $this->schemaVersion,
            traceParent: $this->traceParent,
            traceState: $this->traceState,
            // expiresAt does NOT propagate — TTL is per-message, not per-chain
            expiresAt: Option::none(),
        );
    }
}
```

**`expiresAt` semantics.** Optional message TTL. Producers MAY set it on time-sensitive messages (e.g., a `ChargePayment` that's only valid within 24h). Bus implementations SHOULD check `expiresAt` against the wall-clock at handler-invocation time and route expired messages to the DLQ via `InvalidMessageReason::Expired` (§10) without invoking the handler. The field does NOT propagate via `forCausedMessage` — each downstream message decides its own expiry.

### Ordering acknowledgment (no guarantees by default)

This package makes **no per-channel ordering guarantee**. Bus implementations are free to deliver messages in any order — including out-of-order across redeliveries. Consumers requiring ordering MUST self-serialize:

- **Per-correlation-key serialization** (PMs and aggregates): the runtime ensures one PM/aggregate instance processes one external event at a time. PM spec §7 mandates this.
- **Per-actor-mailbox serialization** (actor-bus adapter): the actor framework's mailbox-per-actor semantics provides this for free; the adapter wires PMs/aggregates as actors.
- **Application-level locks** (last resort): if neither of the above applies, application code uses a distributed lock keyed on the correlation field.

Producers that want a *hint* to an ordering-aware bus impl attach a `PerCorrelationKeyOrdered` stamp:

```php
namespace Monadial\Nexus\Ddd\Messaging\Stamp;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Hint to bus implementations that this message should be delivered in
 * order with respect to other messages bearing the same `correlationKey`.
 * Bus impls MAY honor it (Symfony Messenger via partition key, actor-bus
 * via per-actor mailbox); they are not REQUIRED to. Consumers MUST NOT
 * assume ordering is enforced just because the stamp is present.
 */
final readonly class PerCorrelationKeyOrdered implements Stamp {
    public function __construct(public string $correlationKey) {}
}
```

The EIP-classic theorem holds: with N parallel consumers and at-least-once redelivery, you get out-of-order processing unless the consumer self-serializes. This package is honest about that.

### Causation propagation — worked example (implicit, no manual threading)

A 3-hop chain showing how the framework threads metadata automatically. Domain handlers stay raw — they call `dispatchCommand($cmd)` / `publishEvent($evt)` with no metadata in sight:

```
Hop 1 — outside the system, HTTP controller (the only place metadata is named):
  CurrentMessageContext::within(
      new MessageContext(MessageMetadata::root(actor: ActorRef::user('alice'), traceParent: $tp)),
      fn() => $commandBus->dispatchCommand(new PlaceOrder(...))
  );
  // Bus reads CurrentMessageContext::current()
  // Effective envelope metadata: id=R, causation=null, correlation=R, conversation=R, actor=alice, traceParent=$tp

Hop 2 — PlaceOrderHandler runs (no MessageContext arg, no manual threading):
  public function __invoke(PlaceOrder $cmd): void {
      $order = Order::place(...);
      $this->orders->save($order);   // Aggregate's pullPendingEvents stages OrderPlaced
                                     // Staging flush -> EventBus.publishEvent(new OrderPlaced(...))
                                     // EventBus reads CurrentMessageContext::current() -> derives:
                                     // Effective envelope metadata for OrderPlaced:
                                     //   id=E1, causation=R, correlation=R, conversation=R, actor=alice
  }

Hop 3 — OrderFulfillmentProcess saga consumes OrderPlaced (no MessageContext arg):
  public function __invoke(OrderPlaced $event): void {
      $this->dispatchCommand(new ChargePayment(...));
      // Bus reads CurrentMessageContext::current() (which is the OrderPlaced context)
      // Derives: id=C1, causation=E1, correlation=R, conversation=R, actor=alice
  }
```

At every hop, the framework manages metadata. The handler signature is just the message. The full chain is reconstructable from any point: `causationId` walks back one hop; `conversationId` jumps to the root; `correlationId` groups every message in the workflow. Trace context (`traceParent`/`traceState`) propagates identically so the same chain shows up in your distributed tracer.

**Compare to PixelFederation's pattern.** PF achieves the same propagation by making `Command` mutable and calling `Command::appendMetadata()` at flush time. Nexus keeps messages `final readonly` and uses ambient `CurrentMessageContext` instead — same end-to-end behavior, immutable messages, no metadata threading in domain code.

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
     * @return Option<S>
     */
    public function get(string $stampClass): Option {
        return Option::fromNullable($this->stamps[$stampClass] ?? null);
    }
}
```

### `MessageContext`

```php
/**
 * @psalm-api
 * @psalm-immutable
 *
 * Pure value object — metadata + stamps for the in-flight message.
 *
 * Audience: framework-internal. The bus pushes a MessageContext onto
 * `CurrentMessageContext` before invoking a handler; the bus reads
 * `CurrentMessageContext::current()->metadata` when stamping nested
 * dispatches.
 *
 * Handlers do NOT receive a MessageContext as a parameter. Handlers that
 * need to read metadata for logging/audit call
 * `CurrentMessageContext::current()` — but the typical domain handler
 * never reaches for it.
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
     * @return Option<S>
     */
    public function stamp(string $stampClass): Option {
        return Option::fromNullable($this->stamps[$stampClass] ?? null);
    }
}
```

### Worked example — first command + handler

A complete walkthrough showing how clean domain code stays under implicit propagation:

```php
namespace App\Users;

// 1. Define the command — final readonly class implementing Command marker.
//    The ReadonlyMessageBodyRule Psalm rule enforces final+readonly.
final readonly class RegisterUser implements Command {
    public function __construct(
        public UserId $id,
        public string $email,
        public string $displayName,
    ) {}
}

// 2. Define the handler — single-argument __invoke; no MessageContext threading.
//    The CommandHandlerSignatureRule Psalm rule enforces the __invoke shape.
final class RegisterUserHandler implements CommandHandler {
    public function __construct(
        private readonly UserRepository $users,
        private readonly EventBus $events,
    ) {}

    public function __invoke(RegisterUser $cmd): void {
        $user = User::register($cmd->id, $cmd->email, $cmd->displayName);
        $this->users->save($user);

        // Emit a domain event — framework propagates causation from the
        // in-flight RegisterUser command via CurrentMessageContext.
        $this->events->publishEvent(new UserRegistered($user->id()));
    }
}

// 3. Dispatch from the application boundary (HTTP controller, CLI, etc.).
//    The boundary code is the only place that names metadata.
CurrentMessageContext::within(
    new MessageContext(MessageMetadata::root(actor: ActorRef::user($currentUserId))),
    fn() => $commandBus->dispatchCommand(new RegisterUser($newUserId, $email, $displayName))
);

// 4. Test using RecordingEventBus + setting up CurrentMessageContext.
final class RegisterUserHandlerTest extends TestCase {
    public function testRegistersUserAndEmitsEvent(): void {
        $users = new InMemoryUserRepository();
        $events = new RecordingEventBus();
        $handler = new RegisterUserHandler($users, $events);

        $cmd = new RegisterUser($id, 'a@b.c', 'Alice');
        CurrentMessageContext::within(
            new MessageContext(MessageMetadata::root(actor: ActorRef::user('test-user'))),
            fn() => $handler($cmd)
        );

        self::assertCount(1, $users->all());
        self::assertEquals([new UserRegistered($id)], $events->recorded());
        self::assertNotNull($events->lastMetadata()->causationId);  // RegisterUser caused the event
    }
}
```

This is the canonical recipe. Every command/query/event/handler in the framework follows this shape — the only variations are the message kind (Command vs Query vs DomainEvent) and the bus interface used. **Domain code never instantiates an Envelope, never calls `forCausedMessage()`, never threads `MessageContext` through method signatures.**

### Test doubles

This package ships test doubles in `tests/Support/`:

```php
final class RecordingCommandBus implements CommandBus {
    /** @var array<int, Command> */
    private array $recorded = [];

    public function dispatchCommand(Command $command): void {
        $this->recorded[] = $command;
    }

    /** @return array<int, Command> */
    public function recorded(): array { return $this->recorded; }
}

final class RecordingEventBus implements EventBus { /* same shape */ }
final class RecordingQueryBus implements QueryBus { /* + canned responses */ }
```

These let consumers (PMs, aggregates, application services) write fast unit tests without wiring a real bus. Tests assert on `recorded()`; production wires a real bus impl.

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

**Disjointness invariant.** A single exception class MUST NOT implement both `TransientFailure` and `TerminalFailure`. The disjointness is enforced by a contract test (`assertFalse($e instanceof TransientFailure && $e instanceof TerminalFailure)`) that runs against every concrete `MessagingException` subclass. Violating this would let a fundamentally terminal failure escape into the retry loop.

**Default policy when neither marker is present.** An exception that implements neither is treated as **terminal** — fail-closed. The reasoning: silent retry-forever for unknown exceptions is the worst failure mode. New users will assume "no marker = retry forever"; the framework MUST contradict that assumption by routing unmarked exceptions to the DLQ on first failure unless the team explicitly opts into retry via a marker.

**Where domain-rule recoverability lives.** A team writing `SeatTakenException extends DomainException` decides whether retry helps (it does — another seat may free up) and adds `implements TransientFailure`. The retry policy in §5 then maps the class to a backoff strategy. The recoverability decision is the **exception's** concern (the marker), not the bus's (the policy maps marker-bearers to strategies). `DomainException` from core implements neither marker — concrete subclasses opt in to transience explicitly when the rule is recoverable.

---

## 6. Delivery semantics — the exactly-once contract

This section is load-bearing for every ES consumer (PM, aggregate, future eventsourcing) of this package. The PM spec relies on these contracts; they belong here, in the messaging foundation, not buried in consumer specs.

### 6.1 Producer-supplied `MessageId` is authoritative

Bus implementations MUST honor a producer-supplied `MessageMetadata::id` and MUST NOT regenerate it. The producer (PM, aggregate, application service) decides the id; the bus accepts it untouched.

For producers that need crash-replay safety — PMs and ES aggregates — the recipe is **deterministic id derivation** from producer state:

```
PM-emitted command:   MessageId = hash(pmId, baseStreamSeq, withinStagingOrdinal)
Aggregate-emitted event: MessageId = hash(aggregateId, eventStreamSequence)
```

The deterministic composite is stable across crash-replay (the same handler invocation, on redelivery, computes the same id), which means downstream-side dedup absorbs duplicates.

For producers that don't need crash-replay safety — application services dispatching one-off commands from a controller — `MessageId::generate()` is fine; the request is at-most-once at the application boundary anyway.

### 6.2 At-least-once delivery

Bus implementations MUST attempt delivery at least once. Network partitions, broker restarts, container redeploys may cause the same message to arrive at the handler twice or more.

### 6.3 Handler idempotency — `MessageInbox` contract (MUST for production bus impls)

Handlers MUST be idempotent. At-least-once delivery without consumer-side dedup is a production-incident generator; this package ships the contract so adapters get it right.

```php
namespace Monadial\Nexus\Ddd\Messaging\Inbox;

/**
 * @psalm-api
 *
 * Consumer-side dedup gate. The bus consults the inbox before invoking
 * a handler; if the message id has been processed already, the bus
 * acknowledges the delivery and returns without invoking the handler.
 *
 * Scoped per (handler, message-id) — the same message id may legitimately
 * be processed by multiple distinct handlers (one event, three listeners).
 *
 * Implementations:
 *   - `InMemoryMessageInbox` — process-local; ships in this package
 *     for tests and single-process Fiber runtimes.
 *   - DB-backed inbox (downstream `nexus-ddd-aggregate` or
 *     `nexus-ddd-outbox`) — production-grade durable dedup.
 */
interface MessageInbox {
    /**
     * Returns true if the (handler, messageId) pair has not been
     * processed before AND atomically reserves the id; false if it has
     * been processed (the bus should ack and return without invoking
     * the handler).
     *
     * @param class-string $handlerClass
     */
    public function tryReserve(string $handlerClass, MessageId $messageId): bool;

    /**
     * Mark a previously-reserved (handler, messageId) as fully processed.
     * Called after the handler returns successfully and the surrounding
     * transaction commits.
     *
     * @param class-string $handlerClass
     */
    public function markProcessed(
        string $handlerClass,
        MessageId $messageId,
        ?DateTimeImmutable $at = null,
    ): void;

    /**
     * Release a reservation (called on handler failure / rollback) so the
     * next redelivery can retry. Without this, a transient failure would
     * permanently block the message.
     *
     * **Transactional co-location MUST.** `release()` MUST execute in the
     * same rolled-back transaction as the failing handler — for DB-backed
     * inboxes, that means the release is part of the rollback (or a
     * compensating insert in a separate retry-safe transaction). NEVER
     * call `release()` AFTER the rollback has already committed: there
     * is a sliver between rollback-commit and release-execute where
     * broker redelivery hits a still-reserved row, gets `false` from
     * `tryReserve`, and incorrectly routes to DLQ. The reservation and
     * its release are co-transactional.
     *
     * @param class-string $handlerClass
     */
    public function release(string $handlerClass, MessageId $messageId): void;
}
```

**Production bus implementations MUST integrate with `MessageInbox`.** The default flow:

```
1. bus receives Envelope from transport
2. for each handler resolved by Locator:
3.   if (!inbox.tryReserve(HandlerClass, envelope.metadata.id)) → ack + skip
4.   begin transaction
5.   invoke handler(message)
6.   if success:
7.     inbox.markProcessed(HandlerClass, id)
8.     commit transaction
9.   else:
10.    inbox.release(HandlerClass, id)
11.    rollback
12.    bus retry policy decides next action
```

**`InMemoryMessageInbox` ships in this package** alongside `InMemoryMessageStaging`. Both are tests-only / single-process. Production teams wire the DB-backed inbox from a downstream package.

### 6.4 Exactly-once-effect recipe

For exactly-once-*effect* semantics (the same money is not charged twice; the same email is not sent twice):

1. **Producer determinism** (§6.1) — same input → same `MessageId`.
2. **Consumer-side dedup** via `MessageInbox` (§6.3) — bus skips already-processed ids.

Together these give exactly-once-effect across at-least-once delivery + crash-replay. Both halves are mandatory; either alone is insufficient.

### 6.5 The `void` return semantics

`CommandBus::dispatchCommand(Command): void` and `EventBus::publishEvent(DomainEvent): void` return after **delivery is accepted**, not after the handler completes. Sync implementations happen to give you handler-completion as a side effect, but consumers MUST NOT depend on that — async impls violate it, and code that relies on "if dispatchCommand returned, the handler has run" breaks the moment the team swaps to a queued bus.

The exactly-once-effect contract (§6.4) is the only correctness mechanism. Don't lean on call-stack synchronicity.

### 6.6 Async-safety contract for handlers

Handlers MUST be async-safe:
- No reliance on the call stack — the dispatching code may have returned long before the handler runs.
- No leaking transactions through globals — every transaction is scoped to a single handler invocation.
- No request-scoped framework state (e.g., Symfony's `RequestStack`) — handlers may run in worker processes that have no incoming HTTP request.
- All required context arrives via the message + `MessageContext`; nothing else is reliable across the dispatch boundary.

This is documented as a hard rule. Bus implementations that detect handlers violating it (e.g., by inspecting framework-state usage) SHOULD fail loudly.

---

## 7. `MessageStaging` & `UnitOfWork` — shared with PMs and aggregates

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
    /**
     * Stage a command for post-commit dispatch.
     *
     * Producers (PMs, ES aggregates) supply a deterministic `MessageId`
     * via `Option::some($id)` for crash-replay safety; staging respects
     * it. Application code (HTTP controller, CLI) passes `Option::none()`
     * and staging generates a fresh id at staging time.
     *
     * Either way, the `MessageId` is set the moment the message is
     * staged — i.e., at *creation* (per the §6.1 rule "MessageId is
     * generated when the message is created or deserialized"). Staging
     * builds the `Envelope` immediately and dispatches via
     * `EnvelopedCommandBus::dispatchEnveloped()` at flush time.
     *
     * @param Option<MessageId> $producerId
     *        For PM-emitted commands: Option::some(hash(pmId, baseStreamSeq, ordinal)).
     *        For aggregate-emitted: Option::some(hash(aggregateId, eventStreamSequence)).
     *        For one-off application commands: Option::none().
     */
    public function appendCommand(Command $command, Option $producerId): void;

    /** @param Option<MessageId> $producerId  Same producer-id semantics as appendCommand. */
    public function appendEvent(DomainEvent $event, Option $producerId): void;

    public function flush(): void;     // post-commit — EnvelopedXxxBus.dispatchEnveloped invoked
    public function discard(): void;   // post-rollback — buffer cleared
}

interface UnitOfWork {
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function staging(): MessageStaging;
}
```

### Transactional participation invariant

For **persistent** staging implementations (the future `OutboxMessageStaging` in downstream persistence package):
- `appendCommand()` / `appendEvent()` operations MUST participate in the same transaction that owns the domain state change. The outbox row is written in the SAME DB transaction as the PM/aggregate state, OR the impl MUST refuse to be used in a transactional context that does not enroll it.
- `flush()` is the post-commit dispatch step; the staged buffer is durable across crashes via the underlying transaction's commit guarantee.
- A staging impl that writes to a DIFFERENT DB connection than the domain state is broken — it gives you neither at-least-once nor exactly-once-effect.

For **in-memory** staging (`InMemoryMessageStaging`, this package):
- No persistence. A crash between `flush()` and the bus's actual dispatch loses the messages. **At-most-once** delivery, NOT at-least-once.
- This is **tests-only** and **single-process-Fiber-runtime-only**. Use in production at your own risk.
- The `InMemoryMessageStaging` class docblock SHOULD warn explicitly: "Production deployments MUST use a persistent staging implementation (e.g., OutboxMessageStaging from nexus-ddd-aggregate); this in-memory impl provides at-most-once delivery."

### Default in-package implementations

Ship `InMemoryMessageStaging` + `InMemoryUnitOfWork` in this package. They're sufficient for tests, single-process Fiber runtimes, and applications that consciously accept at-most-once delivery. Both pass the abstract `MessageStagingContractTest`.

```php
final class InMemoryMessageStaging implements MessageStaging {
    /** @var list<Envelope<Command>> */
    private array $commandEnvelopes = [];

    /** @var list<Envelope<DomainEvent>> */
    private array $eventEnvelopes = [];

    public function __construct(
        private readonly EnvelopedCommandBus $commandBus,
        private readonly EnvelopedEventBus $eventBus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** @param Option<MessageId> $producerId */
    public function appendCommand(Command $command, Option $producerId): void {
        // Build the Envelope NOW — at staging time, the message is "created"
        // (per §6.1's rule). MessageId is either producer-supplied or freshly
        // generated. Subsequent flush dispatches the existing envelope.
        $this->commandEnvelopes[] = new Envelope($command, $this->buildMetadata($producerId));
    }

    /** @param Option<MessageId> $producerId */
    public function appendEvent(DomainEvent $event, Option $producerId): void {
        $this->eventEnvelopes[] = new Envelope($event, $this->buildMetadata($producerId));
    }

    /** @param Option<MessageId> $producerId */
    private function buildMetadata(Option $producerId): MessageMetadata {
        $id = $producerId->getOrElse(fn() => MessageId::generate());

        // Read ambient context to derive child metadata; if absent, root.
        return CurrentMessageContext::current()
            ->map(fn(MessageContext $parent) => $parent->metadata->forCausedMessage($id, $this->clock->now()))
            ->getOrElse(fn() => MessageMetadata::root($this->clock)->forCausedMessage($id, $this->clock->now()));
    }

    /**
     * Flush ordering: commands first, then events. Some workflows
     * legitimately want events-first ("emit event, then dispatch
     * compensating commands"); those must inject a custom staging
     * impl. The default ordering reflects the more common case
     * (record state change as event AFTER coordinating commands
     * that depend on the state change).
     *
     * **Production warning** logged on every flush — see class docblock.
     */
    public function flush(): void {
        $this->logger->warning(
            'InMemoryMessageStaging.flush() — at-most-once delivery; '
            . 'a crash between flush() start and bus dispatch loses messages. '
            . 'Use a persistent staging implementation (OutboxMessageStaging) in production.',
        );

        foreach ($this->commandEnvelopes as $envelope) {
            $this->commandBus->dispatchEnveloped($envelope);
        }
        foreach ($this->eventEnvelopes as $envelope) {
            $this->eventBus->publishEnveloped($envelope);
        }
        $this->commandEnvelopes = [];
        $this->eventEnvelopes = [];
    }

    public function discard(): void {
        $this->commandEnvelopes = [];
        $this->eventEnvelopes = [];
    }
}
```

The class-level docblock SHOULD additionally say:

```
/**
 * In-memory staging — TESTS-ONLY (and single-process Fiber-only).
 *
 * Provides at-most-once delivery: a crash between flush() and the bus's
 * actual dispatch loses messages. Production deployments MUST use a
 * persistent staging implementation (OutboxMessageStaging from
 * nexus-ddd-aggregate, or equivalent) which writes the staged messages
 * to a durable store within the same DB transaction as the domain state.
 *
 * The runtime warning logged on every flush() is the operator-facing
 * canary that wiring is wrong if this impl is in production.
 */
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

## 8. Handler resolution — locator contracts

Per Udi's review, the static Psalm rule (`OneCommandHandlerRule`) and the dynamic resolution mechanism (each bus impl chooses container / registry / attribute scan) need a shared vocabulary. Without it, every adapter reinvents resolution and the static rule guards a contract the runtime doesn't actually express.

```php
namespace Monadial\Nexus\Ddd\Messaging\Resolution;

/**
 * @psalm-api
 *
 * Locator contract for command handlers. Bus implementations consume this
 * via container, registry, or reflection scan. The static `OneCommandHandlerRule`
 * verifies "exactly one CommandHandler implementer per concrete Command";
 * this interface is what the runtime side honors.
 *
 * @throws HandlerNotFoundException
 *         when no handler is registered for the command's concrete class
 */
interface CommandHandlerLocator {
    public function locate(Command $command): CommandHandler;
}

/**
 * @psalm-api
 *
 * @throws HandlerNotFoundException
 */
interface QueryHandlerLocator {
    /**
     * @template TResult
     * @param Query<TResult> $query
     */
    public function locate(Query $query): QueryHandler;
}

/**
 * @psalm-api
 *
 * Listeners are 0..N per event class — broadcast semantics. An empty
 * iterable is a valid response (no subscribers); not an error.
 */
interface EventListenerLocator {
    /** @return iterable<int, EventListener> */
    public function locate(DomainEvent $event): iterable;
}
```

Bus implementations construct themselves with a locator; consumers wire the locator with whatever resolution mechanism their stack offers (PSR-11 container, custom registry, attribute scan).

---

## 9. Serialization — `MessageSerializer` contract

Once the bus crosses a process boundary, every message must serialize. Without a contract here, every adapter reinvents its own `serialize`/`deserialize`. Define the contract centrally; let adapters bring their own format.

```php
namespace Monadial\Nexus\Ddd\Messaging\Serialization;

/**
 * @psalm-api
 *
 * Round-trip a message + its envelope across a process boundary.
 * Implementations: JSON, MessagePack, native PHP serialize, Valinor-mapped, etc.
 */
interface MessageSerializer {
    /** @template TMessage of object */
    public function serialize(Envelope $envelope): SerializedMessage;

    /** @template TMessage of object */
    public function deserialize(SerializedMessage $serialized): Envelope;
}

/**
 * @psalm-api
 * @psalm-immutable
 *
 * The wire format. `body` carries the encoded message + metadata + stamps;
 * `format` identifies the encoding for cross-version round-trip safety.
 */
final readonly class SerializedMessage {
    public function __construct(
        public string $body,
        public string $format,         // 'json', 'msgpack', 'php-serialize', etc.
        public string $messageClass,   // FQN of the message — for typed deserialization
    ) {}
}
```

Implementations (`PhpNativeMessageSerializer`, `JsonMessageSerializer`, etc.) live in adapter packages or in `nexus-serialization`. The contract here lets bus impls invoke serialization without coupling to a specific implementation.

---

## 10. Dead-letter store — `DeadLetterStore` contract

When all retries are exhausted (or the failure is `TerminalFailure`), the message goes to a dead-letter store. Without a uniform contract, every adapter ships its own DLQ shape and ops admin surfaces diverge.

```php
namespace Monadial\Nexus\Ddd\Messaging\DeadLetter;

/**
 * @psalm-api
 *
 * Persistent record of messages the bus could not deliver. Operators
 * inspect the DLQ to triage failures, and may re-inject messages back
 * into the bus once the underlying issue is resolved.
 */
interface DeadLetterStore {
    /** Record a failed message + the cause. */
    public function record(DeadLetterEntry $entry): void;

    /** Re-inject a previously-recorded message. The bus dedup gate
     *  (per §6.4) prevents double-processing if the original eventually
     *  succeeded. */
    public function replay(MessageId $messageId): void;

    /** @return iterable<int, DeadLetterEntry> */
    public function pending(): iterable;
}

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class DeadLetterEntry {
    public function __construct(
        public Envelope $envelope,
        public Throwable $cause,
        public DateTimeImmutable $deadLetteredAt,
        public int $attemptsBeforeDeadLetter,
        public DeadLetterReason $reason,    // distinguishes content-failure from delivery-failure
    ) {}
}

/**
 * @psalm-api
 *
 * EIP distinguishes Dead Letter Channel (delivery failure — replayable
 * once root cause is fixed) from Invalid Message Channel (content
 * failure — never replayable, schema/validation issue). Operators
 * need to triage these differently.
 *
 * `Invalid` reasons are NEVER replayed via `DeadLetterStore::replay()` —
 * the bus throws if asked. `Delivery` reasons are replayable; ops fixes
 * the upstream problem (downstream handler bug, infrastructure outage)
 * and then triggers replay.
 */
enum DeadLetterReason: string {
    // Delivery failures — replayable
    case TransientFailureExhausted = 'transient-failure-exhausted';   // retry policy gave up
    case TerminalFailure = 'terminal-failure';                         // exception marked TerminalFailure
    case Timeout = 'timeout';                                          // handler timed out
    case Expired = 'expired';                                          // MessageMetadata::expiresAt past

    // Content failures — NOT replayable (would just dead-letter again)
    case Invalid_DeserializationFailure = 'invalid-deserialization-failure';
    case Invalid_SchemaValidationFailure = 'invalid-schema-validation-failure';
    case Invalid_HandlerSignatureMismatch = 'invalid-handler-signature-mismatch';
    case Invalid_NoHandlerRegistered = 'invalid-no-handler-registered';

    public function isReplayable(): bool {
        return match($this) {
            self::TransientFailureExhausted, self::TerminalFailure,
            self::Timeout, self::Expired => true,
            self::Invalid_DeserializationFailure,
            self::Invalid_SchemaValidationFailure,
            self::Invalid_HandlerSignatureMismatch,
            self::Invalid_NoHandlerRegistered => false,
        };
    }
}
```

`DeadLetterStore::replay()` MUST consult `$entry->reason->isReplayable()` and throw `NonReplayableDeadLetterException` if false.

Implementations live downstream (DB-backed, file-backed, transport-native). The contract here gives ops a single admin vocabulary across adapters.

---

## 11. Exception taxonomy

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
final class HandlerSignatureMismatchException extends MessagingException implements TerminalFailure { ... }
final class MessageDispatchException extends MessagingException { ... }
final class MessageRejectedException extends MessagingException implements TerminalFailure { ... }
final class StagingClosedException extends MessagingException { ... }
```

`HandlerSignatureMismatchException` is the runtime counterpart to the static Psalm rules. Bus implementations resolve handlers dynamically (container, registry, attribute scan) and may encounter a handler whose `__invoke` shape doesn't match what the static rule would have required. The bus MUST throw this exception **at handler-resolution time, not at dispatch time** — discovering the mismatch when the handler is loaded prevents the message from being lost in a rejected envelope.

Three roots are now disjoint by design:
- `NexusDddException` — framework wiring (e.g. ApplyMethod-not-found in core)
- `DomainException` — business rule violations
- `MessagingException` — runtime delivery faults

Each root extends `RuntimeException` directly (not each other). Disjointness is enforced by a test (mirroring the core `ExceptionHierarchyTest` pattern).

---

## 12. PSR-first dependency policy

Same rule as `nexus-ddd-process-manager` (and the monorepo CLAUDE.md):

| Concern | Contract | Notes |
|---|---|---|
| Logger | `Psr\Log\LoggerInterface` (PSR-3) | Bus impls inject for handler diagnostics |
| Container / DI | `Psr\Container\ContainerInterface` (PSR-11) | Bus impls use it for handler resolution (this package only declares the contracts; downstream impls use the container) |
| Event dispatcher | `Psr\EventDispatcher\EventDispatcherInterface` (PSR-14) | For framework-internal events (none in this package directly, but the contract is stable) |
| Clock | `Psr\Clock\ClockInterface` (PSR-20) | `MessageMetadata` timestamps; test-injectable |

**No `symfony/*`, `laravel/*`, `monolog/*`, `doctrine/*` runtime deps as code dependencies.** Adapters live in dedicated `nexus-*-adapter-*` packages. Build-failing Deptrac `forbidden_imports` rule.

**Single allowed exception: `Symfony\Component\Uid\Ulid`.** ULID standardization is in PHP-FIG draft; until it lands as PSR, Symfony's implementation is the de facto standard, and `nexus-ddd-core` already depends on `symfony/uid` for the same reason. The Deptrac `forbidden_imports` regex is narrowed accordingly: `^Symfony\\(?!Component\\Uid\\)`. This is the only Symfony namespace any nexus package imports directly; everything else is accessed via PSR contracts.

---

## 13. Fitness functions (CI-enforced)

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
    - regex: ^Symfony\\(?!Component\\Uid\\).*
    - regex: ^Laravel\\.*
    - regex: ^Illuminate\\.*
    - regex: ^Monolog\\.*
    - regex: ^Doctrine\\.*
    - regex: ^GuzzleHttp\\.*
    - regex: ^React\\.*
    - regex: ^Amp\\.*
```

The `Symfony\Component\Uid` carve-out is the only allowed Symfony namespace (justified in §12). The defensive additions for `GuzzleHttp\\`, `React\\`, `Amp\\` prevent async libraries from leaking into messaging code — they're cheap to add now, expensive to retrofit once 14 downstream packages compile against the loose constraint.

**Psalm rules** (in `nexus-psalm` plugin):

| Rule | Enforces |
|---|---|
| `CommandHandlerSignatureRule` | Implementers of `CommandHandler` declare `__invoke(ConcreteCommand): void` (single-argument; no `MessageContext` parameter — implicit via `CurrentMessageContext`) |
| `QueryHandlerSignatureRule` | Implementers of `QueryHandler` declare `__invoke(ConcreteQuery): TResult` matching `Query<TResult>` |
| `EventListenerSignatureRule` | Implementers of `EventListener` declare `__invoke(ConcreteEvent): void` |
| `ReadonlyMessageBodyRule` | Concrete `Command` and `Query` classes are `final readonly class` (mirrors core's `ReadonlyMessageRule` for `DomainEvent`) |
| `OneCommandHandlerRule` | A given concrete `Command` class has exactly one implementer of `CommandHandler` (commands are point-to-point) |

**PHPUnit reflection / contract tests:**
- **Bus interface signature snapshot test** — pins the public method signatures of `CommandBus`, `QueryBus`, `EventBus` (return types, parameter types, exception annotations) into a fixture file; fails CI if any signature drifts without a coordinated fixture update. Five downstream packages will compile against these three interfaces; one quietly-added parameter breaks every adapter.
- **Three-root exception disjointness test** — `NexusDddException`, `DomainException`, `MessagingException` extend `RuntimeException` directly and not each other.
- **TransientFailure ∩ TerminalFailure = ∅ test** — every concrete exception in the package implements at most one of the markers (`assertFalse($e instanceof TransientFailure && $e instanceof TerminalFailure)` for each). Otherwise a fundamentally terminal failure could escape into the retry loop.
- `MessageMetadata::forCausedMessage` propagates `causationId`, `correlationId`, `conversationId`, `actor`, `traceParent`, `traceState` correctly across hops; `expiresAt` does NOT propagate.
- `Envelope::with()` / `get()` round-trip stamps.
- `RetryPolicy` first-match-wins and `giveUpSet` precedence.
- **`MessageStagingContractTest`** — both `InMemoryMessageStaging` and any future impl pass; pins discard/flush/FIFO/producer-supplied-id semantics.
- **`MessageInboxContractTest`** — both `InMemoryMessageInbox` and any future DB-backed impl pass; pins tryReserve/markProcessed/release semantics under concurrent reservation attempts.
- **`ContextStorageContractTest`** — `StaticStackContextStorage` plus any coroutine-aware impl pass; spawns N parallel fibers/coroutines pushing distinct contexts and asserts no cross-leakage.
- **Replay-sentinel test** — `ReplayingContextStorage` throws `ReplayDispatchAttemptedException` on push attempts.
- **DLQ replay-policy test** — `replay()` rejects entries with `Invalid_*` reasons; accepts `Delivery` reasons.

---

## 14. v1 deliverables

Beyond code:

- **Spec doc** (this file) committed to `docs/superpowers/specs/`
- **All Psalm rules** in §13 added to the `nexus-psalm` plugin
- **Deptrac layer + forbidden_imports** rule with the `Symfony\Component\Uid` carve-out
- **Bus interface signature snapshot test** as a contract test
- **`MessageStagingContractTest`** abstract test class (Support/)
- **`MessageInboxContractTest`** abstract test class (Support/)
- **`ContextStorageContractTest`** abstract test class (Support/)
- **`InMemoryMessageStaging`** + **`InMemoryUnitOfWork`** with full test coverage
- **`InMemoryMessageInbox`** with full test coverage
- **`StaticStackContextStorage`** + **`ReplayingContextStorage`** with full test coverage
- **Test doubles** (`RecordingCommandBus`, `RecordingEventBus`, `RecordingQueryBus`) in `tests/Support/`
- **`withRootContext(ActorRef $actor, callable $fn)` test helper** in `tests/Support/` — wraps `CurrentMessageContext::within(new MessageContext(MessageMetadata::root(actor: $actor)), $fn)` so handler unit tests stay one line

---

## 15. Out of scope for v1

**Out-of-scope but explicitly acknowledged** (so they don't get rediscovered as "missing" three years in):

- Bus implementations (Symfony Messenger adapter, in-process pipeline bus, actor-based bus — separate packages)
- DB-backed staging (`OutboxMessageStaging` in downstream persistence package)
- DB-backed message inbox (`PersistentMessageInbox` in downstream persistence package)
- **Inbox retention policy** (TTL-based pruning of old `markProcessed` rows). Production teams will hit DB-bloat at month 6; downstream `PersistentMessageInbox` MUST ship a retention strategy. The contract here is silent because retention windows are application-specific.
- Coroutine-aware `ContextStorage` (Swoole / ReactPHP impls in their respective adapter packages)
- Middleware abstraction (each bus impl decides)
- Specific handler resolution mechanism implementations (locator *contracts* are in v1; container-backed / registry-backed locators ship in adapter packages)
- Serialization implementations (`MessageSerializer` *contract* is in v1; concrete formats — JSON, MessagePack, native, Valinor — ship in adapters or `nexus-serialization`)
- Specific dead-letter store implementations (`DeadLetterStore` *contract* is in v1; DB-backed / file-backed / transport-native impls ship downstream)
- **Async Request-Reply** — `QueryBus::dispatchQuery` is sync-only in v1. Future async query (Future/Promise-style) is reserved as a separate `AsyncQueryBus` interface in a future v2; sync `QueryBus` stays unchanged for backward compatibility.
- **Control Bus** — admin/management messages (`pause-bus`, `replay-dlq`, `flush-stuck-pm`, `health-probe`). DLQ replay (§10) is a degenerate control-bus operation; full control-bus shipping deferred to a future ops-tooling package.
- Circuit-breaker backoff strategy (defer; the six existing strategies cover v1 needs)
- Per-handler / per-message-class retry granularity (compose from per-exception primitive + adapter wrapper if needed)
- **Resequencer** (out-of-order recovery) — consumers requiring ordering self-serialize via per-correlation-key serialization (§6 Ordering acknowledgment) or actor-mailbox semantics
- **Wire Tap** / **Smart Proxy** — addressable later via `Stamp` extension without breaking the contract; not first-class in v1
- **Claim Check** (large-payload offload) — not addressed; deferred until events with blob payloads materialize

---

## 16. Sign-off

All six brainstorm decisions (Q1–Q6) were locked during the 2026-05-07 conversation. This spec transcribes them into a contract document and addresses every gap surfaced by the four-expert round-1 + Hohpe-EIP reviews.

The locked design (all 13 round-2/EIP patches applied):

| Decision | Source |
|---|---|
| `MessageStaging`/`UnitOfWork` here (not in PM) | Architect resolution |
| In-memory staging + `MessageStagingContractTest` here | Architect |
| `TransientFailure`/`TerminalFailure` disjoint markers; default-when-neither = terminal | Udi |
| Three exception roots disjoint, each extends `RuntimeException` directly | All reviewers |
| `MessageId extends UlidValue`, producer-supplied authoritative (§6.1) | Greg |
| `MessageMetadata::forCausedMessage()` canonical propagation | Vaughn |
| `ActorRef` typed VO for actor (kind/id split) | Vaughn |
| `traceParent` / `traceState` core metadata (W3C Trace Context) | Udi |
| `schemaVersion` is wire-payload-version | Greg |
| Locator contracts ship in v1 | Udi |
| `MessageSerializer` + `DeadLetterStore` contracts ship in v1 | Udi |
| `Symfony\Component\Uid` carve-out in forbidden_imports | Vaughn |
| Delivery semantics §6 (at-least-once + producer-id + void + async-safety) | Greg |
| **`MessageInbox` consumer-side dedup contract MUST in v1** | Hohpe |
| **Ordering acknowledgment + `PerCorrelationKeyOrdered` stamp** | Hohpe |
| **`ContextStorage` indirection + `StaticStackContextStorage` default** | Hohpe + Mark |
| **`ReplayingContextStorage` sentinel for ES replay** | Greg |
| **Coroutine-isolation contract MUST + `ContextStorageContractTest`** | Mark |
| **`appendCommand(Command, ?MessageId)` overload for producer-id pathway** | Greg + Udi |
| **Top-level orphan-dispatch fallback logs WARNING** | Mark |
| **`DeadLetterReason` enum distinguishing Invalid_* from Delivery** | Hohpe |
| **`MessageMetadata::expiresAt` core metadata for TTL** | Hohpe |
| **AsyncQueryBus + Control Bus explicitly noted in §15 out-of-scope** | Hohpe |
| **`HandlerSignatureMismatchException` runtime counterpart to Psalm rules** | Vaughn |
| **`withRootContext` test helper** | Vaughn |

After sign-off → re-run the architect board for round-3 verification. After board approves → invoke `superpowers:writing-plans` to produce the implementation plan. After messaging plan is written and executed, the `nexus-ddd-process-manager` plan's Phase 2 (temporary `Contract/Messaging` stubs) is replaced with a real `nexus-actors/ddd-messaging` composer dependency.
