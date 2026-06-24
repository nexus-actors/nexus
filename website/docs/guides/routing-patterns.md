---
title: How to route messages with a hash ring
related:
  - scaling/overview
  - reference/classes/worker-node
  - guides/standalone-integration
  - best-practices/scaling
---

# How to route messages with a hash ring

When you run a worker pool, the same entity — an order, a user session, an account — must always reach the same worker thread to preserve the single-writer guarantee. `ConsistentHashRing` provides deterministic, coordination-free routing by name.

## Solution

<!-- verify:skip: requires a running WorkerPoolApp with multiple worker threads -->
```php title="src/Worker/OrderWorkerStart.php" verify:skip
<?php

declare(strict_types=1);

namespace App\Worker;

use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerStartHandler;

final class OrderWorkerStart implements WorkerStartHandler
{
    public function onWorkerStart(WorkerNode $node): void
    {
        $node->spawn(Props::fromBehavior(orderBehavior()), 'orders');
        $node->start();
    }
}
```

<!-- verify:skip: illustrates hash-ring lookup outside the worker-pool bootstrap -->
```php title="src/Router/EntityRouter.php" verify:skip
<?php

declare(strict_types=1);

namespace App\Router;

use Monadial\Nexus\WorkerPool\ConsistentHashRing;

final readonly class EntityRouter
{
    private ConsistentHashRing $ring;

    public function __construct(int $workerCount)
    {
        $this->ring = new ConsistentHashRing($workerCount);
    }

    public function workerFor(string $entityId): int
    {
        return $this->ring->getWorker($entityId);
    }
}
```

## How it works

`ConsistentHashRing` builds a virtual ring of `150 × workerCount` positions using CRC32. `getWorker($name)` hashes the name with CRC32 and binary-searches for the nearest position, returning the worker ID that owns it. Because the ring is constructed identically on every worker from the same `$workerCount`, all threads produce the same routing decision with no coordination.

`WorkerNode` uses this ring internally when you send a message to a `WorkerActorRef`. The node computes the target worker from the actor name, then delivers the envelope via `WorkerTransport` if the target is remote, or hands it to the local `ActorSystem` if the target is the current worker.

## Variations

### Pinning an entity to a worker

To guarantee that all messages for a given entity ID (order ID, user ID, etc.) reach the same actor, use the entity ID as the actor name:

<!-- verify:skip: illustrates actor naming convention for hash-ring affinity -->
```php title="src/Actor/OrderActorSpawner.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Props;

// Spawn with the entity ID as the name — the hash ring pins it to the same worker
$orderRef = $ctx->spawn(Props::fromBehavior(orderBehavior()), "order-{$orderId}");
```

The ring ensures `order-abc123` always resolves to the same worker as long as `$workerCount` does not change. Resharding requires draining active actors first.

### Manual ring inspection

During development you can query the ring directly to verify that a set of entity IDs distributes evenly across workers:

<!-- verify:skip: diagnostic utility code, not application code -->
```php title="bin/check-distribution.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\WorkerPool\ConsistentHashRing;

$ring = new ConsistentHashRing(workerCount: 4);
$buckets = array_fill(0, 4, 0);

foreach ($yourEntityIds as $id) {
    $buckets[$ring->getWorker($id)]++;
}

print_r($buckets); // expect roughly equal counts across workers
```

## Caveats

- **Changing `$workerCount` reshards all actors.** If you resize the pool while actors hold state, those actors will receive messages on the wrong worker. Drain the pool to zero active messages before resizing.
- **Actor names must be stable.** The ring routes by the actor name string. If the name changes between spawns (e.g., includes a timestamp), routing breaks.
- **`WorkerActorRef` bypasses the serializer.** Cross-worker messages are passed as PHP objects via Swoole `Thread\Queue`. Messages do not need `#[MessageType]` for intra-pool routing, only for cluster transport.
- **Virtual node count is fixed at 150.** This gives good distribution for up to ~50 workers. Beyond that, consider increasing `$virtualNodes` in the `ConsistentHashRing` constructor.

## Related

- [Worker node](../reference/classes/worker-node.md) — the per-worker coordinator that owns actor spawning and routing
- [Scaling overview](../scaling/overview.md) — worker pool topology and thread count guidance
- [Single-writer aggregates](./single-writer-aggregates.md) — why pinning an entity to one worker matters
