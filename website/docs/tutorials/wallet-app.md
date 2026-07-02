---
sidebar_position: 2
title: Wallet App
related:
  - core-concepts/behaviors
  - doctrine/entity-behavior
  - core-concepts/passivation
  - runtimes/swoole
---

# Wallet App

This tutorial walks through the wallet-app example — a multi-thread Swoole HTTP server that combines event-sourced aggregates, Doctrine-backed entity actors, connection pooling, and graceful shutdown. By the end you will understand how each of those pieces composes and why actors solve the concurrency problem better than `SELECT … FOR UPDATE`.

## What the app does

Each user (identified by their bearer token) has a wallet. The wallet supports deposit, withdraw, and balance lookup, implemented two ways on purpose:

1. The **event-sourced wallet** under `/wallet/{balance,deposit,withdraw}` — every command produces events, every event is appended to an event store, the balance is replayed from history.
2. The **Doctrine ledger** under `/wallet/ledger*` — the same domain modelled as a single mutable row plus an append-only entries table. The writer is an `EntityBehavior` actor (one per owner) that owns its own `EntityManager` and serialises writes for that owner.

One admin endpoint at `/admin/wallets` ranks every ledger by net balance using raw DBAL, not the ORM.

## Why actors

A naive PHP HTTP service for the same domain looks like:

```text
POST /wallet/deposit
  → start a transaction
  → SELECT … FOR UPDATE
  → UPDATE balance
  → COMMIT
```

That works. It also serialises globally through Postgres, fails under contention with deadlock exceptions, and gives you nothing when a side-effect ("notify the user", "publish to Kafka") must happen at most once per state transition.

The actor framing flips the problem: every wallet owner gets exactly one in-process actor. That actor is the only thing that ever writes to that wallet. It reads its own state, mutates it, writes the events or rows, fires the side effects — without holding a row lock — and replies to the caller.

You get four properties for free:

1. **Single-writer per entity.** `EntityRefFactory::of($id)` guarantees one live actor per ID; the second concurrent request enqueues onto the same mailbox.
2. **Linear ordering.** The actor processes its mailbox one message at a time. Deposit → withdraw → balance is simply queue order — no ABA, no lost-update, no `version` columns.
3. **Supervised failures.** If a handler throws, the supervisor restarts the actor. Its mailbox is preserved across the restart. The next request finds a healthy actor.
4. **Idle passivation.** A wallet untouched for two minutes stops, releases its connection, and frees its memory. The next request for the same owner spawns a fresh actor that reloads from storage.

## Architecture overview

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
   │ ┌──────┐   │            │ ┌──────┐   │            │ ┌──────┐   │
   │ │alice │   │            │ │bob   │   │            │ │carol │   │
   │ │ledger│   │            │ │ledger│   │            │ │ledger│   │
   │ └──────┘   │            │ └──────┘   │            │ └──────┘   │
   └─────┼──────┘            └─────┼──────┘            └─────┼──────┘
         │                         │                         │
         └─────────────────────────┴─────────────────────────┘
                                   │
                          ┌────────▼────────┐
                          │    Postgres     │
                          │  (single SoT)   │
                          └─────────────────┘
```

Each Swoole worker thread runs its own `ActorSystem`, owns its own pools, and routes its requests locally. Postgres is the single source of truth across threads. One crashing thread does not poison the others.

## Where things live

```
examples/nexus-wallet-app/
├── public/server.php             ← config → bootstrap → run (44 lines)
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

The entry point is intentionally short:

```php title="examples/nexus-wallet-app/public/server.php"
$config = WalletConfig::fromEnv();
$boot   = WalletBootstrap::run($config);

SwooleThreadServer::run(
    SwooleThreadConfig::bind($config->http->host, $config->http->port)
        ->threads($config->http->threads)
        ->shutdownTimeout(Duration::seconds(5)),
    WalletApp::factory($config),
);
```

## Two pools, two scopes

The wallet-app uses both Doctrine pools simultaneously because handlers do both kinds of work:

```php title="src/Http/Handler/AdminWalletsHandler.php"
// Raw SQL — ConnectionScopeMiddleware lends a DBAL Connection
public function __invoke(Connection $conn): ResponseInterface
{
    $rows = $conn->fetchAllAssociative('SELECT … ORDER BY net DESC');
    // …
}
```

```php title="src/Http/Handler/LedgerEntriesHandler.php"
// ORM / DQL — EntityManagerScopeMiddleware lends an EntityManagerInterface
public function __invoke(EntityManagerInterface $em): ResponseInterface
{
    $entries = $em->getRepository(LedgerEntry::class)->findBy([…]);
    // …
}
```

Both pools' exhaustion is mapped to a `503` with `Retry-After: 1` by `PoolExhaustedToServiceUnavailable`, which sits outermost in the middleware stack.

Middleware order (outermost first):

1. `PoolExhaustedToServiceUnavailable`
2. `ConnectionScopeMiddleware`
3. `EntityManagerScopeMiddleware`
4. `AuthenticationMiddleware`
5. (router → handler)

## The boot flow

1. `WalletConfig::fromEnv()` resolves env vars into a typed tree.
2. `WalletBootstrap::run()` on the **main thread**: builds the stderr Monolog logger and installs `SWOOLE_HOOK_ALL`. Child threads silently no-op the hook install, so it must happen here.
3. `SwooleThreadServer::run(…, WalletApp::factory($config))` starts the server and calls the per-worker factory once per thread.
4. The factory closure opens the async `NexusLogger`, runs `SchemaBootstrap::sync()` (idempotent and race-tolerant), composes the HTTP app, and returns the compiled application.
5. On `SIGTERM`, the `BeforeShutdown` event flips a `Thread\Atomic` that a per-worker watchdog coroutine polls. The watchdog calls `ActorSystem::shutdown(Duration::seconds(5))`, which broadcasts `PoisonPill`, yields cooperatively until actors drain, then force-closes any mailboxes that didn't finish in time.

## Run it

```bash
cd examples/nexus-wallet-app
docker compose up -d

# Index
curl localhost:9080/ | jq .

# Authenticated write (LedgerActor spawns for owner 'alice')
curl -H 'Authorization: Bearer alice-token' \
     -X POST localhost:9080/wallet/ledger/record \
     -H 'content-type: application/json' \
     -d '{"kind":"deposit","amountCents":12345,"description":"smoke"}'

# Read (uses the EM pool, not the actor)
curl -H 'Authorization: Bearer alice-token' \
     localhost:9080/wallet/ledger | jq .

# Cross-owner admin view (raw DBAL via ConnectionPool)
curl localhost:9080/admin/wallets | jq .

# Graceful stop
docker compose stop --timeout 30 app
docker compose logs app | grep -c FATAL    # → 0
```

## What we built

- A multi-thread Swoole HTTP server where each thread owns an independent `ActorSystem`.
- An event-sourced wallet aggregate and a Doctrine-backed `EntityBehavior` actor implementing the same domain two ways.
- Two Doctrine pools with independent scopes, exhaust-to-503 mapping, and poisoning narrowed to `Doctrine\DBAL\Exception`.
- Graceful shutdown wired through `BeforeShutdown` and a per-worker watchdog coroutine.

## Next steps

- [`EntityBehavior` DSL](../doctrine/entity-behavior.md) — the DSL `LedgerActor` is built on.
- [Doctrine HTTP integration](../doctrine/http-integration.md) — the pool and scope middlewares.
- [Passivation](../core-concepts/passivation.md) — why idle passivation matters and how the timer is wired.
- [Swoole runtime — graceful shutdown](../runtimes/swoole.md) — the shutdown wiring this example relies on.
