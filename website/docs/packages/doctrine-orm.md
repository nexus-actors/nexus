---
sidebar_position: 16
title: nexus-doctrine-orm
---

# nexus-doctrine-orm

Pooled `EntityManagerInterface` plus an `EntityBehavior` DSL for treating
Doctrine entities as the state of aggregate actors. Built on top of
`nexus-doctrine-dbal`.

**Composer:** `nexus-actors/doctrine-orm`

**Namespace:** `Monadial\Nexus\Doctrine\Orm\`

**Dependencies:** `doctrine/orm ^3.0`, `doctrine/dbal ^4.0`,
`nexus-actors/doctrine-dbal`, `nexus-actors/core`, `nexus-actors/http`,
`nexus-actors/runtime`, PSR-3, PSR-7, PSR-14, PSR-15, PSR-17.

This package is **separate** from `nexus-persistence-doctrine` (the
event-sourcing / durable-state ORM stores). You can use both.

## At a glance

- `EntityManagerPool` — pooled EMs, each owning a dedicated connection
- `PooledEntityManager` — `EntityManagerDecorator` subclass with borrow counter
- `EntityManagerScopeMiddleware` + `EntityManagerResolver` — HTTP injection
- `TransactionalEmDecorator` — ORM-path `#[Transactional]`
- `EntityBehavior` DSL — entity-as-aggregate-actor
- `EntityRefFactory` — single-writer per `(entityClass, id)`
- `EntityEffect`, `EntityReplayPolicy`, `EntityConflictException`

See:
- [Doctrine / EntityManager Pool](../doctrine/entity-manager-pool.md)
- [Doctrine / HTTP Integration](../doctrine/http-integration.md)
- [Doctrine / EntityBehavior DSL](../doctrine/entity-behavior.md)

## Classes

### Pool

| Class | Description |
|---|---|
| `Pool\EntityManagerPool` | The pool. `take()`, `release()`, `withEntityManager()`, `close()`, `stats()`. |
| `Pool\PooledEntityManager` | Extends `Doctrine\ORM\Decorator\EntityManagerDecorator`. Adds `markBorrowed()`, `borrowCount()`. |
| `Pool\EmPoolConfig` | `max`, `minIdle`, `borrowTimeout`, `clearOnReturn`, `recreateAfter`. |
| `Pool\EmPoolStats` | `idle`, `inUse`, `total`, `totalBorrows`, `totalEvictions`. |
| `Pool\EntityManagerFactory` | Interface: `create(Connection): EntityManagerInterface`. |
| `Pool\DefaultEntityManagerFactory` | Default impl: `new EntityManager($conn, $config)`. |
| `DoctrineEmPool` | Static facade `forConfig(name, connParams, ormSetup, config?, events?, logger?)`. |

### HTTP integration

| Class | Description |
|---|---|
| `Http\EntityManagerLease` | `get(): EntityManagerInterface`, `release()`. |
| `Http\EntityManagerScopeMiddleware` | PSR-15. Attaches lease; releases in `finally`. |
| `Http\EntityManagerResolver` | `ParamResolver` matching `Doctrine\ORM\EntityManagerInterface`. |
| `Http\TransactionalEmDecorator` | Wraps handler in `$em->wrapInTransaction(...)`. Reuses Plan 1's `#[Transactional]` attribute. |
| `Http\DoctrineOrmHttp` | Static facade `installOrm(registry, &middlewares, emPool)`. |

### Actor binding

| Class | Description |
|---|---|
| `Actor\OrmActorPoolBinding` | Composes `ActorPoolBinding` (DBAL) with `EntityManagerPool`. `$base->connPool` + `$emPool`. |

### EntityBehavior DSL

| Class | Description |
|---|---|
| `Behavior\EntityBehavior` | Static factory: `create($entityClass, $id, $commandHandler)` returns a builder. |
| `Behavior\EntityBehaviorBuilder` | Fluent: `withEntityManagerFactory`, `withReplayPolicy`, `withLockMode`, `withConnectionSource`, `withReceiveTimeout`, `withDirectConnection`, `toBehavior()`. |
| `Behavior\EntityBehaviorRunner` | Internal — wires actor lifecycle to entity persistence. Called from `toBehavior()`. |
| `Behavior\EntityEffect` | `same()`, `persist()`, `remove()`, `stop()`, `stash()`, `reply()`, `thenRun()`, `thenReply()`. |
| `Behavior\EntityEffectKind` | Enum: `Same`, `Persist`, `Remove`, `Stop`, `Stash`. |
| `Behavior\EntityRefFactory` | `for($spawner, $entityClass)` returns a builder; `of($id): ActorRef` spawns once and caches. |
| `Behavior\EntityRefFactoryBuilder` | Fluent: `using`, `withConnectionSource`, `withReplayPolicy`, `withReceiveTimeout`, `handle`, `build`. |
| `Behavior\ActorSpawner` | Interface: `spawn(Props, string $name): ActorRef`. |
| `Behavior\ActorSystemSpawner` | `final readonly` adapter wrapping `ActorSystem` as `ActorSpawner`. |

### Replay policies

| Class | Behavior |
|---|---|
| `Behavior\ReplayPolicy\EntityReplayPolicy` | Interface: `resolve($em, $entityClass, $id): ?object`. |
| `Behavior\ReplayPolicy\FailIfMissing` | `find()` → throws `EntityNotFoundException` if absent. |
| `Behavior\ReplayPolicy\CreateIfMissing(Closure $factory)` | `find()` → factory + `persist()` on miss. |
| `Behavior\ReplayPolicy\OnDemand` | Returns `null`. Runner uses `$em->find()` directly on first command. |

### Events + exceptions

| Class | Notes |
|---|---|
| `Event\EntityManagerCreated(poolName)` | |
| `Event\EntityManagerCleared(poolName)` | Fires on `clearOnReturn` lend. |
| `Event\EntityManagerEvicted(poolName, reason)` | Reasons: `closed-pool`, `em-closed`, `recreate-after`, `channel-full`. |
| `Exception\MissingEntityManagerScopeException` | Resolver when middleware not installed. |
| `Exception\EntityConflictException(entityClass, id, OptimisticLockException $previous)` | Wraps Doctrine optimistic-lock failures. |

## Wiring example

```php
use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Http\DoctrineOrmHttp;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;

$ormConfig = ORMSetup::createAttributeMetadataConfig(paths: [__DIR__ . '/src/Entity']);
$ormConfig->enableNativeLazyObjects(true);

$emPool = DoctrineEmPool::forConfig(
    name: 'orders',
    connParams: ['driver' => 'pdo_mysql', 'url' => $_ENV['DATABASE_URL']],
    ormSetup: $ormConfig,
    config: new EmPoolConfig(max: 16, minIdle: 2),
);

$registry = DoctrineOrmHttp::installOrm(
    registry: $registry,        // from DoctrineHttp::install
    middlewares: $middlewares,
    emPool: $emPool,
);
```

Handler:

```php
final class CreateOrder
{
    public function __invoke(
        ServerRequestInterface $req,
        EntityManagerInterface $em,
    ): ResponseInterface {
        $order = new Order(/* … */);
        $em->persist($order);
        $em->flush();

        return new JsonResponse(['id' => $order->getId()]);
    }
}
```

EntityBehavior:

```php
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Doctrine\Orm\Behavior\ActorSystemSpawner;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;

$factory = EntityRefFactory::for(new ActorSystemSpawner($system), Order::class)
    ->using(new DefaultEntityManagerFactory($ormConfig))
    ->withConnectionSource(fn() => DriverManager::getConnection($connParams))
    ->withReplayPolicy(new CreateIfMissing(fn($id) => new Order($id)))
    ->handle(fn($ctx, object $cmd, Order $o): EntityEffect => /* … */)
    ->build();

$factory->of($orderId)->tell(new AddLineItem(...));
```
