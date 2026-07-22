# nexus-wallet-app

End-to-end Nexus example. A small bearer-authenticated HTTP API on top of
event-sourced actor wallets, served by Swoole in thread mode.

This is **a standalone Composer project** living inside the Nexus monorepo
under `examples/`. It's self-contained — its own `composer.json`,
`Dockerfile`, `compose.yaml`, `Makefile`, `.github/workflows/ci.yml`,
PHPUnit suite, and load tester. You can copy the folder out to its own repo
and `git init` it without changes.

> **Status: demo / experimental.** This example exists to show Nexus
> wiring, not to model production-grade money movement. The event-sourced
> wallet uses a per-worker in-memory event store (see the durability
> warning below), commands carry no idempotency keys, and there is no
> deduplication of retried requests. Do not copy it as-is for anything
> financial.

## What it demonstrates

| Concern                       | How it shows up                                                                  |
|-------------------------------|----------------------------------------------------------------------------------|
| Actors                        | `WalletDirectoryActor`, `WalletActor`, `LedgerActor` — three lifetimes           |
| Authentication                | `AuthenticationMiddleware` + `DemoUsers` token map + `#[FromPrincipal]`          |
| Database access (event store) | `InMemoryEventStore`; swap to `DbalEventStore` for Postgres                      |
| Actor-per-owner               | Directory spawns/caches one `WalletActor` child per owner on `EnsureWallet`      |
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
 │                │ ask(EnsureWallet{ownerId})                  │
 │                ▼                                              │
 │     WalletDirectoryActor ── long-lived router ───────────    │
 │                │ spawn child if absent, reply WalletRef      │
 │                ▼                                              │
 │           WalletActor("alice") ── event-sourced ─────────    │
 │                │ Effect::persist(MoneyDeposited)             │
 │                ▼                                              │
 │           InMemoryEventStore                                 │
 └─────────────────────────────────────────────────────────────┘
```

Handlers hold a `#[FromActor('wallets')]` reference to the directory,
`ask()` it `EnsureWallet{ownerId}`, receive a `WalletRef` back, then
`ask()` the per-owner `WalletActor` directly. There is no per-request
actor in this app.

> **Warning — not durable, not cross-worker consistent.** Each worker
> thread builds its own `InMemoryEventStore`
> (`src/Boot/WalletApp.php`, `registerActors()`), so wallet balances
> live in process memory, are scoped to whichever worker handled the
> request, and vanish on restart. Two requests from the same user can
> hit two workers and see two different balances. This is a deliberate
> demo simplification — it is consistent only with a single worker
> thread and is **not** a financial-grade setup. For shared, durable
> state across threads, swap `InMemoryEventStore` for `DbalEventStore`
> against a Postgres connection — the actor code does not change. (The
> Doctrine ledger endpoints below already use Postgres and are shared
> across workers.)

## Quick start

```bash
make build        # build the docker image (PHP 8.5 ZTS + Swoole 6.2.1 threads)
make install      # composer install inside the container
make up           # start the server on :8080
make logs         # tail server logs

# Deposit 1000 cents to Alice's wallet
curl -X POST -H "Authorization: Bearer alice-token" \
     -H "Content-Type: application/json" \
     -d '{"amountCents":1000}' \
     http://localhost:8080/wallet/deposit

# Check Alice's balance
curl -H "Authorization: Bearer alice-token" \
     http://localhost:8080/wallet/balance

# Try to withdraw more than she has → 422 with rejection reason
curl -X POST -H "Authorization: Bearer alice-token" \
     -H "Content-Type: application/json" \
     -d '{"amountCents":99999}' \
     http://localhost:8080/wallet/withdraw

# Record a deposit on the Doctrine-backed ledger (single-writer per owner,
# state persisted to Postgres via the EntityBehavior actor)
curl -X POST -H "Authorization: Bearer alice-token" \
     -H "Content-Type: application/json" \
     -d '{"kind":"deposit","amountCents":1000}' \
     http://localhost:8080/wallet/ledger/record

# Read Alice's denormalised ledger (pooled EntityManager injection)
curl -H "Authorization: Bearer alice-token" \
     http://localhost:8080/wallet/ledger

# Last 20 transaction entries (DQL via pooled EM)
curl -H "Authorization: Bearer alice-token" \
     "http://localhost:8080/wallet/ledger/entries?limit=20"

# Admin view — raw SQL via injected DBAL Connection (NOT the ORM EM),
# wrapped in #[Transactional]. Lists all wallets ranked by net balance.
# Requires the admin role: a user token (or no token) is rejected 401/403.
curl -H "Authorization: Bearer admin-token" \
     http://localhost:8080/admin/wallets

make down
```

## Doctrine ledger — the new path

Alongside the in-memory event-sourced wallet, the app demonstrates the
`nexus-doctrine-dbal` + `nexus-doctrine-orm` integration with a
denormalised ledger backed by **Postgres** (single source of truth
across all worker threads).

| Concern                         | How it shows up                                                                          |
|---------------------------------|------------------------------------------------------------------------------------------|
| Two Doctrine entities           | `Domain\Entity\WalletLedger` (running totals) + `Domain\Entity\LedgerEntry` (history)    |
| Pooled `EntityManagerInterface` | `LedgerHandler`, `LedgerEntriesHandler` — ORM/DQL injection                              |
| Pooled DBAL `Connection`        | `AdminAllLedgersHandler` — raw SQL injection (separate pool from the EM pool)            |
| `#[Transactional]` decorator    | `AdminAllLedgersHandler` — wraps the call in `Connection::beginTransaction()` / `commit()` |
| `EntityManagerScopeMiddleware`  | Borrows EM lazily, releases (and clears UoW) at response                                 |
| `ConnectionScopeMiddleware`     | Same shape for the DBAL pool                                                             |
| `EntityBehavior` actor          | `Actor\LedgerActor` — one actor per owner, entity-as-state                               |
| `EntityRefFactory`              | Single-writer per `(WalletLedger, ownerId)`; spawn-once cache + isAlive() re-check       |
| `EntityEffect::persist()`       | Auto-flush inside the actor on each successful command — cascades to `LedgerEntry` rows  |
| `withReceiveTimeout`            | 120s idle → actor passivates, releases EM + Connection; next command rehydrates from DB  |
| Schema bootstrap                | `SchemaTool::updateSchema()` once per worker startup (idempotent)                        |

### Two pools, one Postgres

The app boots **two independent pools** against the same database:

- **`ConnectionPool`** — for handlers that declare `Connection $conn`.
  Raw SQL, no ORM overhead.
- **`EntityManagerPool`** — for handlers that declare
  `EntityManagerInterface $em`. Full ORM/DQL/repository ergonomics.

Each pool owns its own connections — sized independently. The EM pool
also internally manages its own connections (one per pooled EM); the
DBAL pool is for handlers that bypass the ORM. Total connections to
Postgres = `(EM pool max + DBAL pool max) × worker threads`.

Mixed: a handler can declare BOTH a `Connection` and an
`EntityManagerInterface` and the framework borrows from each
independently.

### Endpoints summary

| Method | Path                       | Injection                  | Notes                                          |
|--------|----------------------------|----------------------------|------------------------------------------------|
| GET    | `/wallet/balance`          | `#[FromActor]`             | Original event-sourced wallet                  |
| POST   | `/wallet/deposit`          | `#[FromActor]`             | Original event-sourced wallet                  |
| POST   | `/wallet/withdraw`         | `#[FromActor]`             | Original event-sourced wallet                  |
| GET    | `/wallet/ledger`           | `EntityManagerInterface`   | Pooled EM, single-row find                     |
| GET    | `/wallet/ledger/entries`   | `EntityManagerInterface`   | Pooled EM, DQL paginated                       |
| POST   | `/wallet/ledger/record`    | `EntityRefFactory` (closure) | Fire RecordLedger at the per-owner actor     |
| GET    | `/admin/wallets`           | `Connection`               | Raw SQL + `#[Transactional]` snapshot          |

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

Two separate token lists are baked in, each minting a different role. User
tokens come from `WALLET_AUTH_TOKENS`:

```
WALLET_AUTH_TOKENS=alice-token=alice,bob-token=bob,carol-token=carol
```

Each maps to a `SimplePrincipal` with `roles=[user]` and scopes
`wallet:read`, `wallet:write`. Admin tokens come from a **separate**
`WALLET_ADMIN_TOKENS` list so an admin capability can never be issued by
accident from the user list:

```
WALLET_ADMIN_TOKENS=admin-token=root
```

Admin tokens add `roles=[admin]` and the `wallet:admin` scope. The
`/admin/wallets` route carries `AuthorizationMiddleware` and its handler is
annotated `#[RequiresRole('admin')]`, so anonymous or user-only callers are
rejected (401/403) before any query runs.

Format for both lists: `token1=user1,token2=user2,…`.

For real auth, swap `StaticTokenAuthenticator` for `JwtAuthenticator`
(also in `nexus-actors/http-auth`) and verify against your IdP — the
middleware contract is identical.

### Production safety

These demo credentials — plus the demo Postgres password (`wallet`) — are
fine for `WALLET_ENV=dev` (the default) but must never ship as-is. Set
`WALLET_ENV=production` and the app **fails closed at boot**
(`DemoDefaultsInProductionException`) if the DB password, `WALLET_AUTH_TOKENS`,
or `WALLET_ADMIN_TOKENS` still hold a built-in demo value. The Postgres port
is bound to `127.0.0.1` in `compose.yaml` so the database is not reachable
off-host.

## File layout

```
examples/nexus-wallet-app/
├── composer.json                  # depends on nexus-actors/* packages
├── Dockerfile                     # PHP 8.5 ZTS + Swoole 6.2.1 thread-mode
├── compose.yaml                   # app service + optional perf client
├── Makefile                       # up/down/test/perf
├── .github/workflows/ci.yml       # build → test → smoke deposit→balance
├── public/server.php              # Swoole-Threads entry point
├── src/
│   ├── Actor/
│   │   ├── WalletDirectoryActor.php  # long-lived router (1 per thread)
│   │   ├── WalletActor.php           # event-sourced (1 per owner)
│   │   ├── LedgerActor.php           # EntityBehavior actor (1 per owner)
│   │   ├── WalletRegistry.php        # directory's owner → child-ref state
│   │   ├── EnsureWallet.php          # message sent to directory
│   │   └── WalletRef.php             # directory reply
│   ├── Boot/                         # WalletApp factory, config, Doctrine kit
│   ├── Domain/
│   │   ├── Money.php                 # value object (cent-precision)
│   │   ├── Command/                  # Deposit, Withdraw, GetBalance, RecordLedger
│   │   ├── Entity/                   # WalletLedger, LedgerEntry (Doctrine ORM)
│   │   ├── Event/                    # WalletOpened, MoneyDeposited, MoneyWithdrawn
│   │   ├── State/WalletState.php     # immutable, folded from events
│   │   └── Reply/                    # BalanceSnapshot, DepositResult, WithdrawResult
│   └── Http/
│       ├── Handler/                  # Balance/Deposit/Withdraw + ledger + admin
│       ├── Request/                  # AmountRequest, LedgerRecordRequest DTOs
│       ├── Response/                 # typed JSON response DTOs
│       ├── Auth/DemoUsers.php        # bearer-token map
│       └── WalletRoutes.php          # route table
├── tests/Unit/WalletStateTest.php   # state-fold invariants
└── perf/deposit-load.php            # Swoole-coroutine load tester
```

## Swapping to a real database

In `src/Boot/WalletApp.php` (`registerActors()`), replace:

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
