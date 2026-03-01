# nexus-worker-pool-swoole

Swoole thread primitives for the worker pool: `Thread\Queue` transport,
`Thread\Map` directory, and `Thread\Pool` bootstrap.

## Prerequisites

- ZTS (Zend Thread Safety) PHP 8.5+
- Swoole 6.0+ compiled with `--enable-swoole-thread`

## Installation

```bash
composer require nexus-actors/worker-pool-swoole
```

## WorkerPoolApp (high-level entry point)

The recommended way to boot a worker pool. Mirror of `NexusApp` for multi-worker setups.

```php
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolApp;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;

final class MyApp extends WorkerPoolApp
{
    protected function configure(WorkerNode $node): void
    {
        $node->spawn(Props::fromBehavior($ordersBehavior), 'orders');
        $node->spawn(Props::fromFactory(fn() => new PaymentActor()), 'payments');
    }
}

MyApp::run(WorkerPoolConfig::withThreads(swoole_cpu_num()));
```

`configure()` runs once per thread. Closures are safe — the class is re-instantiated in
each thread; nothing crosses a thread boundary.

## WorkerPoolBootstrap (lower-level)

```php
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolBootstrap;

WorkerPoolBootstrap::create(WorkerPoolConfig::withThreads(4))
    ->withHandler(MyWorkerStartHandler::class)
    ->run();
```

Accepts a `class-string<WorkerStartHandler>`. The class is instantiated fresh per thread.
`run()` blocks until the pool exits.

## ThreadQueueTransport

Thread-safe `WorkerTransport` backed by one `Swoole\Thread\Queue` per worker.

```php
$queues = [0 => new Queue(), 1 => new Queue(), 2 => new Queue()];
$transport = new ThreadQueueTransport($queues, workerId: 1);

$transport->send(0, $envelope);   // push to worker 0's queue
$transport->listen($handler);     // start adaptive-poll receive loop
$transport->close();              // stop the loop
```

### Adaptive poll backoff

The receive coroutine uses non-blocking `Queue::pop(0)` to stay coroutine-friendly
(blocking `pop()` would freeze the OS thread):

| Consecutive empty polls | Sleep |
|------------------------|-------|
| < 10 | `Coroutine::sleep(0)` — immediate yield |
| 10 – 99 | 100 µs |
| 100 – 999 | 1 ms |
| ≥ 1000 | 10 ms (idle steady state) |

When a message arrives, the counter resets to zero.

## ThreadMapDirectory

Thread-safe `WorkerDirectory` backed by a shared `Swoole\Thread\Map`.

```php
use Swoole\Thread\Map;

$map = new Map();  // shared across all workers
$dir = new ThreadMapDirectory($map);

$dir->register('/user/orders', 2);
$dir->lookup('/user/orders');  // 2
$dir->has('/user/orders');     // true
```

All workers share the same `Map` instance passed as a constructor argument.
`Thread\Map` handles synchronization internally.

## WorkerRunnable

Thread entrypoint implementing `Swoole\Thread\Runnable`. Used internally by
`WorkerPoolBootstrap` — you do not instantiate this directly.

On `run()`:
1. Atomically claims a worker ID via `Thread\Atomic`.
2. Creates `SwooleRuntime`, `ActorSystem`, `ThreadMapDirectory`, `ThreadQueueTransport`.
3. Builds `ConsistentHashRing` and `WorkerNode`.
4. Calls `$node->start()` (registers transport listener).
5. Instantiates and calls the `WorkerStartHandler`.
6. Calls `$system->run()` — blocks until shutdown.
