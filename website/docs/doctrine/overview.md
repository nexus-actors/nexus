---
sidebar_position: 1
title: Doctrine Overview
---

# Doctrine Overview

Nexus ships first-class, coroutine-aware Doctrine DBAL and ORM integration:
per-thread connection pools, request-scoped HTTP injection, and an
`EntityBehavior` DSL that turns a Doctrine entity into the state of a
non-event-sourced aggregate actor.

If you've used Doctrine in Symfony or Laravel before, the surface is the
same. What's different: the pool is cooperative under Swoole, the same
code runs on Fiber for dev/tests, and the `EntityManagerInterface` you
inject into a handler is a real Doctrine EM borrowed from a pool — not a
container singleton.

## The Stack

Two new packages, both layered on top of the existing HTTP and actor
primitives.

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

These are **separate** from `nexus-persistence-dbal` and
`nexus-persistence-doctrine` (the event-sourcing and durable-state stores).
You can use both — they coexist cleanly.

## Three layers

### Connection pool (DBAL)

The primitive everything else borrows through. Configure once, share across
HTTP handlers and actors. Under Swoole, `take()` suspends the coroutine
when the pool is empty until a connection is released or the borrow times
out. Under Fiber, it's a simpler non-blocking pool — PDO blocks the fiber
anyway, so coroutine semantics buy you nothing.

```php
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

See [Connection Pool](./connection-pool.md) for the full configuration
surface.

### HTTP integration

Per-request leases attached to the `ServerRequest`. Handlers declare a
`Connection` or `EntityManagerInterface` parameter and the framework
borrows on first use, releases on response.

```php
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

`#[Transactional]` opt-in wraps the handler in a DBAL transaction (or
`$em->wrapInTransaction(...)` for the ORM path). See
[HTTP Integration](./http-integration.md).

### EntityBehavior DSL

Treats a Doctrine entity as the state of an aggregate actor — no event
sourcing required.

```php
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

The actor loads the entity on start (configurable via `EntityReplayPolicy`),
processes commands, and persists when the handler returns
`EntityEffect::persist()`. Each actor owns a dedicated EM for its lifetime
— not borrowed from any pool. `EntityRefFactory` enforces single-writer
per `(entityClass, id)`.

See [EntityBehavior DSL](./entity-behavior.md).

## Runtime model

| Runtime | Behavior |
|---|---|
| **`SwooleRuntime`** (production) | `SWOOLE_HOOK_ALL` enabled via `DoctrineBootstrap::enable()`. Stock Doctrine PDO drivers become cooperatively async — every blocking I/O yields the coroutine. No custom drivers. |
| **`nexus-worker-pool-swoole` threads** | Each worker thread owns its own pools. Total connections = `threads × pool.max`. Connections never cross thread boundaries (PDO resources aren't ZTS-shareable). |
| **`FiberRuntime`** (dev/tests) | Stock blocking PDO. Pool API identical; `take()` blocks the fiber instead of yielding. Documented limitation. |

## When to use which

- **`Connection` injection** — when you want raw SQL with full control. Lightest possible overhead. Pair with `#[Transactional]` if needed.
- **`EntityManagerInterface` injection** — when you want UoW + repositories + the full ORM ergonomics inside HTTP handlers. The EM is from a pool so it's cheap to borrow and return.
- **`EntityBehavior`** — when an entity is naturally an aggregate with invariants (Order, BankAccount, Cart). The single-writer guarantee comes from the actor system; the dedicated-EM model means the UoW is always consistent.

## Performance

Take/release throughput measured at ~1–2 µs per round-trip under Swoole
with `pdo_sqlite` (no contention). Real production hardware with `pdo_mysql`
and `SWOOLE_HOOK_ALL` will be dominated by the actual SQL roundtrip, not
the pool primitive.

## Caveats

- **`OnDemand` replay policy** loads the entity from the DB on first
  command, not on actor start. The DB row must exist by then (inserted by
  some other process) — otherwise the actor raises a runtime error on its
  first command.
- **Dedicated-EM-per-actor cost**: each long-lived `EntityBehavior` actor
  pins one connection for its whole lifetime. An app with 10k hot entity
  actors needs ≥10k connections. Mitigate by passivating idle entities
  (`onSignal(ReceiveTimeout) → Behavior::stopped()`) and bounding active
  count via a router.
- **Single-writer per `(class, id)`** is enforced at the actor-name level
  (`Order--42`). Two `EntityRefFactory::of(42)` calls return the same
  ref; two different factories pointing at the same system would collide
  via `ActorNameExistsException`.
