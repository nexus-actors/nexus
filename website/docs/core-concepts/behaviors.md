---
sidebar_position: 2
title: "Behaviors"
related:
  - core-concepts/actors
  - core-concepts/lifecycle
  - core-concepts/supervision
  - core-concepts/props
---

# Behaviors

A `Behavior<T>` defines how an actor processes messages. Behaviors are immutable — when a handler runs, it returns the next behavior for the actor to use. This model lets actors change their message-processing logic over time without mutable state and makes behavior transitions explicit and testable.

## Behavior::receive

The primary way to define a behavior. The closure receives the `ActorContext` and the message, and returns the next `Behavior`.

```php title="src/Actor/GreeterBehavior.php"
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

## Behavior::withState

Creates a stateful behavior. The closure receives the context, message, and current state, and returns a `BehaviorWithState` that carries the updated state.

```php title="src/Actor/CounterBehavior.php"
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

## Behavior::setup

Runs an initialization closure before the actor starts processing messages. The closure receives the context and returns the behavior the actor will use. This is the right place to spawn children, start timers, or acquire resources.

```php title="src/Actor/WorkerBehavior.php"
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;

readonly class Tick {}

$behavior = Behavior::setup(function (ActorContext $ctx): Behavior {
    $child = $ctx->spawn(Props::fromBehavior($childBehavior), 'worker');
    $ctx->watch($child);

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

## Sentinels

Four sentinel values control actor state transitions without processing logic.

| Method | Effect |
|---|---|
| `Behavior::same()` | Keep the current behavior unchanged. |
| `Behavior::stopped()` | Stop the actor. `PostStop` signal delivers after this. |
| `Behavior::unhandled()` | Route the current message to dead letters. |
| `Behavior::empty()` | Silently discard all messages. |

## Signal handling

Attach a signal handler to any behavior with `->onSignal()`. The handler receives the `ActorContext` and the `Signal`, and returns the next `Behavior`.

```php title="src/Actor/SignalAwareBehavior.php"
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\Terminated;

$behavior = Behavior::receive(
    static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same(),
)->onSignal(
    static function (ActorContext $ctx, Signal $signal): Behavior {
        return match (true) {
            $signal instanceof PostStop   => handlePostStop($ctx),
            $signal instanceof Terminated => handleTerminated($ctx, $signal),
            default => Behavior::same(),
        };
    },
);
```

Built-in signal types:

| Signal | When it fires |
|---|---|
| `PreStart` | After the actor is created, before it processes any messages |
| `PostStop` | After the actor has stopped |
| `PreRestart` | Before the actor restarts due to a failure |
| `PostRestart` | After the actor restarts |
| `Terminated` | When a watched actor stops |
| `ChildFailed` | When a child actor fails with an exception |

## Composable behavior wrappers

Nexus provides wrappers that inject resources into behavior factories. All are composable via nesting.

### Behavior::withTimers

Provides a `TimerScheduler` for keyed timer management. Starting a timer with an existing key auto-cancels the previous one. All timers cancel automatically when the actor stops.

```php title="src/Actor/HeartbeatBehavior.php"
use Monadial\Nexus\Core\Actor\TimerScheduler;
use Monadial\Nexus\Runtime\Duration;

readonly class Heartbeat {}

$behavior = Behavior::withTimers(
    static function (TimerScheduler $timers): Behavior {
        $timers->startTimerWithFixedDelay('heartbeat', new Heartbeat(), Duration::seconds(5));

        return Behavior::receive(
            static fn (ActorContext $ctx, object $msg): Behavior => match (true) {
                $msg instanceof Heartbeat => handleHeartbeat($ctx),
                default => Behavior::unhandled(),
            },
        );
    },
);
```

### Behavior::withStash

Provides a bounded `StashBuffer` with explicit capacity and inline replay. Unlike context-level stashing, `unstashAll()` processes stashed messages through the new behavior immediately, before any new messages from the mailbox.

```php title="src/Actor/StashingBehavior.php"
use Monadial\Nexus\Core\Actor\StashBuffer;
use Monadial\Nexus\Core\Mailbox\Envelope;

readonly class DbReady
{
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

                $stash->stash(Envelope::of($msg, ActorPath::root(), $ctx->path()));

                return Behavior::same();
            },
        );
    },
);
```

`stash()` throws `StashOverflowException` if the buffer is full.

### Behavior::supervise

Wraps a behavior with a supervision strategy. When the inner behavior's handler throws, the behavior-level strategy decides the response. If it returns `Escalate`, the Props-level strategy takes over.

```php title="src/Actor/SupervisedBehavior.php"
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Supervision\Directive;

$behavior = Behavior::supervise(
    Behavior::receive(
        static fn (ActorContext $ctx, object $msg): Behavior => handleMessage($ctx, $msg),
    ),
    SupervisionStrategy::oneForOne(
        maxRetries: 5,
        decider: fn (Throwable $e) => match (true) {
            $e instanceof RecoverableError => Directive::Restart,
            default => Directive::Escalate,
        },
    ),
);
```

## BehaviorWithState

`BehaviorWithState<T, S>` is the return type of stateful behavior handlers. It tells the actor system what to do with both the behavior and the state after each message.

| Method | Effect |
|---|---|
| `BehaviorWithState::next($state)` | Keep the current behavior; update state to the new value. |
| `BehaviorWithState::same()` | Keep both the current behavior and current state unchanged. |
| `BehaviorWithState::stopped()` | Stop the actor. |
| `BehaviorWithState::withBehavior($behavior, $state)` | Switch to a completely new behavior with a new state. |

## Behavior swapping

Returning a different `Behavior` instance from a handler replaces the current one entirely for all subsequent messages. This is how actors implement state machines.

```php title="src/Actor/LightSwitch.php"
readonly class TurnOn {}
readonly class TurnOff {}

$off = Behavior::receive(
    static function (ActorContext $ctx, object $msg) use (&$on): Behavior {
        if ($msg instanceof TurnOn) {
            $ctx->log()->info('Light is ON');

            return $on;
        }

        return Behavior::same();
    },
);

$on = Behavior::receive(
    static function (ActorContext $ctx, object $msg) use (&$off): Behavior {
        if ($msg instanceof TurnOff) {
            $ctx->log()->info('Light is OFF');

            return $off;
        }

        return Behavior::same();
    },
);

$ref = $system->spawn(Props::fromBehavior($off), 'light');
```

## Higher-level behavior DSLs

The factory methods above are the primitives. Other packages ship opinionated DSLs built on top of them — each still a `Behavior<T>` under the hood.

| DSL | Package | Use case |
|---|---|---|
| `EventSourcedBehavior` | `nexus-persistence` | Commands produce events; events replay to rebuild state. Full audit trail. |
| `DurableStateBehavior` | `nexus-persistence` | State snapshots without event history. Simpler when you don't need the audit trail. |
| `EntityBehavior` | `nexus-doctrine-orm` | Treat a Doctrine entity as the actor's state. Single-writer per `(class, id)` via `EntityRefFactory`. |

All three return a `Behavior` from `->toBehavior()` that fits the same `Props::fromBehavior()` spawn flow.

## Failure modes

Behavior failures usually surface as silently dropped messages or unexpected actor stops. The table below covers the most common root causes.

| Symptom | Cause | Recovery |
|---|---|---|
| Actor stops unexpectedly without a supervision restart | Handler returned `Behavior::stopped()` on an unintended code path | Add a `default => Behavior::same()` branch to your `match`; check for accidental `stopped()` returns |
| Messages routed to dead letters | Handler returned `Behavior::unhandled()` for a message it should process | Extend the handler to cover the message type; inspect `$system->deadLetters()->captured()` in tests |
| `StashOverflowException` thrown | `StashBuffer::stash()` was called when the buffer was at capacity | Increase the stash capacity, or drain the stash sooner by handling the trigger message earlier |
| State lost after actor restart | Stateful closure captured local variables that are discarded on restart | Use `Behavior::withState()` or `StatefulActorHandler` so state is explicitly tracked by the runtime |
| Signal handler never fires | `->onSignal()` was not chained onto the returned behavior | Chain `->onSignal()` on the same behavior instance returned from `setup()`; signals only dispatch to the active behavior's handler |

## Next steps

- [Actors](./actors.md) — actor definition patterns and `ActorContext` API
- [Lifecycle](./lifecycle.md) — the signals delivered at each state transition
- [Supervision](./supervision.md) — `Behavior::supervise()` and error handling
