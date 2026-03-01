# Configuration

Worker pool configuration is minimal — thread count is the only required parameter.

## Prerequisites

- ZTS PHP 8.5+
- Swoole 6.0+ with `--enable-swoole-thread` (`SWOOLE_THREAD` mode)

## WorkerPoolConfig

```php
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;

$config = WorkerPoolConfig::withThreads(8);
```

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `workerCount` | `int` | yes | Number of worker threads. Use `swoole_cpu_num()` for CPU-bound workloads. |

`WorkerPoolConfig` is immutable and `readonly`.

## Actor placement

The consistent hash ring assigns actor names to workers deterministically:

```php
use Monadial\Nexus\WorkerPool\ConsistentHashRing;

$ring = new ConsistentHashRing(workerCount: 4);
$workerId = $ring->getWorker('orders');  // same result on every worker, every run
```

- Algorithm: CRC32 over the actor name, mapped to 150 virtual nodes per worker.
- Distribution: statistically uniform with 150 virtual nodes.
- Stability: adding workers changes assignment for ~1/N actors (consistent hashing guarantee).

## WorkerNode

Each worker thread has its own `WorkerNode`. You normally don't instantiate it directly —
`WorkerRunnable` creates it automatically. It is passed to your `WorkerStartHandler`:

```php
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerStartHandler;

final class MyApp extends WorkerPoolApp
{
    protected function configure(WorkerNode $node): void
    {
        // spawn actors — WorkerNode routes local vs remote automatically
        $node->spawn(Props::fromBehavior($ordersBehavior), 'orders');
        $node->spawn(Props::fromBehavior($paymentsBehavior), 'payments');
    }
}
```

`spawn()` returns an `ActorRef<T>`. If the hash ring assigns the name to another worker,
the ref is a `WorkerActorRef` that routes through `ThreadQueueTransport`. If it belongs
to this worker, it is a `LocalActorRef`.
