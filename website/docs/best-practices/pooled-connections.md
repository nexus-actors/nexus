---
sidebar_position: 4
title: Pooled connections behind actors
related:
  - best-practices/passivation
  - best-practices/scaling
  - guides/single-writer-aggregates
  - core-concepts/actors
---

# Pooled connections behind actors

You can have 10,000 active actor identities and 32 database connections without deadlock — but only if each actor borrows a connection on activation and releases it on passivation rather than holding one permanently.

## The wrong wiring

The simplest `EntityBehavior` setup creates a fresh connection per actor and closes it on `PostStop`:

:::caution Slow connection leak
```php title="src/Factory/LedgerActorFactory.php" verify:lint-only
// DO NOT use this with a connection pool.
->withConnectionSource(
    static fn(): Connection => DriverManager::getConnection($params)
)
```

The runner calls `$connection->close()` on `PostStop`. If the connection came from a pool, `close()` destroys it — the pool's total counter never decrements. After enough passivations you run out of slots.
:::

## The right wiring

`EntityBehaviorBuilder::withConnectionLifecycle($acquire, $release)` takes both halves of the borrow contract. The runner calls `$release($conn)` on `PostStop` instead of `close()`:

```php title="src/Factory/LedgerActorFactory.php"
$ledgers = EntityRefFactory::for(new ActorSystemSpawner($system), WalletLedger::class)
    ->using(new DefaultEntityManagerFactory($ormConfig))
    ->withConnectionLifecycle(
        acquire: static fn() => $connPool->take(),
        release: static fn(Connection $c) => $connPool->release($c),
    )
    ->withReceiveTimeout(Duration::seconds(60))
    ->withReplayPolicy(new CreateIfMissing(fn(string $id) => new WalletLedger($id)))
    ->handle($commandHandler)
    ->build();
```

Two things make this safe:

1. The actor borrows a connection on activation and releases it on `PostStop`. Net pool usage equals the number of *active* actors at any given moment, not total spawned over time.
2. `withReceiveTimeout` passivates idle actors. Their connection goes back to the pool. The next message for that owner spawns a fresh actor that re-acquires.

## The math

Suppose you have 10,000 owners total, p99 active concurrency of 200 owners touching their wallet within a 60-second window, a 60-second receive timeout, and a pool size of 32. At any moment, up to 200 owners hold pooled connections — still over 32, so 168 acquire attempts queue at the pool. Two options:

**Raise pool size to 256.** Postgres handles thousands of connections on modern hardware; the cost is memory per backend. This is the simplest fix for most workloads.

**Shorten the timeout.** A 5-second receive timeout passivates aggressively. The hot 32 owners stay resident; the rest cycle. Cold re-activation pays one connection acquire plus one entity load. Acceptable for write-on-occasion aggregates; costly for write-every-second ones.

The tuning knob is `withReceiveTimeout`. Make it small enough that the steady-state count of resident actors sits comfortably under the pool size.

## When you can't passivate

Some actors legitimately stay hot — a chat room with thousands of messages per minute, an open WebSocket session, an in-flight payment workflow. Three approaches:

**Dedicated connections.** If the actor genuinely needs sole ownership of a connection for hours, let it have one — but cap the number of such actors with a router or LRU upstream so you don't spawn unboundedly.

**Worker pool with consistent-hash routing.** With N worker threads each owning M connections, a hot id pins one slot on one thread. Total ceiling = N × M. The router prevents thrash; the thread isolates failure.

**Actor-layer sharding.** Have one "writer" actor per shard (say, 32 actors total) that owns its connection permanently and route per-id traffic through it. You lose per-id concurrency within a shard, but you get a hard cap on connection use.

## Pool the read path; dedicate the write path

Entity actors on the write path need `UoW` identity continuity. Read handlers are short transactions that end with the response. Wire them separately:

- Read handlers: borrow from `ConnectionPool` or `EntityManagerPool`, return on response.
- Write actors: own a dedicated `EntityManager` via `withConnectionLifecycle`.

If you wire it wrong — write actor borrows from `EntityManagerPool` — you get unit-of-work identity bugs that are difficult to diagnose. Wire it right and you can scale reads via pool size and writes via worker-pool sharding, independently.

## Handling pool exhaustion

Even with correct passivation, a traffic spike can briefly exceed pool capacity. Without a guard, the last handler in line hangs on `pool->take()` until a connection frees up or the borrow timeout fires — then throws a `PoolExhaustedException` that the default error mapper turns into a 500.

Register the included middleware once at boot:

```php title="src/Bootstrap/MiddlewareBootstrap.php"
$app->middleware(new ConnectionScopeMiddleware($connPool));
$app->middleware(new EntityManagerScopeMiddleware($emPool));
$app->middleware(new PoolExhaustedToServiceUnavailable(new Psr17Factory()));
```

`PoolExhaustedToServiceUnavailable` must run outermost of the scope middlewares so it catches exhaustion exceptions thrown lazily inside the handler. Pool exhaustion becomes a 503 with `Retry-After: 1`. Clients back off. The pool drains. The next attempt succeeds.

## Next steps

- [Passivation and memory](./passivation.md) — how `ReceiveTimeout` keeps pool usage bounded
- [Scaling out](./scaling.md) — pool size as a scaling knob alongside worker count and passivation timing
- [Single-writer aggregates](../guides/single-writer-aggregates.md) — why the write actor needs its own dedicated `EntityManager`
