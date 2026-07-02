---
title: Worker pool configuration
related:
  - scaling/overview
  - scaling/bootstrap
  - packages/worker-pool
  - packages/worker-pool-swoole
---

# Worker pool configuration

`WorkerPoolConfig` is the single configuration object for a worker pool: thread count is the only required parameter.

## What it does

`WorkerPoolConfig` is a `readonly` value object. It carries the worker count to `WorkerPoolBootstrap`, which uses it to size the `Swoole\Thread\Pool` and allocate one `Swoole\Thread\Queue` inbox per worker. Everything else — actor placement, transport, directory — is derived automatically from that count.

## Example

```php title="src/App/bootstrap.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\WorkerPool\WorkerPoolConfig;

$config = WorkerPoolConfig::withThreads(swoole_cpu_num());
```

## Parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `workerCount` | `int` | — | Number of worker threads to spawn. Must be ≥ 1. |

`swoole_cpu_num()` returns the number of logical CPU cores available to the process. Use it for CPU-bound workloads. For I/O-bound workloads where handlers spend most of their time waiting on network or disk, a higher multiplier (e.g. `swoole_cpu_num() * 2`) may improve throughput at the cost of higher memory usage.

## Actor placement

`ConsistentHashRing` assigns actor names to workers deterministically:

```php title="src/App/placement-check.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\WorkerPool\ConsistentHashRing;

$ring = new ConsistentHashRing(workerCount: 4);
$workerId = $ring->getWorker('orders');  // same result on every worker, every run
```

Placement properties:

- **Algorithm** — CRC32 over the actor name, mapped onto 150 virtual nodes per worker.
- **Distribution** — Statistically uniform across workers with 150 virtual nodes per slot.
- **Stability** — Adding workers changes the assignment for approximately 1/N existing actors (consistent hashing guarantee). Actors already registered keep their worker until the process restarts.

A given actor name always maps to the same worker within one pool run. Workers that call `spawn()` with a name that hashes to another worker receive a `WorkerActorRef` pointing to that worker — no manual routing is needed.

## WorkerNode

Each worker thread has its own `WorkerNode`. `WorkerRunnable` creates it automatically during thread startup. It is passed to your `configure()` method or `WorkerStartHandler`:

```php title="src/App/MyApp.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolApp;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\Behavior;

final class MyApp extends WorkerPoolApp
{
    protected function configure(WorkerNode $node): void
    {
        $node->spawn(Props::fromBehavior($ordersBehavior), 'orders');
        $node->spawn(Props::fromBehavior($paymentsBehavior), 'payments');
    }
}

MyApp::run(WorkerPoolConfig::withThreads(swoole_cpu_num()));
```

`spawn()` returns an `ActorRef<T>`. If the hash ring assigns the name to another worker, the ref is a `WorkerActorRef` that routes through `ThreadQueueTransport`. If the name belongs to this worker, it is a `LocalActorRef`.

## See also

- [Scaling overview](./overview.md) — architecture, message flow, and location transparency
- [Bootstrap](./bootstrap.md) — `WorkerPoolApp`, `WorkerPoolBootstrap`, and startup patterns
- [Worker Pool package](../packages/worker-pool.md) — `ConsistentHashRing` and `WorkerDirectory` API
