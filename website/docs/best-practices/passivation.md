---
sidebar_position: 6
title: Passivation and memory
---

# Passivation and memory

> *"I have a million users. Do I have a million live actors?"*

No, unless you actively choose to. Nexus's passivation primitive
(`ReceiveTimeout`) is the answer to bounded memory in an unbounded
identity space.

## The rule

> **Any actor that owns expensive resources (DB connections, large
> in-memory state, file handles) should set a `ReceiveTimeout`.**

That includes nearly every `EntityBehavior` actor. The hot path of
your traffic is usually a small subset of total ids — Pareto applies.
Resident actor count should be proportional to *concurrent activity*,
not lifetime cardinality.

## The mechanism

```php
$ledger = EntityRefFactory::for($spawner, WalletLedger::class)
    ->using($emFactory)
    ->withConnectionLifecycle($pool->take(...), $pool->release(...))
    ->withReceiveTimeout(Duration::seconds(60))      // ← here
    ->withReplayPolicy(new CreateIfMissing(/* ... */))
    ->handle($commandHandler)
    ->build();
```

What happens:

1. Actor processes a user message. Internal timer starts (60s).
2. Another user message arrives within 60s. Timer resets.
3. 60s of silence passes. Framework delivers a `ReceiveTimeout` signal.
4. Default behavior: `Behavior::stopped()`. The actor's `PostStop`
   fires — connection released, EM closed, in-memory state freed.
5. Next message for the same id arrives. `EntityRefFactory::of($id)`
   notices the cached ref is dead, spawns a fresh actor, the
   `ReplayPolicy` reloads from storage.

For the user, this is invisible. For your memory footprint, it means
**resident actor count ≈ steady-state concurrent activity**, not
total identity count.

## Choosing the timeout

The tradeoff: short timeouts free memory faster, but pay the
re-activation cost (load entity from DB) more often. Long timeouts
keep the cache warm, but tie up resources.

A useful heuristic: **target the median inter-message gap × 5**.

- "Users hit their wallet once every ~10 seconds" → 60s
  receive-timeout. Active sessions stay resident, idle sessions
  evaporate.
- "Chat room messages bursty, then quiet for minutes" → 5-minute
  timeout. You don't want to reload the room state mid-conversation.
- "IoT sensor pings every 30 seconds, predictably" → 5-minute
  timeout. The sensor is *always* hot; you're effectively pinning.

Measure the inter-message gap before guessing.

## Counter-signal: `setReceiveTimeout(null)`

Sometimes you want to *disable* passivation mid-lifecycle. A wallet
that just opened a long-running transaction shouldn't passivate
between `BEGIN` and `COMMIT`. Call `$ctx->setReceiveTimeout(null)`
inside the handler to cancel the timer; call it again with a
`Duration` when you're back to a passivatable state.

```php
public function handle(ActorContext $ctx, object $msg): Behavior
{
    return match (true) {
        $msg instanceof BeginTransaction => $this->openTxn($ctx),
        $msg instanceof CommitTransaction => $this->closeTxn($ctx),
        default                          => Behavior::same(),
    };
}

private function openTxn(ActorContext $ctx): Behavior
{
    $ctx->setReceiveTimeout(null);  // don't passivate while a txn is open
    // …
}

private function closeTxn(ActorContext $ctx): Behavior
{
    $ctx->setReceiveTimeout(Duration::seconds(60));  // back to passivatable
    // …
}
```

## Counter-signal: per-request actors

If your actor's lifetime is naturally one HTTP request, don't reach
for `ReceiveTimeout` at all. Use `$app->perRequestActor(...)`:

```php
$app->perRequestActor('request-ctx', Props::fromBehavior(RequestContextActor::behavior()));
```

The framework spawns one per request and stops it after the response
goes out. Passivation is the request's natural boundary; you don't
need a timer.

## What gets re-loaded?

Whatever your `ReplayPolicy` says:

- `FailIfMissing` — `$em->find($class, $id)` or throw. Use when the
  aggregate must already exist (the caller created it via a separate
  command somewhere upstream).
- `CreateIfMissing(fn(string $id) => new MyEntity($id))` — find or
  create + persist. Use for "spawn on first interaction" aggregates
  (chat rooms, wallets that exist by virtue of someone wanting one).
- `OnDemand` — defer the load to the first command's handler. Use
  when the entity is large and you want the actor to start
  immediately, paying the load cost only when a command actually
  needs the state.

Pick deliberately. `CreateIfMissing` is the most flexible default for
user-facing aggregates; `FailIfMissing` is the safest for protocol-
created entities where a missing id is a bug.

## What you give up

Passivation has costs. Be honest about them:

1. **Cold-start latency.** Re-activation pays one DB round trip
   plus actor-spawn overhead (microseconds, but real).
2. **Lost in-memory caches.** Whatever the actor accumulated in
   PHP-level state is gone. If your actor maintains a hot lookup
   table that's expensive to rebuild, passivation is the wrong move.
3. **Watched relationships.** If parent A was watching child B and
   B passivates, the watch fires (`Terminated(B)`). Make sure your
   watcher distinguishes "B died" from "B passivated and will respawn."

For most CRUD-style aggregates, none of these matter. For specialised
actors (workflow engines, in-memory routing tables) they might —
keep those resident.

## The wallet-app's choice

`LedgerActor` sets `withReceiveTimeout(Duration::seconds(120))`. Two
minutes of inactivity → passivate. The reasoning:

- A user who just touched their wallet is likely to touch it again
  within ~30 seconds (UI patterns). 120s covers two such bursts.
- Re-activation is one Postgres SELECT (microseconds in practice
  with a warm cache). Acceptable.
- Resident actor count tracks "people actively transacting" rather
  than "people who have ever transacted." Memory bounded by
  steady-state load.

If the wallet-app's traffic shifted to "many users polling
infrequently," 120s would be a poor choice — bump it to 600s.
Measure, don't guess.
