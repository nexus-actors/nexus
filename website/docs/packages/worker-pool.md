---
title: nexus-worker-pool
related:
  - packages/worker-pool-swoole
  - packages/core
  - packages/runtime-swoole
  - scaling/overview
---

# nexus-worker-pool

Core worker pool abstractions for distributing actors across parallel threads — pure PHP, no Swoole dependency.

## What's in this package

- `WorkerNode` — per-thread coordinator; `spawn(Props, name)` routes via hash ring, `actorFor(path)` resolves registered actors
- `WorkerActorRef<T>` — `ActorRef<T>` for actors on a different worker; `tell()` delivers via `WorkerTransport`, `ask()` returns a `Future<R>`
- `ConsistentHashRing` — CRC32 ring with 150 virtual nodes; maps actor names to worker IDs deterministically
- `WorkerPoolConfig` — `withThreads(int)`, `withSystemNamePrefix(string)`
- `WorkerTransport` interface — `send(int $targetWorker, Envelope): void` / `listen(callable): void`; implementations: `InMemoryWorkerTransport` (tests), `ThreadQueueTransport` (Swoole)
- `WorkerDirectory` interface — `register(path, workerId)` / `lookup(path)` / `has(path)`; implementations: `InMemoryWorkerDirectory` (tests), `ThreadMapDirectory` (Swoole)
- `WorkerStartHandler` interface — implement to set up actors when a worker thread starts

## Install

```bash
composer require nexus-actors/worker-pool
```

## Quick example

```php title="src/Worker/AppWorkerHandler.php"
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerStartHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;

final class AppWorkerHandler implements WorkerStartHandler
{
    public function onWorkerStart(WorkerNode $node): void
    {
        $node->spawn(
            Props::fromBehavior(Behavior::receive(
                static fn($ctx, $msg): Behavior => Behavior::same(),
            )),
            'orders',
        );
    }
}
```

`spawn()` consults the hash ring: if the actor name hashes to this worker's ID it is spawned locally and returns a `LocalActorRef`; otherwise it registers the path with the owning worker and returns a `WorkerActorRef` that routes via transport.

## See also

- [nexus-worker-pool-swoole](./worker-pool-swoole.md) — `ThreadQueueTransport`, `ThreadMapDirectory`, and the `WorkerPool` DSL
- [Scaling / overview](../scaling/overview.md) — topology guide
