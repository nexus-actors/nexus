---
title: Doctrine Migrations with actor-managed entities
related:
  - doctrine/entity-behavior
  - doctrine/fixtures
  - persistence/single-writer
  - scaling/overview
---

# Doctrine Migrations with actor-managed entities

Running schema migrations while `EntityBehavior` actors are live risks reading stale data or applying a migration that conflicts with an in-flight transaction. This page documents the safe migration workflow.

## The drain pattern

Stop the actor system before migrating, run the migration, then restart. `ActorSystem::shutdown()` is deadline-driven: it sends `PoisonPill` to all root actors, waits for them to stop, and unblocks when done or when the deadline expires.

```php title="bin/migrate.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

// 1. Acquire a handle to the running system (e.g. via a shared container)
/** @var ActorSystem $system */

// 2. Drain — waits up to 30 s for all actors to finish in-flight work
$system->shutdown(Duration::seconds(30));

// 3. Run the Doctrine Migration
shell_exec('php vendor/bin/doctrine-migrations migrations:migrate --no-interaction');

// 4. Restart the system in your process manager (supervisord, systemd, k8s)
```

:::caution Do not migrate while actors are running
`EntityBehavior` acquires a database connection per entity and may hold open transactions during command processing. Migrating while actors are live risks deadlocks and partial-state reads.
:::

## Coordinating with `EntityBehavior` single-writer semantics

`EntityBehavior` enforces single-writer semantics via the `EntityRefFactory` cache: within one `ActorSystem`, at most one actor ref exists per entity identity. After a restart the cache is empty, and the first `of()` call spawns a fresh actor that loads the post-migration entity state.

This means migration-induced schema changes are safe as long as:

1. All `EntityBehavior` actors have stopped before the migration runs.
2. The migration is applied atomically (Doctrine Migrations handles this via transactions).
3. The actor system restarts after the migration completes.

## Rolling schema changes in a worker pool

In a worker pool with multiple `WorkerNode` threads, all threads share the same database but each runs its own `ActorSystem`. Drain all workers before migrating by coordinating shutdown from the `WorkerPoolApp`:

```php title="src/App/OrdersWorkerPoolApp.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolApp;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;

final class OrdersWorkerPoolApp extends WorkerPoolApp
{
    protected function configure(WorkerNode $node): void
    {
        $node->spawn(buildOrderBehavior($node), 'orders');
        $node->start();
    }
}

// Trigger graceful shutdown from outside — e.g. a SIGTERM handler
// Each worker's ActorSystem shuts down within its $gracePeriod
```

`WorkerPoolApp::shutdown()` is called automatically on SIGTERM in the Swoole thread pool, giving each worker up to the configured grace period to drain.

## Additive-first migration strategy

Prefer additive migrations (add columns, add tables) over destructive ones (rename columns, drop tables). Additive changes are safe to apply while actors are running if the application code is written to handle both old and new schema — the actor ignores the new column until redeployed. Destructive changes always require the drain pattern.

## See also

- [Entity behavior](./entity-behavior.md) — how `EntityBehavior` manages entity lifecycle.
- [Single-writer guarantee](../persistence/single-writer.md) — writer identity and conflict detection.
- [Graceful shutdown](../operations/overview.md) — `ActorSystem::shutdown()` deadline mechanics.
