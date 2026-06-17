---
sidebar_position: 10
title: "Passivation"
---

# Passivation

Long-lived actors that hold expensive resources — database connections,
file handles, large in-memory caches — should release those resources
when idle and rehydrate on the next message. Nexus calls this pattern
**passivation**. It's built on a single core primitive:
`ActorContext::setReceiveTimeout(?Duration)`.

## The primitive

Inside any handler — typically `Behavior::setup` — call:

```php
$ctx->setReceiveTimeout(Duration::seconds(120));
```

After 120s with no **user messages** arriving, the actor cell delivers a
`Monadial\Nexus\Core\Lifecycle\ReceiveTimeout` signal. Handle it in your
`onSignal` block and return `Behavior::stopped()` to passivate:

```php
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Runtime\Duration;

$behavior = Behavior::setup(static function ($ctx): Behavior {
    $ctx->setReceiveTimeout(Duration::seconds(120));

    return Behavior::receive(static fn($c, $msg) => Behavior::same())
        ->onSignal(static function ($c, object $signal): Behavior {
            if ($signal instanceof ReceiveTimeout) {
                return Behavior::stopped();
            }

            return Behavior::same();
        });
});
```

The actor's `PostStop` handler runs as normal — release any resources
there.

### Reset semantics

The timer resets on **every user message**. System messages (`Watch`,
`Unwatch`, `PoisonPill`) do **not** reset. This matches Akka's
`ReceiveTimeout` semantics.

### Cancellation

`$ctx->setReceiveTimeout(null)` disables the timer. Re-calling with a
different `Duration` replaces the current setting; the first user
message after the call uses the new timeout.

### Timing gotcha

`Behavior::setup` runs synchronously inside `spawn()`, **before**
`$system->run()` starts the event loop. A timer armed during setup
begins counting from the moment of `spawn()`, not from the first
event-loop tick. For sub-second timeouts in tests, arm the timer from
inside the first message handler instead, or use a generous duration
(>500ms) that absorbs the gap.

## Rehydration

For `EntityBehavior`, rehydration is automatic via `EntityRefFactory`:
when the cached `ActorRef` reports `isAlive() === false`, the factory
drops the cache entry and spawns a fresh actor on the next `of($id)`
call. The new actor's replay policy loads the entity from DB
transparently.

For plain `Behavior::setup`/`receive`/`withState` actors, you'd
implement the same pattern via a directory actor or a custom factory.
The core primitive that makes it work: `ActorSystem::spawn()` prunes
dead children from its `children` map automatically — re-using a name
that previously belonged to a stopped actor is allowed.

## Where it's used

| Pattern | Setter | Reference |
|---|---|---|
| `EntityBehavior` (Doctrine aggregate) | `->withReceiveTimeout(Duration)` | [Doctrine / EntityBehavior DSL](../doctrine/entity-behavior.md#passivation) |
| `EntityRefFactory` (forwards to spawned actors) | `->withReceiveTimeout(Duration)` | [Doctrine / EntityBehavior DSL](../doctrine/entity-behavior.md#passivation) |
| Custom behaviors | `$ctx->setReceiveTimeout(Duration)` | This page |

Event-sourced and durable-state behaviors don't yet ship a fluent
`->withReceiveTimeout` setter — call `$ctx->setReceiveTimeout(...)`
directly inside your `Behavior::setup` for those.

## Cost trade-off

- **Pinned resource vs cold-start latency.** Without passivation, every
  active aggregate pins its resources. With passivation, only
  *concurrently-active* aggregates do — but the first message after
  passivation pays a setup cost. For DB-backed aggregates that's
  tens of milliseconds against Postgres+TLS.
- **In-flight messages during the rehydration window go to dead
  letters.** For most write paths this is acceptable; clients retry.
  For high-stakes commands, send via `ask()` so the per-message timeout
  surfaces the failure rather than silently dropping it.

## Caveats

- **Sub-second timeouts amplify rehydration cost** relative to the work
  done per message. 60s–10min is the sensible range for hot aggregates.
- **One factory per `(system, entityClass)` pair.** Two factories
  spawned against the same system pointing at the same entity class
  will race on actor names. Hold one factory at the worker/thread level.
- **Only opt in when the actor's state is recoverable.** Passivating a
  closure-based `Behavior::withState(...)` actor that doesn't persist
  anywhere loses the state forever.
