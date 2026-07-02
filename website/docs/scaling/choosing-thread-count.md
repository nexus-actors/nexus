---
title: Choosing a thread count
related:
  - scaling/overview
  - scaling/bootstrap
  - scaling/cross-worker-ask
  - persistence/single-writer
---

# Choosing a thread count

`WorkerPoolConfig::withThreads(N)` sets the number of Swoole worker threads in the pool. The right N depends on whether your workload is CPU-bound or I/O-bound.

## The formula

| Workload type | Starting formula | Why |
|---|---|---|
| CPU-bound | N = CPU core count | One thread per core saturates compute without context-switching overhead |
| Mixed (I/O + compute) | N = CPU cores × 2 | I/O waits free cores for other threads |
| I/O-heavy (DB, HTTP, network) | N = CPU cores × 2 to × 4 | Most threads are waiting; more threads keep cores busy |

These are starting points. Measure throughput and latency under your actual load and tune from there.

## Configuring the pool

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

// Boot with 8 threads on a 4-core host processing DB-heavy workloads
OrdersWorkerPoolApp::run(WorkerPoolConfig::withThreads(8));
```

## Capacity planning examples

**4-core host, I/O-heavy (DBAL + HTTP calls):**

```
threads = 4 × 2 = 8
```

Each actor processes one message at a time. With 8 threads and a p99 message latency of 5 ms (mostly database wait), the pool can sustain roughly 8 ÷ 0.005 = 1,600 messages/second at full utilisation — before factoring in cross-worker routing overhead.

**8-core host, CPU-heavy (serialization, cryptography):**

```
threads = 8 × 1 = 8
```

Adding more threads beyond core count increases context-switching and Swoole channel contention without improving throughput.

**16-core host, mixed workload:**

```
threads = 16 × 2 = 32  (starting point — benchmark to confirm)
```

## Actor placement and single-writer semantics

The consistent hash ring maps each actor name to a deterministic thread. An actor named `"orders"` always lands on the same thread. This means:

- Each entity is handled by exactly one thread — the single-writer guarantee is preserved without coordination.
- Uneven actor-name distributions can concentrate load on fewer threads. If all your orders share the same actor name prefix, consider including the entity ID in the actor name (`"order-42"`) so they hash across threads.

```php title="src/App/OrdersWorkerStartHandler.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerStartHandler;

final class OrdersWorkerStartHandler implements WorkerStartHandler
{
    public function onWorkerStart(WorkerNode $node): void
    {
        // Each unique order ID hashes to a different thread
        foreach ($this->orderIds as $id) {
            $node->spawn(buildOrderBehavior($id), "order-{$id}");
        }

        $node->start();
    }
}
```

## Memory limits

Each thread runs an independent PHP process image. Total memory usage scales with thread count. Monitor per-thread RSS and set `memory_limit` in `php.ini` to cap individual thread consumption.

:::note ZTS PHP required
Swoole thread-mode requires PHP built with Zend Thread Safety (ZTS). See the [ZTS PHP setup guide](../operations/overview.md) for Docker-based installation.
:::

## See also

- [Scaling overview](./overview.md) — topology and consistent hash ring explanation.
- [Cross-worker ask](./cross-worker-ask.md) — latency implications of routing across threads.
- [Single-writer guarantee](../persistence/single-writer.md) — how per-thread actor placement maintains single-writer semantics.
