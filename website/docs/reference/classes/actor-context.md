---
title: ActorContext
sidebar_position: 6
related:
  - core-concepts/actors
  - core-concepts/behaviors
  - core-concepts/lifecycle
  - reference/classes/behavior
  - reference/classes/actor-ref
---

# ActorContext

Runtime context injected into every actor handler — the actor's window onto the system.

## What it does

`ActorContext<T>` is the primary API surface available inside every message handler and signal callback. It gives the running actor access to its own reference, its place in the supervision tree, child lifecycle management, death-watch subscriptions, message stashing, timer scheduling, background task spawning, and a PSR-3 logger. The context is passed fresh on every handler invocation — never cache or store the instance between messages, as it is only valid for the duration of the current dispatch.

## Example

```php title="src/SupervisorActor.php"
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Lifecycle\Terminated;
use Monadial\Nexus\Runtime\Duration;

$supervisor = Behavior::setup(static function (ActorContext $ctx): Behavior {
    // Spawn a child and subscribe to its termination
    $worker = $ctx->spawn(Props::fromBehavior($workerBehavior), 'worker');
    $ctx->watch($worker);

    // Schedule a heartbeat every 5 seconds
    $ctx->scheduleRepeatedly(Duration::seconds(5), Duration::seconds(5), new Heartbeat());

    return Behavior::receive(
        static function (ActorContext $ctx, object $msg) use ($worker): Behavior {
            if ($msg instanceof DoWork) {
                $worker->tell($msg);
            }
            return Behavior::same();
        },
    )->onSignal(static function (ActorContext $ctx, object $signal) use (&$worker): Behavior {
        if ($signal instanceof Terminated) {
            $ctx->log()->warning('Worker terminated — restarting');
            $worker = $ctx->spawn(Props::fromBehavior($workerBehavior), 'worker');
            $ctx->watch($worker);
        }
        return Behavior::same();
    });
});
```

## Key methods

- `self(): ActorRef<T>` — this actor's own typed reference; safe to share and pass to other actors.
- `spawn(Props<C> $props, string $name): ActorRef<C>` — create a named child actor; throws `ActorInitializationException` on duplicate name.
- `watch(ActorRef $target): void` / `unwatch(ActorRef $target): void` — subscribe/unsubscribe to `Terminated` signals when `$target` stops.
- `scheduleOnce(Duration $delay, object $message): Cancellable` — deliver a message to `self()` once after `$delay`.
- `scheduleRepeatedly(Duration $initial, Duration $interval, object $message): Cancellable` — deliver a message repeatedly on a fixed interval.
- `stash(): void` / `unstashAll(): void` — buffer the current message for later; re-enqueue all buffered messages in order.
- `setReceiveTimeout(?Duration $timeout): void` — trigger a `ReceiveTimeout` signal if no user message arrives within the window; pass `null` to cancel.
- `sender(): ?ActorRef` — the actor that sent the current message (non-null only for `ask()` requests).
- `reply(object $message): void` — shorthand for `$ctx->sender()->tell($message)`; throws `NoSenderException` on regular `tell()`.
- `log(): LoggerInterface` — PSR-3 logger scoped to this actor's path.
- `spawnTask(Closure $task): Cancellable` — run a background closure tied to this actor's lifecycle; cancelled automatically on actor stop.

## Full API reference

[Full method list and interface hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Core-Actor-ActorContext.html)

## See also

- [Behaviors concept](../../core-concepts/behaviors) — how handlers receive a context on each dispatch
- [Lifecycle concept](../../core-concepts/lifecycle) — `PreStart`, `PostStop`, `Terminated`, and `ReceiveTimeout` signals
- [Actors concept](../../core-concepts/actors) — actor model overview and supervision trees
- [Behavior](behavior) — handler factories that receive `ActorContext`
- [ActorRef](actor-ref) — type-safe reference returned by `spawn()`
