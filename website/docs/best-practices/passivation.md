---
sidebar_position: 6
title: Passivation and memory
related:
  - core-concepts/passivation
  - best-practices/pooled-connections
  - best-practices/when-to-use-actors
  - best-practices/scaling
---

# Passivation and memory

You don't need a live actor for every identity in your system — only for the ones that are currently active. `ReceiveTimeout` is the mechanism that keeps resident actor count proportional to concurrent activity rather than to total lifetime cardinality.

## The rule

**Any actor that owns expensive resources — DB connections, large in-memory state, file handles — must set a `ReceiveTimeout`.**

That includes nearly every entity actor. The hot path of your traffic is a small subset of total ids. Resident actor count should track concurrent activity, not user count.

## Setting up passivation

```php title="src/Factory/LedgerActorFactory.php"
$ledger = EntityRefFactory::for($spawner, WalletLedger::class)
    ->using($emFactory)
    ->withConnectionLifecycle(
        acquire: static fn() => $pool->take(),
        release: static fn(Connection $c) => $pool->release($c),
    )
    ->withReceiveTimeout(Duration::seconds(60))
    ->withReplayPolicy(new CreateIfMissing(fn(string $id) => new WalletLedger($id)))
    ->handle($commandHandler)
    ->build();
```

What happens after `withReceiveTimeout(Duration::seconds(60))` is set:

1. Actor processes a user message. Internal timer starts (60s).
2. Another user message arrives within 60s. Timer resets.
3. 60s of silence passes. Framework delivers a `ReceiveTimeout` signal.
4. Default behavior: `Behavior::stopped()`. The actor's `PostStop` fires — connection released, entity manager closed, in-memory state freed.
5. Next message for the same id arrives. The factory notices the cached ref is dead, spawns a fresh actor, and the replay policy reloads from storage.

For the caller, this is invisible. For your memory footprint, resident actor count equals steady-state concurrent activity.

## Choosing the timeout

The tradeoff is straightforward: short timeouts free memory faster but pay the re-activation cost (one DB round trip) more often. Long timeouts keep the cache warm but hold resources.

A useful heuristic: **target the median inter-message gap multiplied by 5.**

- Users touch their wallet once every ~10 seconds → 60s timeout. Active sessions stay resident; idle sessions evaporate.
- Chat room messages are bursty, then quiet for minutes → 5-minute timeout. You don't want to reload room state mid-conversation.
- IoT sensor pings every 30 seconds predictably → 5-minute timeout. The sensor is always hot; you're effectively pinning it.

Measure the inter-message gap before guessing.

## Disabling passivation mid-lifecycle

Sometimes you need to suppress passivation temporarily. A wallet that opened a long-running transaction shouldn't passivate between `BEGIN` and `COMMIT`:

```php title="src/Actor/LedgerActor.php"
private function openTxn(ActorContext $ctx): Behavior
{
    $ctx->setReceiveTimeout(null);  // don't passivate while a txn is open
    return Behavior::same();
}

private function closeTxn(ActorContext $ctx): Behavior
{
    $ctx->setReceiveTimeout(Duration::seconds(60));  // back to passivatable
    return Behavior::same();
}
```

Call `$ctx->setReceiveTimeout(null)` inside the handler to cancel the timer. Restore it with a `Duration` when the actor returns to a passivatable state.

## Per-request actors

If your actor's lifetime is naturally one HTTP request, don't use `ReceiveTimeout` at all:

```php title="src/Bootstrap/AppBootstrap.php"
$app->perRequestActor(
    'request-ctx',
    Props::fromBehavior(RequestContextActor::behavior()),
);
```

The framework spawns one per request and stops it after the response goes out. The request boundary is the passivation boundary; no timer needed.

## Reload policies

Whatever your `ReplayPolicy` says is what gets re-loaded on re-activation:

- **`FailIfMissing`** — `find($class, $id)` or throw. Use when the aggregate must already exist; a missing id is a bug in the caller.
- **`CreateIfMissing($factory)`** — find or create and persist. Use for "spawn on first interaction" aggregates such as wallets or chat rooms.
- **`OnDemand`** — defer the load to the first command's handler. Use when the entity is large and you want the actor to start immediately, paying the load cost only when a command actually needs the state.

`CreateIfMissing` is the right default for user-facing aggregates. `FailIfMissing` is safer for protocol-created entities where a missing id indicates a bug.

## What passivation costs

Be honest about the tradeoffs:

- **Cold-start latency.** Re-activation pays one DB round trip plus actor-spawn overhead. Negligible for most workloads; real for latency-sensitive ones.
- **Lost in-memory caches.** Whatever the actor accumulated in PHP-level state is gone on passivation. If the actor maintains a hot lookup table that's expensive to rebuild, keep it resident.
- **Death-watch interactions.** If parent A was watching child B and B passivates, the watch fires as `Terminated(B)`. Make sure your watcher distinguishes "B died permanently" from "B passivated and will respawn."

For most CRUD-style aggregates, none of these matter. For specialised actors — workflow engines, in-memory routing tables — they might. Keep those resident.

## Next steps

- [Passivation](../core-concepts/passivation.md) — how `ReceiveTimeout` and lifecycle signals work under the hood
- [Pooled connections behind actors](./pooled-connections.md) — how passivation interacts with connection pool sizing
- [Scaling out](./scaling.md) — passivation timing as a scaling knob
