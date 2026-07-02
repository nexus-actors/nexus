---
title: BehaviorWithState
sidebar_position: 7
related:
  - core-concepts/behaviors
  - reference/classes/behavior
  - reference/classes/actor-context
---

# BehaviorWithState

Return type for stateful actor handlers — encodes what happens to both behavior and state after each message.

## What it does

`BehaviorWithState<T, S>` is the value returned by the closure passed to `Behavior::withState()` and by `StatefulActorHandler::handle()`. It encodes one of four outcomes: advance to a new state (`next()`), keep both behavior and state unchanged (`same()`), stop the actor (`stopped()`), or atomically switch to a completely different behavior and state (`withBehavior()`). The runtime reads the result after every message dispatch and applies the encoded transition — no mutable fields, no side channels. All four factory methods are `static`, so you pick the one that matches the outcome and pass it directly back to the runtime.

## Example

```php title="src/CounterActor.php"
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\ActorContext;

$counter = Behavior::withState(
    0,
    static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
        return match (true) {
            $msg instanceof Increment => BehaviorWithState::next($count + 1),
            $msg instanceof Reset     => BehaviorWithState::next(0),
            $msg instanceof GetCount  => (static function () use ($ctx, $count): BehaviorWithState {
                $ctx->sender()?->tell(new CountValue($count));
                return BehaviorWithState::same();
            })(),
            $msg instanceof Shutdown  => BehaviorWithState::stopped(),
            default                   => BehaviorWithState::same(),
        };
    },
);
```

## Key methods

- `BehaviorWithState::next(mixed $state): self` — keep the current behavior, advance to `$state`; the most common return value.
- `BehaviorWithState::same(): self` — keep both the current behavior and the current state unchanged; use when the message produces a side effect (reply, log) but no state change.
- `BehaviorWithState::stopped(): self` — stop the actor; equivalent to `Behavior::stopped()` in a stateless handler.
- `BehaviorWithState::withBehavior(Behavior $behavior, mixed $state): self` — atomically swap to a different behavior and advance state in one step; useful for implementing state machines with distinct phases.

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Core-Actor-BehaviorWithState.html)

## See also

- [Behaviors concept](../../core-concepts/behaviors) — stateful vs stateless handler patterns
- [Behavior](behavior) — `Behavior::withState()` creates the stateful handler that returns `BehaviorWithState`
- [ActorContext](actor-context) — the context passed alongside state on every dispatch
