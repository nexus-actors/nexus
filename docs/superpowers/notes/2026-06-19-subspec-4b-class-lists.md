# Sub-spec 4b — Class enumeration notes

**Date:** 2026-06-20
**Purpose:** Task 1 of plan 4b. Resolves the spec's "top-25 front-door classes" + "~25-30 engine internals" lists from prose into concrete file paths for subsequent tasks.

## List A: Engine internals (tagged @internal in Task 2)

These classes are publicly namespaced but are implementation details of the `Behavior` / `ActorCell`
machinery. Users never construct them directly — `Behavior::same()`, `Behavior::setup()`, etc.
return them opaquely. They should carry `@internal` so phpDocumentor excludes them from the
public sidebar.

| Path | Why internal |
|---|---|
| `packages/nexus-core/src/Actor/ActorCell.php` | Runtime container for one actor's lifecycle + message loop. Implementation detail of `ActorSystem::spawn()`. |
| `packages/nexus-core/src/Actor/EmptyBehavior.php` | Sentinel returned internally before first behavior is set. Never constructed by users. |
| `packages/nexus-core/src/Actor/SameBehavior.php` | Sentinel returned by `Behavior::same()`. Users call the factory, never construct directly. |
| `packages/nexus-core/src/Actor/StoppedBehavior.php` | Sentinel returned by `Behavior::stopped()`. Users call the factory, never construct directly. |
| `packages/nexus-core/src/Actor/UnhandledBehavior.php` | Sentinel returned by `Behavior::unhandled()`. Routes to dead letters — implementation detail. |
| `packages/nexus-core/src/Actor/SupervisedBehavior.php` | Wraps a behavior under supervision restart. Created by `ActorCell` during fault recovery. |
| `packages/nexus-core/src/Actor/SetupBehavior.php` | Lazy-init wrapper returned by `Behavior::setup()`. Resolved to concrete behavior on first message. |
| `packages/nexus-core/src/Actor/ReceiveBehavior.php` | Closure-based behavior returned by `Behavior::receive()`. Holds the handler closure internally. |
| `packages/nexus-core/src/Actor/WithStashBehavior.php` | Behavior decorator that activates the stash buffer. Used by `ActorCell` internals. |
| `packages/nexus-core/src/Actor/WithStateBehavior.php` | Stateful closure behavior returned by `Behavior::withState()`. Holds state + handler. |
| `packages/nexus-core/src/Actor/WithTimersBehavior.php` | Behavior decorator that attaches a `TimerScheduler`. Managed by `ActorCell`. |
| `packages/nexus-core/src/Actor/UnstashAllBehavior.php` | Sentinel that triggers draining the stash buffer. Internal to stash machinery. |
| `packages/nexus-core/src/Actor/DefaultStashBuffer.php` | Default stash buffer implementation. Interface is public; this impl is an internal detail. |
| `packages/nexus-core/src/Actor/DefaultTimerScheduler.php` | Default timer scheduler implementation. Users access via `ActorContext::scheduleOnce()`. |
| `packages/nexus-core/src/Actor/LocalActorRef.php` | Concrete `ActorRef` for in-process actors. Users hold `ActorRef<T>` interface, not this. |
| `packages/nexus-core/src/Actor/LocalActorPath.php` | Concrete `ActorPath` for in-process paths. Implementation detail of path creation. |
| `packages/nexus-core/src/Actor/RemoteActorPath.php` | Concrete `ActorPath` for remote actors. Implementation detail of cluster path parsing. |
| `packages/nexus-core/src/Actor/DeadLetterRef.php` | Null-object `ActorRef` for undeliverable messages. Users access via `ActorSystem::deadLetters()`. |
| `packages/nexus-core/src/Actor/FutureRef.php` | Temporary `ActorRef` created for `ask()` replies. Created and destroyed per-request. |
| `packages/nexus-core/src/Actor/TaskContext.php` | Internal context used during `ask()` future resolution. Not user-facing. |
| `packages/nexus-core/src/Actor/ActorState.php` | Enum of internal lifecycle states (`New`, `Starting`, `Running`, etc.). `ActorCell` private detail. |
| `packages/nexus-core/src/Actor/ActorPathContract.php` | Internal interface shared between `LocalActorPath` and `RemoteActorPath`. Not a public API contract. |
| `packages/nexus-core/src/Actor/NullDispatcher.php` | No-op PSR-14 `EventDispatcherInterface` used when no dispatcher is provided. Internal default. |
| `packages/nexus-core/src/Message/Watch.php` | Internal system message for death-watch registration. Never sent by user code directly. |
| `packages/nexus-core/src/Message/Unwatch.php` | Internal system message to cancel death-watch. Never sent by user code directly. |
| `packages/nexus-core/src/Message/Suspend.php` | Internal system message to pause actor processing. Never sent by user code directly. |
| `packages/nexus-core/src/Message/Resume.php` | Internal system message to resume a suspended actor. Never sent by user code directly. |
| `packages/nexus-core/src/Message/Kill.php` | Internal system message for forced stop (bypasses graceful shutdown). Rarely direct-used. |
| `packages/nexus-core/src/Message/SystemMessage.php` | Marker interface for all internal system messages. Not part of user message contract. |

Total: 29 entries

## List B: Top-25 front-door classes (docblock backfill in Task 5, Reference pages in Task 6)

| # | Class | Path | Package | What it does |
|---|---|---|---|---|
| 1 | Duration | `packages/nexus-runtime/src/Duration.php` | nexus-runtime | Nanosecond-precision immutable duration value object; used everywhere timeouts and delays are expressed. |
| 2 | ActorSystem | `packages/nexus-core/src/Actor/ActorSystem.php` | nexus-core | Entry point for the actor system; creates the runtime, spawns top-level actors, and drives the event loop. |
| 3 | Behavior | `packages/nexus-core/src/Actor/Behavior.php` | nexus-core | Immutable behavior definition; factory for all behavior variants (`receive`, `setup`, `withState`, `same`, `stopped`, `unhandled`). |
| 4 | Props | `packages/nexus-core/src/Actor/Props.php` | nexus-core | Immutable spawn configuration; pairs a behavior factory with optional mailbox and supervision overrides. |
| 5 | ActorRef | `packages/nexus-core/src/Actor/ActorRef.php` | nexus-core | Type-safe reference to an actor; the only handle users hold — supports `tell()`, `ask()`, and `path()`. |
| 6 | ActorContext | `packages/nexus-core/src/Actor/ActorContext.php` | nexus-core | Runtime context injected into every handler; exposes `spawn`, `stop`, `watch`, `stash`, timers, and logging. |
| 7 | BehaviorWithState | `packages/nexus-core/src/Actor/BehaviorWithState.php` | nexus-core | Result type returned from stateful handlers; carries the next state via `next()`, `same()`, `stopped()`, or `withBehavior()`. |
| 8 | PersistenceId | `packages/nexus-persistence/src/PersistenceId.php` | nexus-persistence | Unique stable identity for a persistent actor; combines entity type and entity ID. |
| 9 | EventSourcedBehavior | `packages/nexus-persistence/src/EventSourced/EventSourcedBehavior.php` | nexus-persistence | Fluent builder for event-sourced actors; wires command handler, event handler, stores, and retention policy into a `Behavior`. |
| 10 | DurableStateBehavior | `packages/nexus-persistence/src/State/DurableStateBehavior.php` | nexus-persistence | Fluent builder for durable-state actors; persists full state snapshots instead of event history. |
| 11 | Effect | `packages/nexus-persistence/src/EventSourced/Effect.php` | nexus-persistence | Command-handler return type for event-sourced actors; encodes `persist`, `none`, `stash`, `stop`, and `reply` intents. |
| 12 | EventStore | `packages/nexus-persistence/src/Event/EventStore.php` | nexus-persistence | Interface for reading and writing event-sourced event streams; implemented by in-memory, DBAL, and Doctrine adapters. |
| 13 | Runtime | `packages/nexus-runtime/src/Runtime/Runtime.php` | nexus-runtime | Abstraction over the concurrency backend; implemented by `FiberRuntime`, `SwooleRuntime`, and `StepRuntime`. |
| 14 | MailboxConfig | `packages/nexus-runtime/src/Mailbox/MailboxConfig.php` | nexus-runtime | Value object configuring mailbox capacity and overflow strategy; passed via `Props::withMailbox()`. |
| 15 | Envelope | `packages/nexus-core/src/Mailbox/Envelope.php` | nexus-core | Immutable message wrapper carrying the message, sender path, target path, and metadata through the mailbox. |
| 16 | Signal | `packages/nexus-core/src/Lifecycle/Signal.php` | nexus-core | Marker interface for lifecycle signals (`PreStart`, `PostStop`, `Terminated`, `ChildFailed`); handled via `behavior->onSignal()`. |
| 17 | HttpApp | `packages/nexus-http/src/Dsl/HttpApp.php` | nexus-http | DSL entry point for building HTTP applications; produces a `RouteBuilder` to register routes and middleware. |
| 18 | RouteBuilder | `packages/nexus-http/src/Dsl/RouteBuilder.php` | nexus-http | Fluent route registration DSL; maps HTTP methods + paths to handler closures and compiles to a `CompiledHttpApp`. |
| 19 | CompiledHttpApp | `packages/nexus-http/src/App/CompiledHttpApp.php` | nexus-http | Immutable compiled route table; the PSR-15 `RequestHandlerInterface` dispatched by Swoole or Fiber HTTP server. |
| 20 | WebSocketHandler | `packages/nexus-http-ws/src/WebSocket/WebSocketHandler.php` | nexus-http-ws | Interface for WebSocket connection handlers; implement `onOpen`, `onMessage`, `onClose` to define WS behaviour. |
| 21 | WebSocketContext | `packages/nexus-http-ws/src/WebSocket/WebSocketContext.php` | nexus-http-ws | Context injected into `WebSocketHandler` callbacks; provides `send()`, `close()`, and connection metadata. |
| 22 | AuthenticationMiddleware | `packages/nexus-http-auth/src/Middleware/AuthenticationMiddleware.php` | nexus-http-auth | PSR-15 middleware that authenticates requests and populates the identity into the request attribute bag. |
| 23 | RequiresAuth | `packages/nexus-http-auth/src/Attribute/RequiresAuth.php` | nexus-http-auth | PHP attribute that enforces authentication on a route; sibling attributes `RequiresRole` and `RequiresScope` cover authorisation. |
| 24 | WorkerNode | `packages/nexus-worker-pool/src/WorkerNode.php` | nexus-worker-pool | Per-worker coordinator in a worker-pool deployment; routes messages via consistent hash ring and manages local actor refs. |
| 25 | NexusApp | `packages/nexus-app/src/NexusApp.php` | nexus-app | Fluent bootstrap entry point; registers named actors, lifecycle hooks, and boots the full system with a single `run()` call. |

Total: 25 entries

## Cross-check

- No class appears in both lists: ✓ (all List A entries are nexus-core internals; List B spans 8 packages including nexus-core's public API surface)
- All file paths exist (verified via `find` and `ls` during discovery): ✓
- List A omissions noted:
  - `StashBuffer.php` (interface) — deferred; interfaces are generally public API even if the impl is internal. `DefaultStashBuffer.php` (the impl) is tagged internal.
  - `TimerScheduler.php` (interface) — deferred for same reason; `DefaultTimerScheduler.php` (impl) is tagged internal.
  - `ActorHandler.php`, `StatefulActorHandler.php`, `AbstractActor.php` — public extension points users implement; NOT internal.
  - `ActorPath.php`, `ActorPathContract.php` — `ActorPath` is a public interface (used in `ActorRef::path()`); `ActorPathContract.php` is an internal shared interface, included in List A.
  - `Attribute/ReplyType.php` — user-facing PHP attribute for `ask()` type hints; NOT internal.
  - `SupervisionStrategy.php`, `Directive.php`, `StrategyType.php` — public supervision API; NOT internal.
  - `DeadLetter.php` (Message/) — included in List A as a system message; distinguished from `DeadLetterRef` which is also internal.
  - `PoisonPill.php` — omitted from List A: users DO send PoisonPill directly for graceful shutdown; it's documented public API.
