---
sidebar_position: 1
title: nexus-core
---

# nexus-core

The core package contains all actor system abstractions, behaviors, supervision,
mailboxes, lifecycle signals, and the `ActorSystem` entry point. It has no
runtime dependency -- concurrency is delegated to whichever `Runtime`
implementation is injected.

**Composer:** `monadial/nexus-core`

## Actor namespace

`Monadial\Nexus\Core\Actor\`

| Class / Interface | Description |
|---|---|
| `ActorRef<T>` | Interface for sending messages to an actor. Methods: `tell(T)`, `ask(callable, Duration): R`, `path(): ActorPath`, `isAlive(): bool`. |
| `ActorContext<T>` | Interface passed to behavior handlers. Provides `self()`, `parent()`, `spawn()`, `stop()`, `child()`, `children()`, `watch()`, `unwatch()`, `scheduleOnce()`, `scheduleRepeatedly()`, `stash()`, `unstashAll()`, `log()`, `sender()`. |
| `ActorSystem` | Entry point. Created via `ActorSystem::create(string $name, Runtime $runtime)`. Spawns top-level actors under `/user`, manages the runtime lifecycle, and provides the dead-letter endpoint. |
| `Behavior<T>` | Immutable behavior definition. Factory methods: `receive(Closure)`, `withState(mixed, Closure)`, `setup(Closure)`, `same()`, `stopped()`, `unhandled()`, `empty()`. Instance method: `onSignal(Closure)`. |
| `BehaviorWithState<T, S>` | Result of a stateful behavior handler. Factory methods: `next(S)`, `same()`, `stopped()`, `withBehavior(Behavior, S)`. |
| `Props<T>` | Actor configuration. Factory methods: `fromBehavior(Behavior)`, `fromFactory(callable)`, `fromContainer(ContainerInterface, string)`, `fromStatefulFactory(callable)`. Instance methods: `withMailbox(MailboxConfig)`, `withSupervision(object)`. |
| `ActorHandler<T>` | Interface for class-based actors. Single method: `handle(ActorContext, T): Behavior`. |
| `StatefulActorHandler<T, S>` | Interface for stateful class-based actors. Methods: `initialState(): S`, `handle(ActorContext, T, S): BehaviorWithState`. |
| `AbstractActor<T>` | Base class implementing `ActorHandler` with optional lifecycle hooks: `onPreStart(ActorContext)`, `onPostStop(ActorContext)`. |
| `ActorPath` | Immutable hierarchical path (e.g., `/user/orders/order-123`). Methods: `root()`, `fromString(string)`, `child(string)`, `name()`, `parent()`, `equals()`, `isChildOf()`, `isDescendantOf()`, `depth()`. |
| `ActorCell<T>` | Internal engine per actor. Manages behavior evaluation, state machine transitions, children, stash buffer, and supervision. Implements `ActorContext<T>`. |
| `LocalActorRef<T>` | In-process `ActorRef` that delivers messages via a `Mailbox`. |
| `DeadLetterRef` | Null-object `ActorRef` that captures undeliverable messages. Always returns `false` from `isAlive()`. |
| `ActorState` | Enum: `New`, `Starting`, `Running`, `Suspended`, `Stopping`, `Stopped`. |
| `BehaviorTag` | Enum: `Receive`, `WithState`, `Setup`, `Same`, `Stopped`, `Unhandled`, `Empty`. |
| `Cancellable` | Interface for cancelling scheduled operations. Methods: `cancel()`, `isCancelled()`. |

## Mailbox namespace

`Monadial\Nexus\Core\Mailbox\`

| Class / Interface | Description |
|---|---|
| `Mailbox` | Interface for actor mailboxes. Methods: `enqueue(Envelope): EnqueueResult`, `dequeue(): Option<Envelope>`, `dequeueBlocking(Duration): Envelope`, `count()`, `isFull()`, `isEmpty()`, `close()`. |
| `MailboxConfig` | Immutable configuration. Factory methods: `bounded(int $capacity, OverflowStrategy)`, `unbounded()`. Instance methods: `withCapacity(int)`, `withStrategy(OverflowStrategy)`. |
| `Envelope` | Immutable message wrapper. Properties: `message`, `sender` (ActorPath), `target` (ActorPath), `metadata` (array). Factory: `of(object, ActorPath, ActorPath)`. |
| `EnqueueResult` | Enum: `Accepted`, `Dropped`, `Backpressured`. |
| `OverflowStrategy` | Enum: `DropNewest`, `DropOldest`, `Backpressure`, `ThrowException`. |

## Supervision namespace

`Monadial\Nexus\Core\Supervision\`

| Class / Interface | Description |
|---|---|
| `SupervisionStrategy` | Immutable strategy configuration. Factory methods: `oneForOne(int $maxRetries, ?Duration $window, ?Closure $decider)`, `allForOne(...)`, `exponentialBackoff(Duration $initialBackoff, Duration $maxBackoff, int $maxRetries, float $multiplier, ?Closure $decider)`. Instance method: `decide(Throwable): Directive`. |
| `Directive` | Enum: `Restart`, `Stop`, `Resume`, `Escalate`. |
| `StrategyType` | Enum: `OneForOne`, `AllForOne`, `ExponentialBackoff`. |

## Lifecycle namespace

`Monadial\Nexus\Core\Lifecycle\`

| Class / Interface | Description |
|---|---|
| `Signal` | Marker interface for lifecycle signals. |
| `PreStart` | Signal delivered after an actor starts. |
| `PostStop` | Signal delivered before an actor stops. |
| `PreRestart` | Signal delivered before an actor restarts. |
| `PostRestart` | Signal delivered after an actor restarts. |
| `ChildFailed` | Signal delivered to a parent when a child actor fails. |
| `Terminated` | Signal delivered when a watched actor stops. |

## Message namespace

`Monadial\Nexus\Core\Message\`

| Class / Interface | Description |
|---|---|
| `SystemMessage` | Marker interface for system-level messages. |
| `PoisonPill` | System message that initiates graceful actor shutdown. |
| `Kill` | System message that forces immediate actor termination. |
| `Suspend` | System message that transitions an actor to the suspended state. |
| `Resume` | System message that transitions a suspended actor back to running. |
| `Watch` | System message that registers a watcher for death notifications. |
| `Unwatch` | System message that removes a watcher registration. |
| `DeadLetter` | Wraps an undeliverable message with the original sender and intended recipient. |

## Exception namespace

`Monadial\Nexus\Core\Exception\`

| Class | Description |
|---|---|
| `NexusException` | Base exception for all Nexus errors. |
| `ActorException` | Base exception for actor-related errors. |
| `ActorInitializationException` | Thrown when actor setup fails. |
| `AskTimeoutException` | Thrown when an `ask()` call exceeds its timeout. |
| `MailboxException` | Base exception for mailbox errors. |
| `MailboxClosedException` | Thrown when enqueuing or dequeuing from a closed mailbox. |
| `MailboxOverflowException` | Thrown when a bounded mailbox overflows with the `ThrowException` strategy. |
| `MaxRetriesExceededException` | Thrown when supervision retry limits are exceeded. |
| `InvalidActorPathException` | Thrown for malformed actor path strings. |
| `InvalidActorStateTransition` | Thrown for illegal state machine transitions. |
| `InvalidBehaviorException` | Thrown for invalid behavior configurations. |
| `InvalidMailboxConfigException` | Thrown for invalid mailbox configurations. |
| `NexusLogicException` | Thrown for programming errors (extends `LogicException`). |

## Duration

`Monadial\Nexus\Core\Duration`

Nanosecond-precision, immutable duration value object. Implements `Stringable`.

**Factory methods:**

```php
Duration::seconds(5);
Duration::millis(500);
Duration::micros(1000);
Duration::nanos(1_000_000);
Duration::zero();
```

**Arithmetic:**

```php
$a = Duration::seconds(1);
$b = Duration::millis(500);

$a->plus($b);         // 1s 500ms
$a->minus($b);        // 500ms
$a->multipliedBy(3);  // 3s
$a->dividedBy(2);     // 500ms
```

**Conversions:** `toNanos()`, `toMicros()`, `toMillis()`, `toSeconds()`, `toSecondsFloat()`.

**Comparisons:** `equals()`, `isGreaterThan()`, `isLessThan()`, `isZero()`, `compareTo()`.

## Pipe functions

`Monadial\Nexus\Core\Actor\Functions\`

Pipe-friendly functions for composing `Props`:

```php
use function Monadial\Nexus\Core\Actor\Functions\withMailbox;
use function Monadial\Nexus\Core\Actor\Functions\withSupervision;

// Designed for use with PHP's pipe operator:
// $behavior |> Props::fromBehavior(...) |> withMailbox($config) |> withSupervision($strategy)

$props = Props::fromBehavior($behavior)
    ->withMailbox(MailboxConfig::bounded(100))
    ->withSupervision(SupervisionStrategy::oneForOne());
```

- **`withMailbox(MailboxConfig): Closure(Props): Props`** -- Returns a closure that applies a mailbox config to Props.
- **`withSupervision(SupervisionStrategy): Closure(Props): Props`** -- Returns a closure that applies a supervision strategy to Props.
