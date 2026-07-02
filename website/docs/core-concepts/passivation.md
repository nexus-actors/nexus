---
sidebar_position: 10
title: "Passivation"
related:
  - core-concepts/lifecycle
  - core-concepts/actors
  - doctrine/entity-behavior
  - core-concepts/behaviors
---

# Passivation

Passivation stops an idle actor to release its resources, then transparently rehydrates it on the next message. It is the right pattern when actors hold expensive resources — database connections, file handles, large in-memory caches — that should not be pinned indefinitely.

## The primitive

Arm the idle timer from inside any handler — typically `Behavior::setup` — with `$ctx->setReceiveTimeout()`:

```php title="src/Actor/PassivatingActor.php"
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Runtime\Duration;

$behavior = Behavior::setup(static function ($ctx): Behavior {
    $ctx->setReceiveTimeout(Duration::seconds(120));

    return Behavior::receive(static fn ($c, $msg) => Behavior::same())
        ->onSignal(static function ($c, object $signal): Behavior {
            if ($signal instanceof ReceiveTimeout) {
                return Behavior::stopped();
            }

            return Behavior::same();
        });
});
```

After 120 seconds with no user messages, the actor cell delivers `ReceiveTimeout`. The signal handler returns `Behavior::stopped()`, the actor's `PostStop` runs normally — release your resources there.

### Reset semantics

The timer resets on every user message. System messages (`Watch`, `Unwatch`, `PoisonPill`) do not reset. This matches Akka's `ReceiveTimeout` semantics.

### Cancellation

Call `$ctx->setReceiveTimeout(null)` to disable the timer. Re-calling with a different `Duration` replaces the current setting; the first user message after the call uses the new timeout.

### Timing gotcha

`Behavior::setup` runs synchronously inside `spawn()`, before `$system->run()` starts the event loop. A timer armed during setup begins counting from the moment of `spawn()`, not from the first event-loop tick. For sub-second timeouts in tests, arm the timer from the first message handler instead, or use a generous duration (>500ms) that absorbs the gap.

## Rehydration

### EntityBehavior (Doctrine)

For `EntityBehavior`, rehydration is automatic via `EntityRefFactory`. When the cached `ActorRef` reports `isAlive() === false`, the factory drops the cache entry and spawns a fresh actor on the next `of($id)` call. The new actor's replay policy loads the entity from the database transparently.

### Plain behaviors

For plain `Behavior::setup` / `receive` / `withState` actors, implement the same pattern via a directory actor or a custom factory. `ActorSystem::spawn()` prunes stopped children from its `children` map automatically — reusing a name that previously belonged to a stopped actor is allowed.

## Where it's used

| Pattern | Setter | Reference |
|---|---|---|
| `EntityBehavior` (Doctrine aggregate) | `->withReceiveTimeout(Duration)` | [Doctrine / EntityBehavior DSL](../doctrine/entity-behavior.md#passivation) |
| `EntityRefFactory` (forwards to spawned actors) | `->withReceiveTimeout(Duration)` | [Doctrine / EntityBehavior DSL](../doctrine/entity-behavior.md#passivation) |
| Custom behaviors | `$ctx->setReceiveTimeout(Duration)` | This page |

## Cost trade-off

Without passivation, every active aggregate pins its resources. With passivation, only concurrently-active aggregates do — but the first message after passivation pays a setup cost. For database-backed aggregates that is tens of milliseconds against Postgres with TLS.

In-flight messages sent during the rehydration window go to dead letters. For most write paths this is acceptable and clients retry. For high-stakes commands, send via `ask()` so the per-message timeout surfaces the failure rather than silently dropping it.

## Failure modes

Passivation failures are usually silent: state is lost, or the actor fails to revive cleanly on the next message.

| Symptom | Cause | Recovery |
|---|---|---|
| State lost permanently after passivation | A closure-based `Behavior::withState()` actor passivated without any persistence backing | Only passivate actors whose state is recoverable; use `EventSourcedBehavior` or `DurableStateBehavior` when state must survive restarts |
| `ActorNameExistsException` on re-spawn after passivation | A rapid passivate-then-re-spawn race where the old actor is still in `Stopping` when the factory tries to reuse the name | Add a small retry delay in the factory, or check `$ctx->child($name) === null` before spawning |
| `ReceiveTimeout` fires too aggressively; frequent cold starts | Timeout is shorter than the actor's natural message interval | Raise the timeout to at least 2-5x the expected inter-message gap; sub-second timeouts amplify rehydration cost relative to useful work |
| Messages go to dead letters during rehydration | Messages arrived while the actor was stopped and before the new instance was ready | For write paths that cannot tolerate drops, use `ask()` with a timeout and retry on failure at the caller |

## Next steps

- [Lifecycle](./lifecycle.md) — `ReceiveTimeout`, `PostStop`, and the full actor state machine
- [Behaviors](./behaviors.md) — `Behavior::setup()` and signal handling via `->onSignal()`
- [Doctrine / EntityBehavior DSL](../doctrine/entity-behavior.md) — built-in passivation for entity actors
