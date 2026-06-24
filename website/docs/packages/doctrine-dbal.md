---
sidebar_position: 15
title: nexus-doctrine-dbal
related:
  - packages/doctrine-orm
  - doctrine/overview
  - doctrine/connection-pool
  - packages/http
---

# nexus-doctrine-dbal

Coroutine-aware Doctrine DBAL connection pool with HTTP middleware integration and actor-side binding.

## What's in this package

- `ConnectionPool` — per-thread, lazy pool with cooperative wait under Swoole
- `SwooleChannel` / `FiberChannel` — runtime-appropriate channel backends
- `ConnectionScopeMiddleware` + `ConnectionResolver` — inject `Connection` into HTTP handlers via PSR-15
- `#[Transactional]` + `TransactionalDecorator` — auto commit/rollback around handlers
- `DoctrineBootstrap::enable()` — sets `SWOOLE_HOOK_ALL` when Swoole is loaded
- `ActorPoolBinding` — carry a pool reference into actor factories
- `PoolExhaustedToServiceUnavailable` — maps `PoolExhaustedException` to 503

## Install

```bash
composer require nexus-actors/doctrine-dbal
```

## Quick example

```php title="src/Bootstrap/WorkerSetup.php"
use Monadial\Nexus\Doctrine\Dbal\Bootstrap\DoctrineBootstrap;
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Http\DoctrineHttp;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;

DoctrineBootstrap::enable();

$pool = DoctrinePool::fromParams(
    name: 'orders',
    connParams: ['driver' => 'pdo_mysql', 'url' => $_ENV['DATABASE_URL']],
    config: new PoolConfig(max: 16, minIdle: 2),
);

$registry = DoctrineHttp::install(
    registry: new ParamResolverRegistry(),
    middlewares: $middlewares,
    connPool: $pool,
);
```

After `DoctrineHttp::install()`, handler `__invoke()` methods may declare a `Connection` parameter and receive the per-request lease automatically.

This package is separate from `nexus-persistence-dbal` (the event-sourcing/durable-state stores). Both can coexist.

## See also

- [Doctrine / overview](../doctrine/overview.md)
- [Doctrine / connection pool](../doctrine/connection-pool.md)
- [nexus-doctrine-orm](./doctrine-orm.md) — ORM layer on top of this package
