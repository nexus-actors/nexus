---
title: Doctrine second-level cache with EntityBehavior
related:
  - doctrine/entity-behavior
  - doctrine/fixtures
  - doctrine/migrations
  - core-concepts/passivation
---

# Doctrine second-level cache with EntityBehavior

Doctrine's second-level cache (2LC) is a cross-request entity cache backed by an adapter such as APCu, Redis, or Memcached. When `EntityBehavior` is the sole writer for an entity, the two caches — Doctrine 2LC and the `EntityRefFactory` in-process cache — can interact in unexpected ways.

## How the caches relate

`EntityRefFactory` enforces one actor ref per entity identity within a single `ActorSystem`. The actor holds the authoritative in-memory state of the entity. Doctrine's 2LC is a read-side cache — it caches entities loaded by the `EntityManager` to avoid repeated database reads.

When `EntityBehavior` processes a command, it loads the entity via the `EntityManager`, applies changes, and flushes. If Doctrine 2LC is enabled, the post-flush entity is written into the 2LC region. The next load from any `EntityManager` instance — including on other threads or other processes — reads the cached version.

## When to enable 2LC alongside `EntityBehavior`

Enable Doctrine 2LC for the entities managed by `EntityBehavior` when:

- The same entity is read frequently from outside the actor system (e.g. from HTTP handlers that bypass the actor for read-only queries).
- The 2LC adapter is shared across all processes and threads (Redis or Memcached, not APCu).
- You accept eventual consistency: a brief window exists between a flush and a 2LC update where stale data may be served to readers.

Do not enable per-process 2LC (APCu) in a worker pool — each worker has its own APCu memory, so cache invalidation by one worker is invisible to others.

## Invalidation patterns

`EntityBehavior` does not call `$entityManager->getCache()->evict()` automatically. After a command that modifies an entity, the flush writes through to the 2LC automatically if Doctrine's `CacheMode` is `READ_WRITE` or `NONSTRICT_READ_WRITE`. Explicit eviction is only needed for `READ_ONLY` caches (rarely used with mutable aggregates).

To evict explicitly after a high-priority write:

```php title="src/Aggregates/OrderActor.php"
<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Core\Actor\ActorContext;

// Inside an EntityBehavior command handler callback
$commandHandler = static function (
    ActorContext $ctx,
    object $cmd,
    Order $entity,
) use ($entityManager): EntityEffect {
    if ($cmd instanceof CancelOrder) {
        $entity->cancel();
        $entityManager->flush();
        // Evict from 2LC so other readers see the updated state immediately
        $entityManager->getCache()?->evictEntity(Order::class, $entity->getId());

        return EntityEffect::persist($entity);
    }

    return EntityEffect::none();
};
```

## Passivation and 2LC coherence

When `EntityBehavior` passivates (stops due to `ReceiveTimeout`), the in-process actor ref is removed from the `EntityRefFactory` cache. A subsequent `of()` call spawns a fresh actor that loads the entity from the database (or from 2LC if it is warm). This is safe as long as no other writer modified the entity between passivation and reactivation — which the single-writer guarantee enforces.

:::caution Multi-process deployments
In a multi-process deployment where each process has an `ActorSystem`, the single-writer guarantee is per-process. If two processes have actors for the same entity identity, the 2LC may serve stale data after one process modifies the entity. Route entity commands to a single designated process by entity ID to maintain single-writer semantics.
:::

## See also

- [Entity behavior](./entity-behavior.md) — `EntityRefFactory` cache and passivation.
- [Passivation](../core-concepts/passivation.md) — how actors stop when idle.
- [Single-writer guarantee](../persistence/single-writer.md) — one writer per entity identity.
