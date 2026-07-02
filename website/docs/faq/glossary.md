---
title: Glossary
related:
  - faq/overview
  - core-concepts/actors
  - core-concepts/behaviors
---

# Glossary

A-Z reference for actor model and Nexus-specific terminology. Class names link to their API documentation via the auto-link plugin when wrapped in backticks.

---

### Actor

The fundamental unit of computation in the actor model. An actor has a mailbox, a behavior, and optional state. It processes one message at a time, never shares memory with other actors, and communicates only by sending messages. In Nexus every actor is backed by a `ActorCell` and exposed externally only through an `ActorRef`.

### ActorRef

A typed, location-transparent reference to an actor. `ActorRef<T>` carries the message type `T` so Psalm can verify that only compatible messages are sent. An `ActorRef` may point to a local actor (`LocalActorRef`), a cross-worker actor (`WorkerActorRef`), or a dead actor (`DeadLetterRef`) — callers cannot distinguish these from the outside. Obtain an `ActorRef` by calling `spawn()` or `spawnAnonymous()` on `ActorSystem` or `ActorContext`.

### ActorSystem

The top-level coordinator. `ActorSystem` owns the root supervision tree, the dead-letter sink, the scheduler, and the runtime event loop. Create one per process: `ActorSystem::create('name', $runtime)`. All top-level actors are spawned on the system; they become children of the system guardian.

### Ask pattern

A request-reply interaction where the caller sends a message and suspends (via a PHP Fiber or Swoole coroutine) until the target actor replies or the timeout elapses. Implemented via `ActorRef::ask(callable $requestFactory, Duration $timeout)`. The caller fiber suspends without blocking the event loop; other actors continue processing. Prefer `tell()` for one-way fire-and-forget; reserve the ask pattern for places where the calling code genuinely needs a synchronous-looking result.

### Backpressure

A flow-control signal that tells the sender to slow down or pause because a downstream resource (mailbox, channel, or network buffer) is full. In Nexus, `OverflowStrategy::Backpressure` causes `enqueue()` to return `Backpressured` rather than accepting or dropping the message. The sender is responsible for deciding what to do — typically by applying its own rate-limiting or stashing the message.

### Behavior

A description of how an actor handles a single message, expressed as a pure function `(ActorContext<T>, T) => Behavior<T>`. The return value replaces the current behavior for the next message, making state transitions explicit. Nexus provides `Behavior::receive()`, `Behavior::withState()`, `Behavior::setup()`, `Behavior::same()`, `Behavior::stopped()`, and `Behavior::unhandled()` as factory methods.

### Bounded mailbox

A mailbox with a finite capacity. When the mailbox is full, new messages are handled according to the configured `OverflowStrategy`: `DropNewest`, `DropOldest`, `Backpressure`, or `ThrowException`. Use `MailboxConfig::bounded(capacity: 1000, strategy: OverflowStrategy::DropNewest)` to configure one. Bounded mailboxes protect against unbounded memory growth but require careful capacity planning.

### Child actor

An actor spawned by another actor via `ActorContext::spawn()`. The spawning actor becomes the parent and is responsible for supervising the child. When the parent stops, all its children are stopped first. A child's path is a sub-path of its parent: `/system/parent/child`.

### Cluster (Experimental)

The `nexus-cluster` package provides contracts (`ClusterTransport`, `NodeDirectory`, `NodeHashRing`, `NodeAddress`) for routing messages across multiple PHP processes or machines. The TCP transport implementation is not yet shipped; the package defines the interfaces so application code can be written against the contracts today. Do not use in production without a custom transport implementation.

### Dead letter

A message that could not be delivered to its intended recipient — because the target actor stopped, the mailbox was closed, or the `ActorRef` was already a `DeadLetterRef`. Undeliverable messages are routed to the actor system's dead-letter sink. Monitor dead letters during development to detect mis-routed messages or premature actor shutdown.

### Death watch

A subscription mechanism by which one actor registers interest in another's termination. Call `ActorContext::watch($ref)` to register; the watching actor receives a `Terminated` signal when the watched actor stops. Call `unwatch($ref)` to cancel. Use death watch to build parent–child-like relationships between actors that are not in the same supervision tree.

### Durable state

A persistence model where the actor's current state is stored as a single snapshot on every change, with no event history. Simpler than event sourcing when you need persistence but do not need a full audit log. Implemented via `DurableStateBehavior`; the state store is queried at startup to restore the latest snapshot. Effects are expressed via `DurableEffect::persist($newState)`, `none()`, `stash()`, `stop()`, and `reply()`.

### Effect

The return type from a persistence command handler. `Effect` describes what the actor wants to do: `Effect::persist(...$events)` to emit domain events, `Effect::none()` to do nothing, `Effect::stash()` to buffer the message until recovery completes, `Effect::stop()` to stop the actor, or `Effect::reply($to, $msg)` to send a response. Side effects are attached via `->thenRun()` and `->thenReply()` and execute only after the event is durably stored.

### Envelope

The wire-level wrapper around a user message. `Envelope` carries the message, the sender's `ActorPath`, the target `ActorPath`, and optional metadata (correlation ID, timestamp). The mailbox operates on `Envelope` objects. Serialization packages serialize the envelope structure around the serialized message when crossing process boundaries.

### Event sourcing

A persistence model where the actor's state is derived by replaying a sequence of immutable domain events stored in an event store. The current state is never stored directly; it is rebuilt on startup by replaying all events (or from a snapshot plus subsequent events). Implemented via `EventSourcedBehavior`. Provides a full audit log and enables temporal queries and event-driven projections.

### Fiber

A PHP 8.1+ cooperative multitasking primitive (`\Fiber`). Each actor in `FiberRuntime` runs in its own Fiber. The Fiber suspends when waiting for a message (`dequeueBlocking` suspends the Fiber, returning control to the scheduler), and resumes when the mailbox has data. Fibers are not threads — they run on a single OS thread and yield cooperatively. Do not confuse with Swoole coroutines, which run on a thread pool with true async I/O.

### Future

A value that will be resolved at some point in the future. In Nexus, `ActorRef::ask()` returns the result synchronously from the caller's perspective (the Fiber suspends), so there is no explicit `Future` type in userland. The term appears in the actor model literature to describe the placeholder for a pending ask result. See [core-concepts/futures](../core-concepts/futures.md) for the Nexus-specific implementation.

### Handler

An object that processes messages for an actor. Nexus supports two handler styles: closure-based (the `Behavior::receive()` family) and class-based (`ActorHandler`, `StatefulActorHandler`, `AbstractActor`). Class-based handlers are useful when the actor needs constructor-injected dependencies or lifecycle callbacks beyond `onSignal`.

### Hash ring

A consistent-hashing data structure that maps actor names to worker IDs (within a worker pool) or `NodeAddress` values (within a cluster). Nexus uses `ConsistentHashRing` with 150 virtual nodes per real node, distributing actors evenly while minimising redistribution when workers are added or removed. The same actor name always maps to the same worker as long as the worker count is unchanged.

### Mailbox

The message queue attached to each actor. `Mailbox` is an interface with two implementations: unbounded (backed by a PHP `SplQueue` in FiberRuntime, or a Swoole channel) and bounded. The actor runtime dequeues envelopes from the mailbox in a loop; the actor's behavior processes one envelope at a time. Closing the mailbox wakes all blocking dequeue waiters and prevents further enqueues.

### Message

An immutable object sent between actors. By convention (and enforced by the Psalm plugin's `ReadonlyMessageRule`) messages are `readonly class` instances. Messages carry data only; they do not contain behaviour. Name messages after what happened (`OrderPlaced`) or what is requested (`PlaceOrder`), not after the technical operation.

### Passivation

The voluntary shutdown of an idle actor to reclaim memory, with automatic restart on the next incoming message. The actor calls `ActorContext::stop(ActorContext::self())` (or the persistence layer calls it after an idle timeout), and the parent re-spawns it on demand. Passivation is the primary tool for keeping memory usage bounded in systems with large numbers of potentially-idle actors.

### PersistenceId

A globally unique identifier for a persistent actor's event stream or state record. Constructed as `PersistenceId::of('EntityType', 'entity-id')` — e.g., `PersistenceId::of('Order', $orderId)`. The persistence ID is used as the partition key in the event store and snapshot store. Choose stable IDs: changing a persistence ID after events are stored means recovery will start from an empty state.

### PoisonPill

A system message that instructs an actor to stop gracefully. When the actor processes a `PoisonPill` from its mailbox, it stops its children, delivers the `PostStop` signal, and closes its mailbox. `PoisonPill` is processed in mailbox order — all previously enqueued user messages are handled first. For immediate termination, call `ActorContext::stop($ref)` from the parent.

### Props

An immutable spawn configuration for an actor. `Props<T>` carries the behavior factory, the mailbox configuration, and the supervision strategy. Factories: `Props::fromBehavior()`, `Props::fromFactory()`, `Props::fromStatefulFactory()`, `Props::fromContainer()`. Props are passed to `spawn()` and may be reused to spawn multiple actors with identical configuration.

### Receive timeout

A duration after which an actor that has not received a user message receives a `ReceiveTimeout` signal. Set via `ActorContext::setReceiveTimeout(Duration $timeout)`. Cancel by passing `Duration::zero()`. Use receive timeout to trigger passivation or to detect stuck workflows.

### Recovery

The startup phase of a persistent actor where stored events or state are replayed to rebuild the in-memory state before the actor begins handling new commands. Managed by `PersistenceEngine` (event sourcing) or `DurableStateEngine` (durable state). During recovery the actor's command handler is not called; only the event handler (for event sourcing) or a state-restore callback (for durable state) runs.

### Restart

A supervision directive that stops the failed actor, discards its in-memory state, re-runs its `Props` factory to create a fresh instance, and delivers `PreStart`. Children spawned before the failure are stopped. The restarted actor starts with a clean slate. Configure restart limits via `SupervisionStrategy::oneForOne(maxRetries: 3, within: Duration::seconds(10))`.

### Runtime

The concurrency backend for an actor system. `Runtime` is an interface that abstracts mailbox creation, fiber/coroutine spawning, scheduling, and cooperative yielding. Three implementations ship: `FiberRuntime` (PHP Fibers, single thread), `SwooleRuntime` (Swoole coroutines, async I/O), and `StepRuntime` (deterministic, for tests). Pass a `Runtime` instance to `ActorSystem::create()`.

### Saga

A long-running business transaction implemented as a stateful actor that coordinates a sequence of steps across multiple services or actors. Each step is a message send; compensating actions are sent when a step fails. Nexus sagas are plain actors — there is no dedicated saga framework. Use `Behavior::withState()` or `EventSourcedBehavior` to track saga progress durably.

### Sender

The `ActorRef` of the actor that sent the current message, available via `ActorContext::sender()`. Returns `Option<ActorRef>` because not all messages have a traceable sender (e.g., scheduled messages). Use sender to reply without coupling the handler to the reply-to address: `$ctx->sender()->map(fn($s) => $s->tell(new Reply(...)))`.

### Signal

A lifecycle notification delivered to an actor outside the normal message queue. Signals include `PreStart` (actor started), `PostStop` (actor stopped), `Terminated` (watched actor stopped), `ChildFailed` (child threw), and `ReceiveTimeout` (idle timeout elapsed). Handle signals by attaching an `onSignal` callback to a `Behavior`: `$behavior->onSignal(fn(ActorContext $ctx, Signal $sig) => ...)`.

### Single-writer

A consistency guarantee enforced by the persistence layer: only one `ActorSystem` (identified by its ULID writer ID) may write to a given `PersistenceId` at a time. If two writer IDs are detected in the same event stream, `WriterConflictException` is thrown (in `Fail` mode). Configure the response via `ReplayFilter` modes: `Fail`, `Warn`, `RepairByDiscardOld`, or `Off`. See [persistence/single-writer](../persistence/single-writer.md).

### Snapshot

A point-in-time capture of an event-sourced actor's state, stored to speed up recovery. Instead of replaying all events from the beginning of time, recovery loads the latest snapshot and replays only the events that followed it. Configure snapshot frequency with `SnapshotStrategy::everyN(10)`. Retention is controlled by `RetentionPolicy`.

### Stash

A temporary buffer inside the actor for messages that cannot be processed right now — typically during initialization or recovery. Call `ActorContext::stash()` to move the current message to the stash buffer; call `unstashAll()` to prepend all stashed messages back to the front of the mailbox for reprocessing. The stash is bounded by the mailbox capacity.

### Supervision

The mechanism by which a parent actor manages failures in its children. When a child throws an unhandled exception, the parent's supervision strategy determines the directive: `Restart`, `Stop`, `Resume`, or `Escalate`. Nexus implements "let it crash" — actors are expected to fail occasionally, and supervision trees contain the blast radius. Configure via `Props::withSupervision(SupervisionStrategy::oneForOne(...))`.

### Swoole

An async PHP extension that provides coroutines, non-blocking I/O, and a built-in HTTP server. `SwooleRuntime` uses Swoole coroutines as the concurrency primitive instead of PHP Fibers. Swoole enables true async network I/O, making it the preferred runtime for production HTTP workloads. Requires PHP compiled with Swoole 6.0+ (and ZTS for the worker pool).

### Tell

The fundamental message-passing primitive: `ActorRef::tell(object $message): void`. Fire-and-forget — the caller does not wait for a result. `tell()` enqueues the message in the target's mailbox and returns immediately. It is always non-blocking from the caller's perspective. Prefer `tell()` over `ask()` for all messages where the caller does not need a reply.

### Worker pool

A set of PHP threads (backed by Swoole ZTS) each running an independent `ActorSystem`. Actors are distributed across workers via a consistent hash ring so each actor always runs on the same worker. The `WorkerNode` coordinates routing, and `WorkerActorRef` sends `Envelope` objects directly via `WorkerTransport` without serialization overhead. See [scaling/overview](../scaling/overview.md).

### ZTS

Zend Thread Safety — a PHP build mode that enables true multi-threading. Required for `nexus-worker-pool-swoole` because `Swoole\Thread\Pool` and `Swoole\Thread\Queue` require a ZTS PHP binary. Verified at startup: `WorkerRunnable` asserts `PHP_ZTS === 1` before booting. See [operations/zts-php-setup](../operations/zts-php-setup.md) for installation instructions.
