---
title: nexus-observability-doctrine
related:
  - observability/tracing
  - observability/metrics
  - packages/observability
  - packages/doctrine-dbal
  - packages/doctrine-orm
---

# nexus-observability-doctrine

Doctrine observability — PSR-14 listeners for DBAL connection-pool and ORM EntityManager-pool metrics, plus a DBAL driver middleware that opens a Client span per SQL query.

## Install

```bash title="terminal"
composer require nexus-actors/observability-doctrine
```

## What's in this package

| Class | Purpose |
|---|---|
| `DbalPoolMetricsListener` | PSR-14 listener for DBAL pool events: counters for connections created/taken/released/destroyed/poisoned and a pool-exhausted counter; histogram for acquire wait time |
| `OrmPoolMetricsListener` | PSR-14 listener for ORM pool events: counters for EntityManagers created/cleared/evicted |
| `TracingDriverMiddleware` | Doctrine DBAL `Middleware`: wraps the driver with SQL query tracing — opens a Client span per statement with the parameterized query text (never bound values) |

## Quick example

```php title="src/Doctrine/DoctrineSetup.php" verify:lint-only
use Doctrine\DBAL\Configuration;
use Monadial\Nexus\Observability\Doctrine\DbalPoolMetricsListener;
use Monadial\Nexus\Observability\Doctrine\OrmPoolMetricsListener;
use Monadial\Nexus\Observability\Doctrine\Sql\TracingDriverMiddleware;

// SQL tracing via DBAL middleware
$config = new Configuration();
$config->setMiddlewares([new TracingDriverMiddleware($obs)]);

// Pool metrics via PSR-14 event dispatcher
$listener = new DbalPoolMetricsListener($obs);
$dispatcher->addListener(\Monadial\Nexus\Doctrine\Dbal\Event\ConnectionTaken::class, [$listener, 'onConnectionTaken']);
$dispatcher->addListener(\Monadial\Nexus\Doctrine\Dbal\Event\ConnectionReleased::class, [$listener, 'onConnectionReleased']);

$ormListener = new OrmPoolMetricsListener($obs);
$dispatcher->addListener(\Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCreated::class, [$ormListener, 'onEntityManagerCreated']);
```

## Emitted metrics

| Metric | Type | Dimension |
|---|---|---|
| `nexus.dbal.pool.connections.created` | Counter | `pool.name` |
| `nexus.dbal.pool.connections.taken` | Counter | `pool.name` |
| `nexus.dbal.pool.connections.released` | Counter | `pool.name` |
| `nexus.dbal.pool.connections.destroyed` | Counter | `pool.name` |
| `nexus.dbal.pool.connections.poisoned` | Counter | `pool.name` |
| `nexus.dbal.pool.exhausted` | Counter | `pool.name` |
| `nexus.dbal.pool.acquire.wait` | Histogram (s) | `pool.name` |
| `nexus.orm.pool.entity_managers.created` | Counter | `pool.name` |
| `nexus.orm.pool.entity_managers.cleared` | Counter | `pool.name` |
| `nexus.orm.pool.entity_managers.evicted` | Counter | `pool.name` |

## See also

- [Observability metrics guide](../observability/metrics.md) — metric names and collection
- [nexus-observability](./observability.md) — vendor-neutral contracts
- [nexus-doctrine-dbal package](./doctrine-dbal.md) — DBAL connection pool
- [nexus-doctrine-orm package](./doctrine-orm.md) — ORM EntityManager pool
