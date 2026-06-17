# nexus-wallet-app

End-to-end Nexus example. A small bearer-authenticated HTTP API on top of
event-sourced actor wallets, served by Swoole in thread mode.

This is **a standalone Composer project** living inside the Nexus monorepo
under `examples/`. It's self-contained — its own `composer.json`,
`Dockerfile`, `docker-compose.yml`, `Makefile`, `.github/workflows/ci.yml`,
PHPUnit suite, and load tester. You can copy the folder out to its own repo
and `git init` it without changes.

## What it demonstrates

| Concern                       | How it shows up                                                                  |
|-------------------------------|----------------------------------------------------------------------------------|
| Actors                        | `WalletDirectoryActor`, `WalletActor`, `RequestActor` — three lifetimes          |
| Authentication                | `AuthenticationMiddleware` + `StaticTokenAuthenticator` + `#[FromPrincipal]`     |
| Database access (event store) | `InMemoryEventStore`; swap to `DbalEventStore` for Postgres                      |
| Actor-per-request             | `$app->perRequestActor('request', …)` — spawned fresh per HTTP call              |
| Persistent state              | `EventSourcedBehavior::create(...)` on `WalletActor` — replay on restart         |

## Architecture

```
   HTTP request                                    HTTP response
        │                                                ▲
        ▼                                                │
 ┌─────────────────────────────────────────────────────────────┐
 │ Swoole worker thread (4 by default)                         │
 │  ┌──────────────────────────────────────────────────────┐   │
 │  │ AuthenticationMiddleware    stamps Principal          │   │
 │  ├──────────────────────────────────────────────────────┤   │
 │  │ BalanceHandler / DepositHandler / WithdrawHandler     │   │
 │  │   #[FromPrincipal] $principal                         │   │
 │  │   #[FromActor('request')] $request                    │   │
 │  │   #[FromActor('wallets')] $directory                  │   │
 │  └─────────────┬────────────────────────────────────────┘   │
 │                │ ask(HandleRequest{owner, action, …})        │
 │                ▼                                              │
 │           RequestActor ── per-request, fresh each call ──    │
 │                │ ask(EnsureWallet{ownerId})                  │
 │                ▼                                              │
 │     WalletDirectoryActor ── long-lived router ───────────    │
 │                │ spawn child if absent                       │
 │                ▼                                              │
 │           WalletActor("alice") ── event-sourced ─────────    │
 │                │ Effect::persist(MoneyDeposited)             │
 │                ▼                                              │
 │           InMemoryEventStore                                 │
 └─────────────────────────────────────────────────────────────┘
```

Each thread runs an **independent ActorSystem with its own event store**.
For shared state across threads, swap `InMemoryEventStore` for
`DbalEventStore` against a Postgres connection — the actor code does not
change.

## Quick start

```bash
make build        # build the docker image (PHP 8.5 ZTS + Swoole 6.0 threads)
make install      # composer install inside the container
make up           # start the server on :8080
make logs         # tail server logs

# Deposit 1000 cents to Alice's wallet
curl -X POST -H "Authorization: Bearer alice-token" \
     -H "Content-Type: application/json" \
     -d '{"amount":1000}' \
     http://localhost:8080/wallet/deposit

# Check Alice's balance
curl -H "Authorization: Bearer alice-token" \
     http://localhost:8080/wallet/balance

# Try to withdraw more than she has → 422 with rejection reason
curl -X POST -H "Authorization: Bearer alice-token" \
     -H "Content-Type: application/json" \
     -d '{"amount":99999}' \
     http://localhost:8080/wallet/withdraw

# Record a deposit on the Doctrine-backed ledger (single-writer per owner,
# state persisted to SQLite via the EntityBehavior actor)
curl -X POST -H "Authorization: Bearer alice-token" \
     -H "Content-Type: application/json" \
     -d '{"kind":"deposit","amountCents":1000}' \
     http://localhost:8080/wallet/ledger/record

# Read Alice's denormalised ledger (pooled EntityManager injection)
curl -H "Authorization: Bearer alice-token" \
     http://localhost:8080/wallet/ledger

make down
```

## Doctrine ledger — the new path

Alongside the in-memory event-sourced wallet, the app demonstrates the
`nexus-doctrine-dbal` + `nexus-doctrine-orm` integration with a
denormalised per-owner ledger:

| Concern                         | How it shows up                                                  |
|---------------------------------|------------------------------------------------------------------|
| Doctrine entity                 | `Domain\Entity\WalletLedger` — running totals per owner          |
| Pooled `EntityManagerInterface` | `LedgerHandler::__invoke(... EntityManagerInterface $em)`        |
| `EntityManagerScopeMiddleware`  | Borrows EM from pool on first use, releases at response          |
| `EntityBehavior` actor          | `Actor\LedgerActor` — one actor per owner, entity-as-state       |
| `EntityRefFactory`              | Single-writer per `(WalletLedger, ownerId)`; spawn-once cache    |
| `EntityEffect::persist()`       | Auto-flush inside the actor on each successful command           |
| Schema bootstrap                | `SchemaTool::updateSchema()` once per worker startup             |

The ledger uses **file-backed SQLite** per worker thread
(`/tmp/wallet-ledger-{worker}.db`). This is a demo. In production, point
all workers at one Postgres URL and the EM pool handles concurrent
borrows — the actor's single-writer guarantee per `(class, ownerId)`
still holds within each worker. For a single source of truth across
workers, route writes through a shared lookup actor (the same pattern as
`WalletDirectoryActor`).

## Performance testing

The repo ships a Swoole-coroutine-based load tester at
`perf/deposit-load.php`.

```bash
make up           # boot the server
make perf         # runs the perf profile in docker-compose
# OR run the script directly against a remote target:
docker compose run --rm app php perf/deposit-load.php \
    http://app:8080 alice-token 30 32
```

Output:

```
== nexus-wallet-app deposit-load ==
target:      http://app:8080
token:       alice-token
duration:    30s
concurrency: 32

results
  elapsed:    30.01s
  ok:         847312
  errors:     0
  rps:        28233
  latency p50: 0.92ms
  latency p95: 1.74ms
  latency p99: 3.11ms
  latency max: 28.40ms
```

## Authentication

Three demo tokens are baked in via the `WALLET_AUTH_TOKENS` env var:

```
WALLET_AUTH_TOKENS=alice-token=alice,bob-token=bob,carol-token=carol
```

Format: `token1=user1,token2=user2,…`. Each token maps to a `SimplePrincipal`
with `roles=[user]` and scopes `wallet:read`, `wallet:write`.

For real auth, swap `StaticTokenAuthenticator` for `JwtAuthenticator`
(also in `nexus-actors/http-auth`) and verify against your IdP — the
middleware contract is identical.

## File layout

```
examples/nexus-wallet-app/
├── composer.json                  # depends on monadial/nexus-* packages
├── Dockerfile                     # PHP 8.5 ZTS + Swoole 6.0 thread-mode
├── docker-compose.yml             # app service + optional perf client
├── Makefile                       # up/down/test/perf
├── .github/workflows/ci.yml       # build → test → smoke deposit→balance
├── public/server.php              # Swoole-Threads entry point
├── src/
│   ├── Actor/
│   │   ├── WalletDirectoryActor.php  # long-lived router (1 per thread)
│   │   ├── WalletActor.php           # event-sourced (1 per owner)
│   │   ├── RequestActor.php          # per-request orchestrator
│   │   ├── HandleRequest.php         # message sent by handler → request actor
│   │   ├── EnsureWallet.php          # message sent to directory
│   │   └── WalletRef.php             # directory reply
│   ├── Domain/
│   │   ├── Money.php                 # value object (cent-precision)
│   │   ├── Command/                  # Deposit, Withdraw, GetBalance
│   │   ├── Event/                    # WalletOpened, MoneyDeposited, MoneyWithdrawn
│   │   ├── State/WalletState.php     # immutable, folded from events
│   │   └── Reply/                    # BalanceSnapshot, DepositResult, WithdrawResult
│   └── Http/
│       ├── Handler/
│       │   ├── BalanceHandler.php   # GET  /wallet/balance
│       │   ├── DepositHandler.php   # POST /wallet/deposit
│       │   └── WithdrawHandler.php  # POST /wallet/withdraw
│       └── Auth/DemoUsers.php       # bearer-token map
├── tests/Unit/WalletStateTest.php   # state-fold invariants
└── perf/deposit-load.php            # Swoole-coroutine load tester
```

## Swapping to a real database

In `public/server.php`, replace:

```php
$eventStore = new InMemoryEventStore();
```

with:

```php
$conn = DriverManager::getConnection(['url' => getenv('DATABASE_URL')]);
$eventStore = new DbalEventStore($conn);
```

Add `nexus-actors/persistence-dbal` to `composer.json`, run the
`PostgresEventStoreSchema` migration once at boot, and you're done — none
of the actor code changes.

## License

MIT. See [`LICENSE`](LICENSE).
