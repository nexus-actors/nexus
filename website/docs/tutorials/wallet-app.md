---
sidebar_position: 2
title: Wallet App
---

# Wallet App

The wallet app is the densest of the Nexus examples. In ~700 lines of PHP
it composes:

- a multi-thread Swoole HTTP server with per-thread `ActorSystem`s
- an **event-sourced** wallet aggregate (per owner) backed by an
  in-memory event store
- a **Doctrine-backed** ledger entity (per owner) maintained by an
  `EntityBehavior` actor with idle passivation
- two independent Doctrine pools — a `ConnectionPool` for raw SQL and
  an `EntityManagerPool` for ORM use — both scoped per request via
  PSR-15 middleware
- bearer-token authentication with `Principal` resolved by attribute
- async logging through `NexusLogger`, with a synchronous Monolog
  fallback for the pre-actor boot window
- graceful shutdown wired through Swoole's `BeforeShutdown` event

It is also a real working app: `docker compose up -d` and you can talk
to it on `:9080` with `curl`.

## What it does

Each user (identified by their bearer token) has a wallet. The wallet
supports deposit, withdraw, and balance lookup. It is implemented twice
on purpose:

1. The **event-sourced wallet** under `/wallet/{balance,deposit,withdraw}`
   is the canonical Nexus aggregate pattern — every command produces
   events, every event is appended to an event store, the balance is
   replayed from history on each access.
2. The **Doctrine ledger** under `/wallet/ledger*` is the same domain
   modelled as a single mutable row plus an append-only entries table.
   The writer is an `EntityBehavior` actor (one per owner) that owns
   its own `EntityManager` and serialises writes for that owner.

There is one admin endpoint at `/admin/wallets` that ranks every ledger
by net balance — it deliberately uses **raw DBAL** through the pool,
not the ORM, to show what a "just give me SQL" handler looks like
inside the same composition root.

## Why actors?

This is the question the example is fundamentally trying to answer.

A naive PHP HTTP service for the same domain looks like:

```text
POST /wallet/deposit
  → start a transaction
  → SELECT … FOR UPDATE
  → UPDATE balance
  → COMMIT
```

That works. It also takes a row lock on every write, serialises
*globally* through Postgres, fails under contention with deadlock
exceptions, and gives you nothing if you ever want to add a side-effect
("notify the user", "publish to Kafka", "queue an email") that has to
happen *at most once* per state transition.

The actor framing flips the problem:

> Every wallet owner gets exactly one in-process actor. That actor is
> the only thing that ever writes to that wallet. The actor reads its
> own state, mutates it, writes the events/rows, fires the side
> effects — all without holding a row lock — and replies to the
> caller.

You get four properties for free that the SQL approach makes you
build by hand:

1. **Single-writer per entity.** No two requests can race against the
   same wallet inside the same `ActorSystem`. `EntityRefFactory::of($id)`
   guarantees one live actor per id; the second concurrent request just
   enqueues onto the same mailbox.
2. **Linear ordering.** The actor processes its mailbox one message at
   a time. The "happens before" of deposit → withdraw → balance is
   simply the queue order. There is no ABA, no lost-update, no need
   for `version` columns or optimistic-lock retries (Doctrine's
   `OptimisticLockException` still surfaces if the row was touched out
   of band, and the supervisor restarts the actor and replays — that's
   the only retry path).
3. **Supervised failures.** If a handler throws, the supervisor restarts
   the actor; its mailbox is preserved across the restart. The HTTP
   request that *caused* the failure still sees an error, but the next
   request finds a healthy actor instead of a corrupt half-state.
4. **Idle passivation.** A wallet that hasn't been touched for 2 minutes
   stops, releases its connection back, and frees its memory. The next
   request for the same owner spawns a fresh actor that reloads from
   storage. Memory stays bounded regardless of how many users you have.

You don't get any of those for free with `SELECT … FOR UPDATE`.

## Architecture

```text
                        ┌──────────────────────────────┐
                        │   Swoole HTTP server (main)  │
                        │      + BeforeShutdown        │
                        └───────────────┬──────────────┘
                                        │
              ┌─────────────────────────┼─────────────────────────┐
              │                         │                         │
       ┌──────▼─────┐            ┌──────▼─────┐            ┌──────▼─────┐
       │  Thread 0  │            │  Thread 1  │     ...    │  Thread N  │
       │ ActorSystem│            │ ActorSystem│            │ ActorSystem│
       │ ConnPool   │            │ ConnPool   │            │ ConnPool   │
       │ EmPool     │            │ EmPool     │            │ EmPool     │
       │            │            │            │            │            │
       │ ┌──────┐   │            │ ┌──────┐   │            │ ┌──────┐   │
       │ │alice │   │            │ │bob   │   │            │ │carol │   │
       │ │ledger│   │            │ │ledger│   │            │ │ledger│   │
       │ └──────┘   │            │ └──────┘   │            │ └──────┘   │
       │     │      │            │     │      │            │     │      │
       └─────┼──────┘            └─────┼──────┘            └─────┼──────┘
             │                         │                         │
             └─────────────────────────┴─────────────────────────┘
                                       │
                              ┌────────▼────────┐
                              │    Postgres     │
                              │  (single SoT)   │
                              └─────────────────┘
```

Per-thread isolation is intentional. Each Swoole worker thread runs
its own `ActorSystem`, owns its own pools, and routes its requests
locally; Postgres is the single source of truth across threads. This
makes per-thread state cheap and the failure domain small — one
crashing thread doesn't poison the others.

A wallet owner can therefore be served by **different threads at
different times** — what's guaranteed is that within any one thread
they're serialised. The cross-thread story (sticky routing or
single-thread-per-owner) is built on top of the
[worker pool](../scaling/overview.md) when you need it.

## Where things live

The composition root is intentionally short:

```
examples/nexus-wallet-app/
├── public/server.php             ← 44 lines: config → bootstrap → run
└── src/
    ├── Actor/
    │   ├── LedgerActor.php       ← EntityBehavior writer (per owner)
    │   ├── RequestActor.php      ← per-request ephemeral actor
    │   └── WalletDirectoryActor.php  ← supervises event-sourced wallets
    ├── Boot/
    │   ├── WalletConfig.php      ← typed env config (Http/Db/Auth)
    │   ├── WalletBootstrap.php   ← main-thread step (logger + hook)
    │   ├── SchemaBootstrap.php   ← idempotent schema sync
    │   ├── DoctrineKit.php       ← per-worker pools + LedgerActor factory
    │   └── WalletApp.php         ← per-worker HTTP factory closure
    ├── Domain/
    │   ├── Entity/               ← Doctrine entities (WalletLedger, LedgerEntry)
    │   └── Command/              ← message types (RecordLedger, …)
    └── Http/
        ├── WalletRoutes.php      ← route registration, grouped by feature
        ├── JsonExceptionRenderer.php
        └── Handler/              ← one file per endpoint
```

`server.php` reads:

```php
$config = WalletConfig::fromEnv();
$boot   = WalletBootstrap::run($config);

SwooleThreadServer::run(
    SwooleThreadConfig::bind($config->http->host, $config->http->port)
        ->threads($config->http->threads)
        ->shutdownTimeout(Duration::seconds(5)),
    WalletApp::factory($config),
);
```

That's the whole entry point. Everything else is named code under
`src/Boot` and `src/Http`.

## Two pools, two scopes

The wallet-app uses both Doctrine pools at the same time, on purpose,
because handlers do *both* kinds of work:

```php
// Raw SQL — ConnectionScopeMiddleware lends a Doctrine\DBAL\Connection
public function __invoke(Connection $conn): ResponseInterface
{
    $rows = $conn->fetchAllAssociative('SELECT … ORDER BY net DESC');
    // …
}

// ORM / DQL — EntityManagerScopeMiddleware lends an EntityManagerInterface
public function __invoke(EntityManagerInterface $em): ResponseInterface
{
    $entries = $em->getRepository(LedgerEntry::class)->findBy([...]);
    // …
}
```

The two pools have their own sizes and their own eviction policies, so
a slow OR-mapper hotspot doesn't starve the raw-SQL admin path (or
vice versa). Both pools' exhaustion is mapped to a `503` with
`Retry-After: 1` by `PoolExhaustedToServiceUnavailable`, which sits
**outermost** in the middleware stack so it can catch the lazy
`$lease->get()` exception that fires inside the handler.

Middleware order (top = outermost):

1. `PoolExhaustedToServiceUnavailable`
2. `ConnectionScopeMiddleware`
3. `EntityManagerScopeMiddleware`
4. `AuthenticationMiddleware`
5. (router → handler)

`ConnectionScopeMiddleware` poisons only on `Doctrine\DBAL\Exception`
— a `NotFoundHttpException` or any other domain throwable releases the
connection back to the pool intact rather than evicting it.

The `LedgerActor` is the exception to the pool story. EntityBehavior's
invariant is *one dedicated `EntityManager` per actor*, so every spawn
opens a fresh connection (via `DriverManager::getConnection`) and
closes it on `PostStop`. This is what
[`EntityBehaviorBuilder::withConnectionSource`](../doctrine/entity-behavior.md)
gives you. If you'd rather borrow from a pool, use
`withConnectionLifecycle($acquire, $release)` instead, and the actor
will return the slot on passivation.

## The boot flow, end to end

1. `WalletConfig::fromEnv()` resolves env vars into a typed tree.
2. `WalletBootstrap::run()` on the **main thread**:
   a. builds the stderr Monolog logger,
   b. installs `SWOOLE_HOOK_ALL` via `DoctrineBootstrap::enable()` —
      child threads silently no-op the hook install, so it has to be
      here.
3. `SwooleThreadServer::run(…, WalletApp::factory($config))` starts
   the server and calls the per-worker factory once per thread.
4. The factory closure (`WalletApp::factory`):
   a. opens the async `NexusLogger` for that thread,
   b. calls `DoctrineKit::build()` which runs `SchemaBootstrap::sync()`
      (idempotent + race-tolerant: every thread tries, the
      `UniqueConstraintViolationException` from the loser is swallowed),
   c. composes the HTTP app: registers root actors, middlewares, param
      resolvers, exception renderer, then routes,
   d. returns the compiled application — Swoole keeps it for the
      duration of the worker's life.
5. On `SIGTERM`, the server's `BeforeShutdown` event flips a
   `Thread\Atomic` that a per-worker watchdog coroutine polls. The
   watchdog calls `ActorSystem::shutdown(Duration::seconds(5))` which
   broadcasts `PoisonPill`, yields cooperatively until actors drain,
   then force-closes any mailboxes that didn't finish in time — all
   *before* Swoole's reactor-exit timeout fires. See
   [Graceful shutdown](../runtimes/swoole.md#graceful-shutdown-thread-mode)
   for the full sequence.

## Try it

```bash
cd examples/nexus-wallet-app
docker compose up -d

# Index
curl localhost:9080/ | jq .

# Authed write (LedgerActor will spawn for owner 'alice')
curl -H 'Authorization: Bearer alice-token' \
     -X POST localhost:9080/wallet/ledger/record \
     -H 'content-type: application/json' \
     -d '{"kind":"deposit","amountCents":12345,"description":"smoke"}'

# Read (uses the EM pool, NOT the actor)
curl -H 'Authorization: Bearer alice-token' \
     localhost:9080/wallet/ledger | jq .

# Cross-owner admin view (raw DBAL via ConnectionPool)
curl localhost:9080/admin/wallets | jq .

# Graceful stop — no FATAL lines in the log
docker compose stop --timeout 30 app
docker compose logs app | grep -c FATAL    # → 0
```

## What to read next

- The actor and behavior model: [Behaviors](../core-concepts/behaviors.md)
  and the [`EntityBehavior` DSL](../doctrine/entity-behavior.md).
- The pool + scope middlewares: [Doctrine HTTP integration](../doctrine/http-integration.md).
- Why idle passivation matters and how the timer is wired:
  [Passivation](../core-concepts/passivation.md).
- The shutdown wiring this example relies on:
  [Swoole runtime — Graceful shutdown](../runtimes/swoole.md#graceful-shutdown-thread-mode).
