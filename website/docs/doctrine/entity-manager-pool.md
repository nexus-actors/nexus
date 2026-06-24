---
sidebar_position: 3
title: EntityManager Pool
related:
  - doctrine/overview
  - doctrine/connection-pool
  - doctrine/http-integration
  - doctrine/entity-behavior
---

# EntityManager Pool

`EntityManagerPool` is a sibling primitive to `ConnectionPool` — not a wrapper. Each pooled `EntityManagerInterface` owns its own DBAL connection for its lifetime in the pool. Pooling EMs reduces the metadata-setup cost (class metadata cache, proxy factory, hydrator wiring) that Doctrine ORM incurs on every EM construction.

## Quick start

```php title="src/Bootstrap/WorkerStart.php"
use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;

$ormConfig = ORMSetup::createAttributeMetadataConfig(paths: [__DIR__ . '/src/Entity']);
$ormConfig->enableNativeLazyObjects(true);  // PHP 8.4+ requirement

$pool = DoctrineEmPool::forConfig(
    name: 'orders',
    connParams: ['driver' => 'pdo_mysql', 'url' => 'mysql://...'],
    ormSetup: $ormConfig,
    config: new EmPoolConfig(max: 32, minIdle: 4),
);

$result = $pool->withEntityManager(static fn($em) =>
    $em->getRepository(Order::class)->findBy(['customerId' => 42]));
```

## Why sibling, not wrapper

An EntityManager is built around a single `Connection` at construction time, and the Unit of Work (identity map, scheduled inserts/updates) is tied to that EM. Swapping the connection under an EM is a bug. So the EM pool maintains its own internal `ConnectionPool` (one connection per pooled EM) — the same primitive class, but a private instance with its own budget.

This means:

- You can size the DBAL pool and the EM pool independently.
- A mixed workload (some pure-SQL handlers, some ORM handlers) can use both pools without one starving the other.
- The internal connection pool is invisible to callers — they only see the EM pool.

## `EmPoolConfig`

| Knob | Default | Purpose |
|---|---|---|
| `max` | `16` | Per-thread cap. |
| `minIdle` | `2` | Warm baseline. |
| `borrowTimeout` | `Duration::seconds(5)` | As `ConnectionPool`. |
| `clearOnReturn` | `true` | `EM::clear()` the UoW on return. Almost always what you want. |
| `recreateAfter` | `1000` borrows | Periodically replace EMs to bound proxy-class cache growth. |

`clearOnReturn` semantics: the borrower always receives a clean EM (no leaked entity references across borrows). The actual `clear()` happens on the lend-from-idle path, not on release — same observable behavior, slightly different timing.

## `PooledEntityManager`

The decorator returned by `take()`. Extends Doctrine's `EntityManagerDecorator` so it implements the full `EntityManagerInterface` by delegation — handlers see a normal EM.

Additions:

- `markBorrowed(): void` — internal bookkeeping
- `borrowCount(): int` — used by `recreateAfter` policy

Type-hint `EntityManagerInterface` everywhere in your code. You do not need to know it is a decorator unless you are writing the pool itself.

## Borrow semantics

### `take(?Duration $timeout = null): PooledEntityManager`

Returns an open EM. Resolution order:

1. Pop an existing EM from the idle channel — if `clearOnReturn` is set, `clear()` is called before returning so the borrower starts clean.
2. If `total < max`, lazily: `connPool->take()` + `factory->create($conn)`. The connection is held for the EM's lifetime in the pool.
3. Otherwise, suspend the coroutine on the channel until release or `borrowTimeout`.

Throws `PoolClosedException` after `close()`. Throws `PoolExhaustedException` on timeout.

### `release(PooledEntityManager $em): void`

Resolution order:

1. If the pool is closed → destroy.
2. If `!$em->isOpen()` (Doctrine closes the EM on flush failure) → destroy with reason `em-closed`.
3. If `recreateAfter > 0` and `$em->borrowCount() >= recreateAfter` → destroy with reason `recreate-after`.
4. Otherwise → push back to idle channel.

Destruction returns the underlying connection to the private connection pool and emits an `EntityManagerEvicted(reason)` event.

### `withEntityManager(Closure $fn): mixed`

```php title="src/Repository/OrderRepository.php"
$result = $pool->withEntityManager(static function ($em) {
    $entity = $em->find(Order::class, 42);
    $entity->markPaid();
    $em->flush();

    return $entity->getId();
});
```

The EM is always released, even if the closure throws.

## `EntityManagerFactory`

```php title="src/Doctrine/CustomEntityManagerFactory.php"
interface EntityManagerFactory
{
    public function create(Connection $connection): EntityManagerInterface;
}
```

The default `DefaultEntityManagerFactory(Configuration $config)` builds a fresh `EntityManager` per call, bound to the supplied connection. `DoctrineEmPool::forConfig()` wires it for you — you only construct one manually if you are writing tests or have unusual setup needs.

## Observability

### `stats(): EmPoolStats`

```php title="src/Http/Handler/HealthCheck.php"
$stats = $pool->stats();
// $stats->idle, ->inUse, ->total,
// $stats->totalBorrows, ->totalEvictions,
// $stats->waitingCoroutines, ->totalWaits, ->totalTimeouts
```

`waitingCoroutines`, `totalWaits`, and `totalTimeouts` are populated in the exhaustion-wait path. They let you distinguish "we are occasionally bursting past `max`" from "every other borrow is timing out — raise `max`".

### PSR-14 events

- `EntityManagerCreated(poolName)`
- `EntityManagerCleared(poolName)` — on `clearOnReturn` lend
- `EntityManagerEvicted(poolName, reason)` — `closed-pool`, `em-closed`, `recreate-after`, or `channel-full`

## When NOT to pool

`EntityBehavior` actors take a **dedicated, non-pooled** EM via `EntityManagerFactory` directly. Pooling for them would either pin a pool slot forever (defeating the pool) or require swapping EMs (which breaks UoW identity). See [EntityBehavior DSL](./entity-behavior.md) for the non-pooled path.

For HTTP handlers, pool everything. For scripts, scheduled jobs, and console commands, use `withEntityManager()` and let the pool handle lifecycle just like HTTP.

## Exceptions

| Exception | Source |
|---|---|
| `PoolExhaustedException` (from dbal) | `take()` timeout |
| `PoolClosedException` (from dbal) | post-close `take()` |
| `MissingEntityManagerScopeException` | resolver when middleware not installed |

## See also

- [Connection Pool](./connection-pool.md) — the underlying DBAL primitive
- [HTTP Integration](./http-integration.md) — request-scoped EM leases and `#[Transactional]`
- [EntityBehavior DSL](./entity-behavior.md) — dedicated (non-pooled) EM for aggregate actors
