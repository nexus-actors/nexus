---
sidebar_position: 2
title: "Behaviors"
---

# Behaviors

A `Behavior<T>` defines how an actor processes messages. Behaviors are immutable value objects -- when an actor handles a message, it returns a new behavior that will be used for the next message. This model enables actors to change their message-processing logic over time without mutable state.

## Behavior

`Behavior<T>` is a `final readonly class` with several static factory methods. The template parameter `T` represents the message protocol the actor handles.

### Behavior::receive

The primary way to define a behavior. The closure receives the `ActorContext` and the message, and returns the next `Behavior`.

```php
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;

readonly class Greet
{
    public function __construct(public string $name) {}
}

/** @var Behavior<Greet> */
$behavior = Behavior::receive(
    static function (ActorContext $ctx, Greet $msg): Behavior {
        $ctx->log()->info("Hello, {$msg->name}!");

        return Behavior::same();
    },
);
```

Signature:

```php
/**
 * @template U of object
 * @param \Closure(ActorContext<U>, U): Behavior<U> $handler
 * @return Behavior<U>
 */
public static function receive(Closure $handler): self;
```

### Behavior::withState

Creates a stateful behavior. The closure receives the context, message, and current state, and returns a `BehaviorWithState` that carries the updated state.

```php
use Monadial\Nexus\Core\Actor\BehaviorWithState;

readonly class Increment {}
readonly class Decrement {}

/** @var Behavior<Increment|Decrement> */
$behavior = Behavior::withState(0, static function (
    ActorContext $ctx,
    object $msg,
    int $count,
): BehaviorWithState {
    return match (true) {
        $msg instanceof Increment => BehaviorWithState::next($count + 1),
        $msg instanceof Decrement => BehaviorWithState::next($count - 1),
        default => BehaviorWithState::same(),
    };
});
```

Signature:

```php
/**
 * @template U of object
 * @template S
 * @param S $initialState
 * @param \Closure(ActorContext<U>, U, S): BehaviorWithState<U, S> $handler
 * @return Behavior<U>
 */
public static function withState(mixed $initialState, Closure $handler): self;
```

### Behavior::setup

Runs an initialization function before the actor starts processing messages. The factory closure receives the context and returns the behavior the actor will use. This is the right place to spawn children, start timers, or perform other setup work.

```php
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;

readonly class Tick {}

$behavior = Behavior::setup(function (ActorContext $ctx): Behavior {
    // Spawn a child during initialization
    $child = $ctx->spawn(Props::fromBehavior($childBehavior), 'worker');
    $ctx->watch($child);

    // Start a periodic timer
    $ctx->scheduleRepeatedly(
        Duration::seconds(0),
        Duration::seconds(10),
        new Tick(),
    );

    return Behavior::receive(
        static fn (ActorContext $c, object $msg): Behavior => Behavior::same(),
    );
});
```

Signature:

```php
/**
 * @template U of object
 * @param \Closure(ActorContext<U>): Behavior<U> $factory
 * @return Behavior<U>
 */
public static function setup(Closure $factory): self;
```

### Behavior::same

Tells the actor system to keep the current behavior unchanged. Use this when a message does not require a behavior change.

```php
return Behavior::same();
```

### Behavior::stopped

Tells the actor system to stop this actor. The actor will process its `PostStop` signal and then terminate.

```php
readonly class Shutdown {}

$behavior = Behavior::receive(
    static fn (ActorContext $ctx, object $msg): Behavior => match (true) {
        $msg instanceof Shutdown => Behavior::stopped(),
        default => Behavior::same(),
    },
);
```

### Behavior::unhandled

Signals that the actor does not handle this particular message. The message is forwarded to dead letters.

```php
$behavior = Behavior::receive(
    static fn (ActorContext $ctx, object $msg): Behavior => match (true) {
        $msg instanceof SupportedMessage => Behavior::same(),
        default => Behavior::unhandled(),
    },
);
```

### Behavior::empty

Creates a behavior with no handler. Useful as a placeholder or for actors that only respond to signals.

```php
$behavior = Behavior::empty();
```

## Concrete Behavior types

Every factory method returns a specific concrete subclass. The runtime dispatches on
these types internally, but user code can also narrow them with `instanceof` for
introspection or testing.

| Class | Returned by | Description |
|---|---|---|
| `ReceiveBehavior<T>` | `Behavior::receive()` | Stateless message handler with an optional signal handler |
| `WithStateBehavior<T, S>` | `Behavior::withState()` | Stateful handler; carries `$initialState` and the handler closure |
| `SetupBehavior<T>` | `Behavior::setup()` | Factory wrapper; the inner closure runs once at actor startup |
| `SameBehavior<T>` | `Behavior::same()` | Sentinel — keep the current behavior unchanged |
| `StoppedBehavior<T>` | `Behavior::stopped()` | Sentinel — stop the actor gracefully |
| `UnhandledBehavior<T>` | `Behavior::unhandled()` | Sentinel — route the current message to dead letters |
| `EmptyBehavior<T>` | `Behavior::empty()` | Sentinel — silently discard all messages |
| `SupervisedBehavior<T>` | `Behavior::supervise()` | Wrapper that installs a `SupervisionStrategy` for the inner behavior |
| `WithTimersBehavior<T>` | `Behavior::withTimers()` | Wrapper that provides a `TimerScheduler` to its factory closure |
| `WithStashBehavior<T>` | `Behavior::withStash()` | Wrapper that provides a `StashBuffer` to its factory closure |
| `UnstashAllBehavior<T>` | `StashBuffer::unstashAll()` | Internal — carries stashed envelopes to replay; produced by stash buffer, not user code |

All 11 classes are `final` and `readonly`. Signal handlers are attached via `->onSignal()` on
any concrete instance and return a new instance with the handler wired in.

```php
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\ReceiveBehavior;
use Monadial\Nexus\Core\Actor\WithStateBehavior;

$b = Behavior::receive(static fn ($ctx, $msg): Behavior => Behavior::same());
assert($b instanceof ReceiveBehavior);    // true

$s = Behavior::withState(0, static fn ($ctx, $msg, $state) => BehaviorWithState::same());
assert($s instanceof WithStateBehavior);  // true
```

## Composable behavior wrappers

Nexus provides composable behavior wrappers inspired by Akka Typed. These wrappers inject resources (timers, stash buffers) into behavior factories and can be nested to compose multiple capabilities.

### Behavior::withTimers

Provides a `TimerScheduler` for keyed timer management. Timers are identified by string keys -- starting a timer with an existing key auto-cancels the previous one. All timers are automatically cancelled when the actor stops.

```php
use Monadial\Nexus\Core\Actor\TimerScheduler;
use Monadial\Nexus\Runtime\Duration;

readonly class Heartbeat {}

$behavior = Behavior::withTimers(
    static function (TimerScheduler $timers): Behavior {
        $timers->startTimerWithFixedDelay('heartbeat', new Heartbeat(), Duration::seconds(5));

        return Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => match (true) {
                $msg instanceof Heartbeat => handleHeartbeat($ctx),
                default => Behavior::unhandled(),
            },
        );
    },
);
```

`TimerScheduler` methods:

| Method | Description |
|---|---|
| `startSingleTimer(key, msg, delay)` | Single-fire timer. Auto-cancels previous timer with same key. |
| `startTimerAtFixedRate(key, msg, interval, ?initialDelay)` | Repeating timer at fixed rate (drift-compensating). |
| `startTimerWithFixedDelay(key, msg, delay, ?initialDelay)` | Repeating timer with fixed delay between completions. |
| `cancel(key)` | Cancel a timer by key. No-op if key does not exist. |
| `cancelAll()` | Cancel all active timers. |
| `isTimerActive(key)` | Check whether a timer with this key is currently active. |

### Behavior::withStash

Provides a bounded `StashBuffer` for deferring messages. Unlike the basic `$ctx->stash()`, this buffer has an explicit capacity and supports inline replay -- `unstashAll()` processes stashed messages through the new behavior immediately, before any new messages from the mailbox.

```php
use Monadial\Nexus\Core\Actor\StashBuffer;
use Monadial\Nexus\Core\Mailbox\Envelope;

readonly class DbReady {
    public function __construct(public object $connection) {}
}

$behavior = Behavior::withStash(
    100,
    static function (StashBuffer $stash): Behavior {
        return Behavior::receive(
            static function (ActorContext $ctx, object $msg) use ($stash): Behavior {
                if ($msg instanceof DbReady) {
                    return $stash->unstashAll(activeBehavior($msg->connection));
                }

                // Not ready yet -- stash the envelope
                $stash->stash(Envelope::of($msg, ActorPath::root(), $ctx->path()));

                return Behavior::same();
            },
        );
    },
);
```

`StashBuffer` methods:

| Method | Description |
|---|---|
| `stash(envelope)` | Stash an envelope. Throws `StashOverflowException` if the buffer is full. |
| `unstashAll(targetBehavior)` | Replay all stashed messages through the target behavior inline, then continue with it. Returns the target directly if the buffer is empty. |
| `isEmpty()` | Whether the buffer has no stashed messages. |
| `isFull()` | Whether the buffer has reached capacity. |
| `size()` | Number of currently stashed messages. |
| `capacity()` | Maximum buffer capacity. |

### Behavior::supervise

Wraps a behavior with a supervision strategy. When the inner behavior's handler throws, the behavior-level strategy decides the response. If the behavior-level strategy returns `Escalate`, it falls through to the Props-level strategy (set via `Props::withSupervision`).

```php
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Supervision\Directive;

$behavior = Behavior::supervise(
    Behavior::receive(
        static fn(ActorContext $ctx, object $msg): Behavior => handleMessage($ctx, $msg),
    ),
    SupervisionStrategy::oneForOne(
        maxRetries: 5,
        decider: fn(Throwable $e) => match (true) {
            $e instanceof RecoverableError => Directive::Restart,
            default => Directive::Escalate,
        },
    ),
);
```

Supervision precedence:

1. Behavior-level strategy decides first (from `Behavior::supervise`)
2. If `Escalate` -- falls through to Props-level strategy (from `Props::withSupervision`)
3. If Props-level also escalates -- parent actor handles it

### Nesting wrappers

All wrappers compose naturally via nesting. The resolution order during actor startup is outside-in:

```php
$behavior = Behavior::setup(static function (ActorContext $ctx) {
    $db = $ctx->spawn(Props::fromBehavior($dbBehavior), 'db');

    return Behavior::withTimers(static function (TimerScheduler $timers) use ($db) {
        return Behavior::withStash(100, static function (StashBuffer $stash) use ($timers, $db) {
            $timers->startSingleTimer('health', new HealthCheck(), Duration::seconds(30));

            return Behavior::supervise(
                Behavior::receive(
                    static fn(ActorContext $ctx, object $msg): Behavior => match (true) {
                        $msg instanceof HealthCheck => handleHealth($ctx, $db),
                        default => Behavior::unhandled(),
                    },
                ),
                SupervisionStrategy::oneForOne(maxRetries: 5),
            );
        });
    });
});
```

The nesting order does not change the behavior as long as there is no additional logic in any function other than the innermost one. When combining with `supervise`, note that it wraps the behavior it contains -- a restart will not re-execute the `setup` or `withTimers` factories.

## Signal handling

Signals are lifecycle events delivered to an actor outside the normal message flow. Attach a signal handler to any behavior using `onSignal()`. The method returns a new behavior (the original is unchanged, since behaviors are immutable).

```php
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\Terminated;
use Monadial\Nexus\Core\Lifecycle\PreStart;

$behavior = Behavior::receive(
    static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same(),
)->onSignal(
    static function (ActorContext $ctx, Signal $signal): Behavior {
        return match (true) {
            $signal instanceof PostStop => handlePostStop($ctx),
            $signal instanceof Terminated => handleTerminated($ctx, $signal),
            default => Behavior::same(),
        };
    },
);
```

Signature:

```php
/**
 * @param \Closure(ActorContext<T>, Signal): Behavior<T> $handler
 * @return Behavior<T>
 */
public function onSignal(Closure $handler): self;
```

Built-in signal types:

| Signal | When it fires |
|---|---|
| `PreStart` | After the actor is created, before it processes any messages |
| `PostStop` | After the actor has stopped |
| `PreRestart` | Before the actor restarts due to a failure |
| `PostRestart` | After the actor restarts |
| `Terminated` | When a watched actor stops (carries the stopped actor's `ActorRef`) |
| `ChildFailed` | When a child actor fails with an exception |

## BehaviorWithState

`BehaviorWithState<T, S>` is the return type of stateful behavior handlers. It is a `final readonly class` that tells the actor system what to do with both the behavior and the state after processing a message.

### BehaviorWithState::next

Keep the current behavior, update the state to a new value.

```php
// State was 5, now it becomes 6
return BehaviorWithState::next($count + 1);
```

### BehaviorWithState::same

Keep both the current behavior and the current state unchanged.

```php
return BehaviorWithState::same();
```

### BehaviorWithState::stopped

Stop the actor.

```php
readonly class Quit {}

$behavior = Behavior::withState(0, static function (
    ActorContext $ctx,
    object $msg,
    int $count,
): BehaviorWithState {
    if ($msg instanceof Quit) {
        return BehaviorWithState::stopped();
    }

    return BehaviorWithState::next($count + 1);
});
```

### BehaviorWithState::withBehavior

Switch to a completely new behavior and set a new state. This is useful for transitioning between different phases of an actor's lifecycle.

```php
/**
 * @template U of object
 * @template NS
 * @param Behavior<U> $behavior
 * @param NS $state
 * @return BehaviorWithState<U, NS>
 */
public static function withBehavior(Behavior $behavior, mixed $state): self;
```

```php
$newBehavior = Behavior::withState('ready', static function (
    ActorContext $ctx,
    object $msg,
    string $phase,
): BehaviorWithState {
    // Handle messages in the "ready" phase
    return BehaviorWithState::same();
});

return BehaviorWithState::withBehavior($newBehavior, 'ready');
```

## Behavior swapping

One of the most powerful features of the actor model is the ability to change an actor's behavior dynamically. Returning a new `Behavior` from a handler replaces the current one entirely for subsequent messages.

### Echo actor

A minimal actor that logs every message it receives.

```php
readonly class Echo_
{
    public function __construct(public string $text) {}
}

$echo = Behavior::receive(
    static function (ActorContext $ctx, Echo_ $msg): Behavior {
        $ctx->log()->info("Echo: {$msg->text}");

        return Behavior::same();
    },
);

$ref = $system->spawn(Props::fromBehavior($echo), 'echo');
$ref->tell(new Echo_('hello'));
$ref->tell(new Echo_('world'));
```

### Counter with stateful behavior

A counter that tracks its value using `Behavior::withState`.

```php
readonly class Increment {}
readonly class Decrement {}
readonly class GetCount
{
    public function __construct(public ActorRef $replyTo) {}
}
readonly class CountValue
{
    public function __construct(public int $value) {}
}

$counter = Behavior::withState(0, static function (
    ActorContext $ctx,
    object $msg,
    int $count,
): BehaviorWithState {
    return match (true) {
        $msg instanceof Increment => BehaviorWithState::next($count + 1),
        $msg instanceof Decrement => BehaviorWithState::next($count - 1),
        $msg instanceof GetCount => (function () use ($msg, $count): BehaviorWithState {
            $msg->replyTo->tell(new CountValue($count));

            return BehaviorWithState::same();
        })(),
        default => BehaviorWithState::same(),
    };
});

$ref = $system->spawn(Props::fromBehavior($counter), 'counter');
$ref->tell(new Increment());
$ref->tell(new Increment());
$ref->tell(new Decrement());
```

### Behavior-switching actor

An actor that changes its behavior based on the messages it receives. This example models a light switch with on and off states.

```php
readonly class TurnOn {}
readonly class TurnOff {}
readonly class Toggle {}

$off = Behavior::receive(
    static function (ActorContext $ctx, object $msg) use (&$on): Behavior {
        return match (true) {
            $msg instanceof TurnOn,
            $msg instanceof Toggle => (function () use ($ctx, &$on): Behavior {
                $ctx->log()->info('Light is ON');

                return $on;
            })(),
            default => Behavior::same(),
        };
    },
);

$on = Behavior::receive(
    static function (ActorContext $ctx, object $msg) use (&$off): Behavior {
        return match (true) {
            $msg instanceof TurnOff,
            $msg instanceof Toggle => (function () use ($ctx, &$off): Behavior {
                $ctx->log()->info('Light is OFF');

                return $off;
            })(),
            default => Behavior::same(),
        };
    },
);

// Actor starts in the "off" state
$ref = $system->spawn(Props::fromBehavior($off), 'light');
$ref->tell(new Toggle());  // Light is ON
$ref->tell(new Toggle());  // Light is OFF
$ref->tell(new TurnOn());  // Light is ON
```

When the handler returns a new `Behavior` (instead of `Behavior::same()`), the actor system replaces the current behavior entirely. The next message will be processed by the new behavior. This pattern is often called "become" in actor model literature.
