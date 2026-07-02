---
title: Behavior
sidebar_position: 3
related:
  - core-concepts/behaviors
  - core-concepts/actors
  - reference/classes/props
  - reference/classes/actor-ref
---

# Behavior

Immutable actor message-handler definition — the heart of every actor.

## What it does

A `Behavior<T>` describes what an actor does when it receives a message of type `T`.
You never instantiate `Behavior` subclasses directly; instead use the static factory
methods (`receive`, `withState`, `setup`) which return the appropriate concrete subtype.
Each message handler must return a `Behavior` to indicate what the actor should do next:
keep the same behavior (`same()`), stop the actor (`stopped()`), mark the message as
unhandled (`unhandled()`), or return a completely new behavior to replace the current one.
Attaching a signal handler via `onSignal()` lets the same behavior react to lifecycle
events such as `PreStart` and `PostStop` without a separate class.

## Example

```php title="src/CounterActor.php"
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Lifecycle\Signal;

// Stateless — no mutable state needed
$greeter = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
    if ($msg instanceof Greet) {
        $ctx->log()->info('Hello, ' . $msg->name);
        return Behavior::same();
    }
    return Behavior::unhandled();
});

// Stateful — state is threaded through the handler by the runtime
$counter = Behavior::withState(
    0,
    static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
        return match (true) {
            $msg instanceof Increment => BehaviorWithState::next($count + 1),
            $msg instanceof GetCount  => tap(
                BehaviorWithState::same(),
                fn() => $ctx->sender()?->tell(new Count($count)),
            ),
            default => BehaviorWithState::same(),
        };
    },
);

// Setup — runs once at actor start; useful for spawning children or opening resources
$supervisor = Behavior::setup(static function (ActorContext $ctx): Behavior {
    $worker = $ctx->spawn(Props::fromBehavior($counter), 'counter');
    return Behavior::receive(static fn($ctx, $msg) => match (true) {
        $msg instanceof Increment => tap(Behavior::same(), fn() => $worker->tell($msg)),
        default                   => Behavior::unhandled(),
    });
});
```

## Key methods

- `Behavior::receive(Closure $handler): ReceiveBehavior<T>` — stateless message handler; closure signature `(ActorContext<T>, T): Behavior<T>`.
- `Behavior::withState(mixed $initial, Closure $handler): SetupBehavior<T>` — stateful handler; closure signature `(ActorContext<T>, T, S): BehaviorWithState<T, S>`.
- `Behavior::setup(Closure $factory): SetupBehavior<T>` — deferred factory; closure signature `(ActorContext<T>): Behavior<T>`.
- `Behavior::same(): SameBehavior<T>` — sentinel: keep current behavior unchanged.
- `Behavior::stopped(): StoppedBehavior<T>` — sentinel: stop the actor after this message.
- `Behavior::unhandled(): Behavior<T>` — sentinel: message was not handled (routes to dead letters).
- `->onSignal(Closure $handler): static` — attach a lifecycle signal handler; closure signature `(ActorContext<T>, Signal): Behavior<T>`.

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Core-Actor-Behavior.html)

## See also

- [Behaviors concept](../../core-concepts/behaviors) — deep dive into the behavior state machine
- [Props](props) — wraps a `Behavior` into a spawnable configuration
- [ActorRef](actor-ref) — the reference returned when you spawn a `Behavior`
- [Core Concepts — Actors](../../core-concepts/actors) — actor model overview
