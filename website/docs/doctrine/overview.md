---
sidebar_position: 1
title: Doctrine Overview
related:
  - doctrine/connection-pool
  - doctrine/entity-manager-pool
  - doctrine/http-integration
  - doctrine/entity-behavior
---

# Doctrine Overview

Nexus ships coroutine-aware Doctrine DBAL and ORM integration: per-thread connection pools, request-scoped HTTP injection, and an `EntityBehavior` DSL that turns a Doctrine entity into the state of a non-event-sourced aggregate actor.

## Choosing a Doctrine integration

- **Use `Connection` injection (`#[FromService]`) when** you need raw SQL in an HTTP handler — pooled, request-scoped, lowest overhead.
- **Use `EntityManagerInterface` injection (`#[FromService]`) when** you have an existing ORM-driven repository pattern and want Unit-of-Work semantics inside HTTP handlers.
- **Use `EntityBehavior` when** you want single-writer semantics on a specific entity — race-free updates with optional passivation, no optimistic-lock retries.

## The stack

Two packages, layered on top of the existing HTTP and actor primitives:

```
┌──────────────────────────────────────────────────────────────┐
│  nexus-doctrine-dbal                                          │
│    ConnectionPool, Channel abstraction (SwooleChannel +       │
│    FiberChannel), DriverManagerConnectionFactory,             │
│    DoctrineBootstrap, HTTP middleware + ConnectionResolver,   │
│    #[Transactional] attribute + decorator, ActorPoolBinding.  │
└──────────────────────────────────────────────────────────────┘
                              ▲
                              │
┌──────────────────────────────────────────────────────────────┐
│  nexus-doctrine-orm                                           │
│    EntityManagerPool, PooledEntityManager decorator,          │
│    EntityManagerFactory, HTTP middleware +                    │
│    EntityManagerResolver, ORM-path #[Transactional],          │
│    OrmActorPoolBinding, EntityBehavior DSL +                  │
│    EntityRefFactory, EntityReplayPolicy, EntityEffect.        │
└──────────────────────────────────────────────────────────────┘
```

These are separate from `nexus-persistence-dbal` and `nexus-persistence-doctrine` (the event-sourcing and durable-state stores). Both can coexist in the same application.

## Connection pool (DBAL)

The primitive everything else borrows through. Configure once, share across HTTP handlers and actors. Under Swoole, `take()` suspends the coroutine when the pool is empty until a connection is released or the borrow times out. Under Fiber, it is a non-blocking pool — PDO blocks the fiber anyway, so coroutine semantics add nothing here.

```php title="src/Bootstrap/WorkerStart.php"
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;

$pool = DoctrinePool::fromParams(
    name: 'orders',
    connParams: ['driver' => 'pdo_mysql', 'url' => 'mysql://...'],
    config: new PoolConfig(max: 32, minIdle: 4),
);

$rows = $pool->withConnection(static fn($conn) =>
    $conn->fetchAllAssociative('SELECT * FROM orders WHERE customer_id = ?', [42]));
```

See [Connection Pool](./connection-pool.md) for the full configuration surface.

## HTTP integration

Per-request leases are attached to the `ServerRequest`. Handlers declare a `Connection` or `EntityManagerInterface` parameter and the framework borrows on first use and releases on response — no `try/finally` in handler code.

```php title="src/Http/Handler/CreateOrder.php"
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CreateOrder
{
    public function __invoke(
        ServerRequestInterface $req,
        EntityManagerInterface $em,
    ): ResponseInterface {
        $order = new Order(...);
        $em->persist($order);
        $em->flush();

        return new JsonResponse(['id' => $order->getId()]);
    }
}
```

`#[Transactional]` wraps the handler in a DBAL transaction or `$em->wrapInTransaction(...)` for the ORM path. See [HTTP Integration](./http-integration.md).

## EntityBehavior DSL

Treats a Doctrine entity as the state of an aggregate actor — no event sourcing required.

```php title="src/Actors/OrderBehavior.php"
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;

$behavior = EntityBehavior::create(
    entityClass: Order::class,
    id: $orderId,
    commandHandler: static fn($ctx, object $cmd, Order $order): EntityEffect =>
        match (true) {
            $cmd instanceof AddLineItem => $order->tryAdd($cmd->sku, $cmd->qty)
                ? EntityEffect::persist()
                : EntityEffect::reply($cmd->replyTo, new LineRejected()),
            $cmd instanceof Cancel       => EntityEffect::remove(),
            default                      => EntityEffect::same(),
        },
)
    ->withEntityManagerFactory($emFactory)
    ->withDirectConnection(['driver' => 'pdo_mysql', 'url' => '...'])
    ->toBehavior();
```

The actor loads the entity on start (configurable via `EntityReplayPolicy`), processes commands, and persists when the handler returns `EntityEffect::persist()`. Each actor owns a dedicated EM for its lifetime. `EntityRefFactory` enforces single-writer per `(entityClass, id)`.

See [EntityBehavior DSL](./entity-behavior.md).

## Runtime model

| Runtime | Behavior |
|---|---|
| `SwooleRuntime` (production) | `SWOOLE_HOOK_ALL` enabled via `DoctrineBootstrap::enable()`. Stock Doctrine PDO drivers become cooperatively async — every blocking I/O yields the coroutine. No custom drivers. |
| `nexus-worker-pool-swoole` threads | Each worker thread owns its own pools. Total connections = `threads × pool.max`. Connections never cross thread boundaries — PDO resources are not ZTS-shareable. |
| `FiberRuntime` (dev/tests) | Stock blocking PDO. Pool API is identical; `take()` blocks the fiber instead of yielding. |

## Caveats

- **`OnDemand` replay policy** loads the entity from the database on first command, not on actor start. The database row must exist by then — otherwise the actor raises a runtime error on its first command.
- **Dedicated-EM-per-actor cost**: each long-lived `EntityBehavior` actor pins one connection for its whole lifetime. An application with many hot entity actors needs matching connections. Mitigate by passivating idle entities and bounding the active count via a router.
- **Single-writer per `(class, id)`** is enforced at the actor-name level (`Order--42`). Two `EntityRefFactory::of(42)` calls on the same factory return the same ref. Two different factories pointing at the same system collide via `ActorNameExistsException`.

## See also

- [Connection Pool](./connection-pool.md) — full pool configuration and borrow semantics
- [EntityManager Pool](./entity-manager-pool.md) — ORM pool, `clearOnReturn`, and `recreateAfter`
- [HTTP Integration](./http-integration.md) — request-scoped leases and `#[Transactional]`
- [EntityBehavior DSL](./entity-behavior.md) — single-writer aggregate actors
