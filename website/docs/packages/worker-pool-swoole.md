---
title: nexus-worker-pool-swoole
related:
  - packages/worker-pool
  - packages/runtime-swoole
  - scaling/overview
  - scaling/bootstrap
---

# nexus-worker-pool-swoole

:::caution Experimental
The worker pool is experimental and not yet production-hardened. APIs and delivery semantics may change before 1.0.
:::

Swoole thread primitives for the worker pool: `Thread\Queue` transport, `Thread\Map` directory, and the `WorkerPool` DSL for zero-boilerplate pool setup.

## What's in this package

- `WorkerPool` — fluent builder: `withThreads`, `actor`, `stateful`, `behavior`, `configure`, `onStart`, `run`
- `WorkerPoolApp` — class-based alternative; extend and override `configure(WorkerNode $node)`
- `WorkerPoolBootstrap` — lower-level bootstrap via `Swoole\Thread\Pool`; used internally by `WorkerPool` and `WorkerPoolApp`
- `WorkerPoolHandle` — main-thread handle passed to `onStart`; `workerCount()`, `queues()`, `directory()`, `stop()`
- `ThreadQueueTransport` — `WorkerTransport` backed by one `Swoole\Thread\Queue` per worker; adaptive-poll backoff coroutine loop
- `ThreadMapDirectory` — `WorkerDirectory` backed by a shared `Swoole\Thread\Map`
- `WorkerRunnable` — `Swoole\Thread\Runnable` thread entrypoint; used internally

## Requirements

- PHP 8.5+ with ZTS (verify: `php -r 'echo PHP_ZTS;'` must print `1`)
- Swoole 6.2.1+ compiled with `--enable-swoole-thread`

## Install

```bash
composer require nexus-actors/worker-pool-swoole
```

## Quick example

<!-- verify:skip: requires ZTS PHP + Swoole threads -->
```php title="src/Worker/boot.php" verify:skip
use Monadial\Nexus\WorkerPool\Swoole\WorkerPool;
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolHandle;

WorkerPool::withThreads(8)
    ->withName('shop')
    ->actor('orders', OrderActor::class)
    ->actor('payments', PaymentActor::class)
    ->configure(static function (\Monadial\Nexus\WorkerPool\WorkerNode $node): void {
        $node->log()->info('Worker ready', ['id' => $node->workerId()]);
    })
    ->onStart(function (WorkerPoolHandle $handle): void {
        echo 'Workers ready: ' . $handle->workerCount() . PHP_EOL;
    })
    ->run();
```

Closures passed to `behavior()`, `configure()`, and `withLoggerFactory()` must be `static` with no object captures — they are serialized via opis/closure before crossing thread boundaries.

## See also

- [nexus-worker-pool](./worker-pool.md) — `WorkerNode`, `ConsistentHashRing`, `WorkerActorRef`, and transport/directory interfaces
- [Scaling / overview](../scaling/overview.md) — topology guide
- [Scaling / bootstrap](../scaling/bootstrap.md) — full wiring examples
