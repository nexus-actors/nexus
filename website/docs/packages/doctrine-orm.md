---
sidebar_position: 16
title: nexus-doctrine-orm
related:
  - packages/doctrine-dbal
  - doctrine/entity-behavior
  - doctrine/entity-manager-pool
  - doctrine/http-integration
---

# nexus-doctrine-orm

Pooled `EntityManagerInterface` and an `EntityBehavior` DSL for treating Doctrine entities as aggregate actor state.

## What's in this package

- `EntityManagerPool` — pooled entity managers, each owning a dedicated DBAL connection
- `EntityManagerScopeMiddleware` + `EntityManagerResolver` — inject `EntityManagerInterface` into HTTP handlers
- `TransactionalEmDecorator` — ORM-path auto-commit/rollback using `#[Transactional]`
- `EntityBehavior` + `EntityRefFactory` — DSL for entity-as-aggregate actors
- `EntityEffect` — `same`, `persist`, `remove`, `stop`, `stash`, `reply`, `thenRun`, `thenReply`
- `EntityReplayPolicy` — `FailIfMissing`, `CreateIfMissing`, `OnDemand`

## Install

```bash
composer require nexus-actors/doctrine-orm
```

## Quick example

```php title="src/Actor/OrderActorFactory.php"
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\ActorSystemSpawner;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;

$factory = EntityRefFactory::for(new ActorSystemSpawner($system), Order::class)
    ->using(new DefaultEntityManagerFactory($ormConfig))
    ->withConnectionSource(fn() => DriverManager::getConnection($connParams))
    ->withReplayPolicy(new CreateIfMissing(fn($id) => new Order($id)))
    ->handle(fn($ctx, object $cmd, Order $o): EntityEffect => EntityEffect::persist())
    ->build();

$factory->of($orderId)->tell(new AddLineItem($sku, $qty));
```

Each `$orderId` maps to exactly one actor; `EntityRefFactory` spawns on first access and caches the reference. This package is separate from `nexus-persistence-doctrine` (event-sourcing/durable-state ORM stores).

## See also

- [Doctrine / EntityBehavior DSL](../doctrine/entity-behavior.md)
- [Doctrine / EntityManager pool](../doctrine/entity-manager-pool.md)
- [nexus-doctrine-dbal](./doctrine-dbal.md) — DBAL pool this package builds on
