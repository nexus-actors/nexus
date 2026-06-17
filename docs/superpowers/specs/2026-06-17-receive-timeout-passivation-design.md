# Receive Timeout & Idle Passivation Design

**Status:** Spec — pending review
**Author:** brainstorming session 2026-06-17
**Target branch:** `feat/nexus-doctrine` (continues the same branch as Plans 1–3)
**Target packages:** `nexus-core`, `nexus-doctrine-orm`

## Goal

Add `ReceiveTimeout` semantics to `nexus-core` so any actor can self-terminate after N seconds of inactivity. Wire it into `EntityBehavior` so long-lived entity actors **passivate** when idle — releasing their dedicated `EntityManager` and `Connection` — and **rehydrate transparently** on the next message. Same pattern works for `EventSourcedBehavior`, `DurableStateBehavior`, and plain closure behaviors; this plan ships the core primitive plus the `EntityBehavior` integration.

Today, every long-lived `EntityBehavior` actor pins one DB connection for its whole lifetime. An app with 10k hot entity actors needs ≥10k connections. With passivation, idle actors release their resources and the connection budget shrinks to *concurrently-active* entities — typically orders of magnitude smaller.

## Non-goals

- **No stash-during-passivation queue.** Messages arriving during the brief passivation → rehydration gap go to dead letters by default. A future enhancement could buffer them via a directory-level proxy; out of scope for v1.
- **No per-message-type granularity.** Receive timeout resets on **any user message**. System messages (`Watch`, `Unwatch`, internal signals) do **not** reset. This matches Akka.
- **No persistence-layer integration.** `EventSourcedBehavior` and `DurableStateBehavior` *can* use this primitive immediately — they replay state on restart by design — but a wiring layer for them is a follow-up.
- **No PoisonPill-on-timeout.** Passivation = clean stop. If you need delayed cleanup, override `onSignal(PostStop)`.

## Architecture overview

### Three new pieces in `nexus-core`

1. **`Lifecycle\ReceiveTimeout`** — a lifecycle signal class. Like `PreStart` / `PostStop` / `Terminated`, dispatched to the actor via the `onSignal` hook on the behavior.

2. **`ActorContext::setReceiveTimeout(?Duration $timeout): void`** — opt-in. When set to a non-null `Duration`, every user-message dispatch schedules (or reschedules) a single timer; if the timer fires before the next message arrives, the cell delivers `ReceiveTimeout` as a signal. Calling with `null` cancels.

3. **`ActorCell` wiring** — on each user-message processing pass, cancel the existing timer and re-arm. The timer's callback enqueues a system-message-like wrapper that the cell routes to `onSignal(ReceiveTimeout)`.

### One new piece in `nexus-doctrine-orm`

4. **`EntityBehaviorBuilder::withReceiveTimeout(Duration $timeout): self`** — sugar that opts the actor into passivation. The runner's `Behavior::setup` calls `$ctx->setReceiveTimeout($timeout)` after replay. The existing `PostStop` handler already closes the EM + Connection, so no new cleanup is needed.

### One eviction integration

5. **`EntityRefFactory` watches spawned children.** When the cached actor terminates (passivation, supervision-stop, or `EntityEffect::stop()`), the factory drops the cache entry so the **next** `of($id)` call spawns a fresh actor with a fresh EM, replays the entity from DB, and processes the incoming message.

## Detailed design

### `Lifecycle\ReceiveTimeout`

```php
namespace Monadial\Nexus\Core\Lifecycle;

/**
 * Lifecycle signal delivered to an actor when no user message has arrived
 * within the duration configured via ActorContext::setReceiveTimeout().
 *
 * Handle it via behavior->onSignal(...). Typical use: return Behavior::stopped()
 * to passivate.
 *
 * @psalm-api
 */
final readonly class ReceiveTimeout
{
    public function __construct(public Duration $configured) {}
}
```

`$configured` carries the timeout the actor was configured with — useful for actors that vary their behaviour by length of idleness.

### `ActorContext::setReceiveTimeout`

Added to the interface. Side-effecting (mutates cell state).

```php
public function setReceiveTimeout(?Duration $timeout): void;
```

Calling repeatedly with a new `Duration` replaces the current setting. The first user message processed after the call uses the new timeout. Calling with `null` cancels the timer.

### `ActorCell` integration

State added to `ActorCell`:
```php
private ?Duration $receiveTimeout = null;
private ?Cancellable $receiveTimer = null;
```

`setReceiveTimeout` implementation:
```php
public function setReceiveTimeout(?Duration $timeout): void
{
    $this->receiveTimeout = $timeout;
    $this->receiveTimer?->cancel();
    $this->receiveTimer = null;

    if ($timeout === null) {
        return;
    }

    $this->armReceiveTimer($timeout);
}

private function armReceiveTimer(Duration $timeout): void
{
    $this->receiveTimer = $this->runtime->scheduleOnce(
        $timeout,
        fn() => $this->onReceiveTimeout($timeout),
    );
}

private function onReceiveTimeout(Duration $configured): void
{
    // Re-check: a message may have arrived after the timer fired but
    // before this callback ran. The cell's mailbox is single-threaded,
    // so we just check whether the timer is still the one we armed.
    if ($this->receiveTimer === null) {
        return;
    }

    $this->receiveTimer = null;
    $this->dispatchSignal(new ReceiveTimeout($configured));
}
```

Hook into the user-message dispatch:
```php
// inside the existing per-message loop, after PreStart but before
// invoking the behavior:
if ($this->receiveTimeout !== null && $envelope->isUserMessage()) {
    $this->receiveTimer?->cancel();
    $this->armReceiveTimer($this->receiveTimeout);
}
```

System messages (`Watch`, `Unwatch`, `PoisonPill`, etc.) do **not** reset.

### `EntityBehaviorBuilder::withReceiveTimeout`

Add a nullable `Duration` field:
```php
public ?Duration $receiveTimeout;
```

Add the setter (immutable, like the other `with*` methods):
```php
public function withReceiveTimeout(Duration $timeout): self
{
    return new self(
        // ... all existing fields ...,
        receiveTimeout: $timeout,
    );
}
```

Wire into the runner's `Behavior::setup`:
```php
return Behavior::setup(static function (ActorContext $ctx) use ($builder, ...): Behavior {
    $connection = ($builder->connectionSource)();
    $em = $builder->emFactory->create($connection);
    $entity = $builder->replayPolicy->resolve($em, $builder->entityClass, $builder->id);

    if ($builder->receiveTimeout !== null) {
        $ctx->setReceiveTimeout($builder->receiveTimeout);
    }

    $stateful = Behavior::withState($entity, /* command handler */);

    return $stateful->onSignal(static function (ActorContext $ctx, object $signal) use ($em, $connection): Behavior {
        if ($signal instanceof ReceiveTimeout) {
            return Behavior::stopped();          // triggers PostStop
        }
        if ($signal instanceof PostStop) {
            $em->close();
            $connection->close();
        }
        return Behavior::same();
    });
});
```

The existing `PostStop` handler does the cleanup. Passivation is **free cleanup** — no new resource-release path.

### `EntityRefFactory` eviction

Current `of($id)` implementation caches by name forever. Update to watch the spawned ref and drop the cache entry on `Terminated`:

```php
public function of(mixed $id): ActorRef
{
    $name = self::deriveName($this->entityClass, $id);

    if (isset($this->cache[$name]) && $this->cache[$name]->isAlive()) {
        return $this->cache[$name];
    }

    // Cached entry is dead (passivated, stopped, or never spawned).
    // Drop it and spawn fresh.
    unset($this->cache[$name]);

    $behavior = EntityBehavior::create($this->entityClass, $id, $this->commandHandler)
        ->withEntityManagerFactory($this->emFactory)
        ->withConnectionSource($this->connectionSource)
        ->withReplayPolicy($this->replayPolicy)
        ->withReceiveTimeout($this->receiveTimeout)    // forwarded
        ->toBehavior();

    return $this->cache[$name] = $this->spawner->spawn(Props::fromBehavior($behavior), $name);
}
```

`ActorRef::isAlive()` already exists per CLAUDE.md. The check makes the cache **self-cleaning** — no `Watch`/`Terminated` propagation needed at this layer, because every `of()` validates liveness.

For factories that need to pass `receiveTimeout`, `EntityRefFactoryBuilder` gains:
```php
public function withReceiveTimeout(Duration $timeout): self;
```

## Testing

### Unit tests (`nexus-core`)

- `ActorContextReceiveTimeoutTest` — set, reset, cancel via null, signal fires after configured duration without messages
- `ActorCellReceiveTimerResetTest` — timer is reset on each user message but NOT on system messages
- `ReceiveTimeoutSignalTest` — signal carries the configured duration

### Unit tests (`nexus-doctrine-orm`)

- `EntityBehaviorBuilderReceiveTimeoutTest` — `withReceiveTimeout()` returns new instance, runner respects it
- `EntityBehaviorRunnerReceiveTimeoutTest` — receiving the signal triggers `Behavior::stopped()` → `PostStop` → EM/Connection close
- `EntityRefFactoryEvictionTest` — `of($id)` after the cached ref dies spawns a fresh actor

### Integration tests

- `tests/Integration/ReceiveTimeout/PassivationUnderFiberTest.php` — set a 50ms timeout, send 3 messages 10ms apart, wait 100ms, assert `isAlive() === false`. Send a 4th message, assert it rehydrates (`isAlive() === true`) and processes correctly.
- `tests/Integration/Doctrine/Orm/EntityBehavior/PassivationTest.php` — same shape, but with a real DB. Send a deposit command, verify persistence, wait past timeout, verify connection released (via pool stats or DB lock check), send another command, verify the new actor reloads the entity from DB.

## Out of scope (explicit)

- **Stash-during-passivation queue.** Messages arriving during the rehydration window go to dead letters. A directory-level proxy could buffer them; deferred to a future plan.
- **`EventSourcedBehavior` + `DurableStateBehavior` integration.** Trivial follow-ups — each gets a `->withReceiveTimeout(Duration)` builder method that calls `setReceiveTimeout` in its setup. Deferred to keep this plan focused on `EntityBehavior`.
- **`Watch`-based external eviction.** The polling `isAlive()` check in `EntityRefFactory::of()` is correct and cheap. If profiling shows it's a hot path under millions of cached refs, replace with active `$ctx->watch()` + `Terminated` propagation. Premature.
- **Backpressure on rehydration storms.** If 10k actors passivate and then all get a message simultaneously, you re-open 10k connections at once. The DBAL pool can throttle this for *handler* paths but `EntityBehavior` uses dedicated (non-pooled) connections. Mitigation: rate-limit rehydration via a directory router. Deferred.

## Migration

- Branch stays `feat/nexus-doctrine` (already the home of Plans 1–3).
- Additive only — no existing behavior changes when `setReceiveTimeout` is not called.
- `EntityBehavior` actors without `withReceiveTimeout(...)` keep their current "live forever" semantics. Opt-in.

## Open questions (resolved during design)

| Question | Decision |
|---|---|
| Where does the `ReceiveTimeout` signal live? | `nexus-core/src/Lifecycle/` alongside `PreStart`, `PostStop`, `Terminated`. |
| Does it reset on system messages? | No — only user messages. Matches Akka. |
| What happens when `setReceiveTimeout(null)` is called? | Active timer is cancelled. No-op if no timer was armed. |
| Should `EntityBehaviorRunner` set a default timeout? | No. Opt-in via `withReceiveTimeout`. Default is "live forever" (current behavior). |
| Eviction model for `EntityRefFactory` | Polling `isAlive()` in `of()`. Cheap, correct, no `Watch` plumbing needed. |
| Should we ship `EventSourcedBehavior` / `DurableStateBehavior` integration in this plan? | No. Trivial follow-ups; keep this plan focused. |
| Stash queue for messages-during-passivation? | No. Deferred to a follow-up if needed. |
