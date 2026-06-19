---
sidebar_position: 4
title: Pooled connections behind actors
---

# Pooled connections behind actors

> *"I have 10,000 active users. I have 32 database connections in the
> pool. If each user has their own actor and each actor holds a
> connection, I'm dead."*

You're correct that the naive shape dies. Here's the shape that
doesn't.

## The wrong default

`EntityBehavior` actors own a dedicated `EntityManager`. The simplest
wiring takes a fresh connection per actor and closes it on PostStop:

```php
// Dedicated connection per actor. DON'T do this with a pool.
->withConnectionSource(static fn(): Connection => DriverManager::getConnection($params))
```

This works for development. It does NOT work in production with a
connection pool, because the runner calls `$connection->close()` on
PostStop. If the connection came from the pool, that's a slow leak:
the pool's `total` counter never decrements, and after enough
passivations you run out of slots.

## The right wiring: `withConnectionLifecycle`

`EntityBehaviorBuilder::withConnectionLifecycle($acquire, $release)`
takes both halves of the borrow contract. The runner calls
`$release($conn)` on PostStop instead of `close()`.

```php
$ledgers = EntityRefFactory::for(new ActorSystemSpawner($system), WalletLedger::class)
    ->using(new DefaultEntityManagerFactory($ormConfig))
    ->withConnectionLifecycle(
        acquire: static fn() => $connPool->take(),
        release: static fn(Connection $c) => $connPool->release($c),
    )
    ->withReceiveTimeout(Duration::seconds(60))   // ← critical
    ->withReplayPolicy(new CreateIfMissing(fn(string $id) => new WalletLedger($id)))
    ->handle($commandHandler)
    ->build();
```

Two things make this safe:

1. The actor borrows a connection on activation and releases it on
   PostStop. Net pool usage = number of *active* actors at any given
   moment, NOT total spawned over time.
2. The `withReceiveTimeout(Duration::seconds(60))` passivates idle
   actors. Their connection goes back to the pool. The next message
   for that owner spawns a fresh actor that re-acquires.

## The math

Suppose:
- 10,000 owners total
- p99 active concurrency: 200 owners touching the wallet within a
  60-second window
- Receive timeout: 60s
- Pool size: 32

At any moment, ≤200 owners hold pooled connections. That's still over
32 — and 168 acquire attempts queue at the pool. Two options:

**Raise pool size to 256.** Postgres handles thousands of
connections fine on modern hardware; the cost is a memory footprint
per backend. This is the simplest fix.

**Shorten the timeout.** A 5-second receive-timeout passivates
aggressively. The hot 32 owners stay resident; the rest cycle. Cold
re-activation pays one connection acquire + one entity load (a few
ms). Acceptable for write-on-occasion aggregates; bad for
write-every-second ones.

The tuning knob is `withReceiveTimeout(...)`. Make it small enough
that the steady-state count of resident actors is comfortably under
the pool size.

## What if I can't passivate?

Some aggregates legitimately stay hot — a chat room with thousands of
messages a minute, a websocket session, an in-flight payment workflow.
Three approaches:

**1. Use Postgres connections directly, not pooled.** If the actor's
workload genuinely needs sole ownership of a connection for hours,
let it have one — but cap the number of such actors with a router /
LRU upstream so you don't unbounded-spawn.

**2. Use a worker-pool with consistent-hash routing.** With N worker
threads each owning M connections, a given hot id pins one slot on
one thread. Total ceiling = N × M. The router prevents thrash; the
thread isolates failure.

**3. Pool at the actor layer, not the connection layer.** Have one
"writer" actor per shard (say, 32 actors total) that owns its
connection forever, and route per-id traffic through it. You lose
per-id concurrency within a shard, but you trade nothing for a hard
cap on connection use.

## Don't conflate the read pool with the write actor's connection

The wallet-app uses TWO pools at the same time:

- `ConnectionPool` (DBAL) for raw SQL handlers (`AdminAllLedgersHandler`).
- `EntityManagerPool` (ORM) for handlers that need an `EntityManagerInterface`
  on the read path (`LedgerHandler`, `LedgerEntriesHandler`).

The `LedgerActor` (write path) owns its OWN dedicated `EntityManager`
— it doesn't borrow from `EntityManagerPool`, because pooling EMs
across actors would break UoW identity.

The lesson: **pool the read path, dedicate the write path.** A read
handler is a short transaction that ends with the response. A
write actor is a long-lived identity that needs UoW continuity.

If you wire it wrong (write actor borrows from `EntityManagerPool`)
you get UoW-identity bugs that are hellish to debug. If you wire it
right, you can scale reads via pool size and writes via worker-pool
sharding — independently.

## What `PoolExhaustedToServiceUnavailable` is for

Even with correct passivation, a traffic spike can briefly exceed pool
capacity. Without a guard, the handler that's last in line hangs on
`pool->take()` until either a connection frees up or the borrow
timeout fires — and then throws a `PoolExhaustedException` that the
default error mapper turns into a 500.

Register the included middleware once at boot:

```php
$app->middleware(new PoolExhaustedToServiceUnavailable(new Psr17Factory()));
```

…and pool exhaustion becomes a 503 with `Retry-After: 1`. Clients can
back off. The pool drains. The next attempt succeeds.

Order matters — `PoolExhaustedToServiceUnavailable` runs OUTERMOST of
the scope middlewares so it catches exhaustion exceptions thrown
lazily inside the handler. The wallet-app's `WalletApp::registerMiddlewares`
shows the canonical order:

```php
$app->middleware(new ConnectionScopeMiddleware($connPool));
$app->middleware(new EntityManagerScopeMiddleware($emPool));
$app->middleware(new PoolExhaustedToServiceUnavailable(new Psr17Factory()));  // outermost
```
