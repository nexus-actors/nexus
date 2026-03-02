# nexus-worker-pool

Core worker pool abstractions and implementations. Pure PHP — no Swoole dependency.

## Installation

```bash
composer require nexus-actors/worker-pool
```

## Overview

The worker pool distributes actors across N parallel threads. Each thread runs one
`WorkerNode`, which owns an `ActorSystem` and routes messages using a consistent hash
ring. All cross-worker message delivery is serialization-free — the transport layer
(`Thread\Queue` in Swoole threads) handles object passing internally.

```
Thread 0: WorkerNode(id=0) → ActorSystem → [orders, billing]
Thread 1: WorkerNode(id=1) → ActorSystem → [payments, shipping]
Thread 2: WorkerNode(id=2) → ActorSystem → [inventory, analytics]
```

The hash ring is deterministic and identical on every thread — no coordination
protocol is needed to agree on actor placement.

## WorkerNode

`WorkerNode` is the per-worker coordinator. Each worker thread holds exactly one
`WorkerNode` instance. It is injected into `WorkerStartHandler::onWorkerStart()` and
is the primary interface for spawning actors and resolving cross-worker references.

```php
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\Behavior;

class MyWorkerHandler implements WorkerStartHandler
{
    public function onWorkerStart(WorkerNode $node): void
    {
        // Spawn an actor — the hash ring decides which worker owns it.
        // Returns LocalActorRef if this worker owns the name,
        // WorkerActorRef if another worker owns it.
        $ref = $node->spawn(
            Props::fromBehavior(Behavior::receive(
                static fn ($ctx, $msg): Behavior => Behavior::same(),
            )),
            'order-processor',
        );

        // Resolve any actor registered in the directory.
        // Returns WorkerActorRef for remote actors, LocalActorRef for local ones.
        // Returns null if the actor has not been registered yet.
        $remoteRef = $node->actorFor('/user/payment-gateway');
    }
}
```

`spawn()` consults the hash ring. If the actor name hashes to this worker's ID, the
actor is spawned locally in the `ActorSystem` and the path is registered in the
`WorkerDirectory`. If the name hashes to a different worker, `spawn()` registers the
path with the owning worker ID and returns a `WorkerActorRef` that routes messages
via the transport.

### WorkerNode methods

| Method | Description |
|--------|-------------|
| `spawn(Props $props, string $name): ActorRef` | Spawn actor locally or return `WorkerActorRef` for the owning worker |
| `actorFor(string $path): ?ActorRef` | Resolve a registered actor by full path; `null` if unknown |
| `start(): void` | Begin listening for incoming transport envelopes |
| `workerId(): int` | This worker's numeric ID (0-based) |
| `system(): ActorSystem` | The underlying `ActorSystem` for this worker |

## WorkerActorRef

`WorkerActorRef<T>` implements `ActorRef<T>` for actors that live on a different
worker. `tell()` wraps the message in an `Envelope` and calls
`WorkerTransport::send()` targeting the owner worker's inbox. No application-level
serializer is involved.

```php
use Monadial\Nexus\WorkerPool\WorkerActorRef;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Async\Future;

// tell() — fire and forget, returns immediately
$ref->tell(new ProcessOrder($orderId));

// ask() — request-response across workers
// Returns a Future<R>; await() suspends the current fiber/coroutine
// until the reply arrives or the timeout expires.
/** @var Future<OrderStatus> $future */
$future = $ref->ask(new GetStatus($orderId), Duration::millis(200));
$status = $future->await();
```

The `ask()` signature on `WorkerActorRef` takes the message directly — it does not
use the `callable(ActorRef): T` factory form used by the core `ActorRef` interface.
The cross-worker ask protocol sends a `WorkerAskRequest` envelope, waits for a
`WorkerAskAck`, then resolves the `Future` when the `WorkerAskReply` arrives.
Up to three automatic retries are made at 50 ms intervals before the timeout fires.

`WorkerActorRef::isAlive()` checks the `WorkerDirectory` — it returns `true` as long
as the actor path remains registered.

## ConsistentHashRing

`ConsistentHashRing` maps actor names to worker IDs using CRC32 with 150 virtual
nodes per worker. The ring is immutable and `readonly`. The same actor name always
resolves to the same worker ID as long as the worker count does not change.

```php
use Monadial\Nexus\WorkerPool\ConsistentHashRing;

$ring = new ConsistentHashRing(workerCount: 8);
$workerId = $ring->getWorker('order-processor');  // deterministic, 0–7
```

All `WorkerNode` instances in a pool are constructed with the same `workerCount`, so
every node independently agrees on actor placement without communication.

When the pool scales from N to M workers, approximately `1 - (N/M)` of actor names
reassign to a different worker. In-memory actor state on the previous owner is not
migrated automatically — actors that move workers start fresh.

| Parameter | Default | Description |
|-----------|---------|-------------|
| `$workerCount` | — | Total number of workers in the pool |
| `$virtualNodes` | `150` | Virtual nodes per worker for even distribution |

## WorkerPoolConfig

`WorkerPoolConfig` carries the configuration that is passed to `WorkerPoolBootstrap`
(or the `nexus-worker-pool-swoole` DSL). It is a `readonly` value object.

```php
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;

// Minimum required: number of worker threads
$config = WorkerPoolConfig::withThreads(16);
echo $config->workerCount; // 16

// Optional: customise the ActorSystem name prefix for each worker.
// Worker 0 becomes 'payment-0', worker 1 becomes 'payment-1', etc.
// Default prefix is 'worker'.
$config = WorkerPoolConfig::withThreads(16)->withSystemNamePrefix('payment');
```

`workerCount` must be at least 1; `withThreads()` throws `InvalidArgumentException`
for values below 1.

## Interfaces

### WorkerTransport

```php
namespace Monadial\Nexus\WorkerPool\Transport;

use Monadial\Nexus\Core\Mailbox\Envelope;

interface WorkerTransport
{
    /** Deliver an envelope to the target worker's inbox. */
    public function send(int $targetWorker, Envelope $envelope): void;

    /**
     * Register an envelope listener for this worker.
     *
     * @param callable(Envelope): void $onEnvelope
     */
    public function listen(callable $onEnvelope): void;

    /** Release transport resources. */
    public function close(): void;
}
```

Implementations: `InMemoryWorkerTransport` (tests), `ThreadQueueTransport` (Swoole
threads — see `nexus-worker-pool-swoole`).

#### InMemoryWorkerTransport (test double)

```php
use Monadial\Nexus\WorkerPool\Transport\InMemoryWorkerTransport;

$transport = new InMemoryWorkerTransport();
$transport->send(1, $envelope);

$sent = $transport->getSentTo(1);   // list<Envelope> delivered to worker 1
$all  = $transport->getSent();      // all sent entries with targetWorker

$transport->receive($envelope);     // inject an incoming envelope for testing
```

### WorkerDirectory

```php
namespace Monadial\Nexus\WorkerPool\Directory;

interface WorkerDirectory
{
    /** Associate an actor path with a worker ID. */
    public function register(string $path, int $workerId): void;

    /** Return the worker ID that owns the path, or null if unknown. */
    public function lookup(string $path): ?int;

    /** Return true if the path has been registered. */
    public function has(string $path): bool;
}
```

Implementations: `InMemoryWorkerDirectory` (tests), `ThreadMapDirectory` (Swoole
threads — backed by a shared `Swoole\Thread\Map`).

#### InMemoryWorkerDirectory (test double)

```php
use Monadial\Nexus\WorkerPool\Directory\InMemoryWorkerDirectory;

$dir = new InMemoryWorkerDirectory();
$dir->register('/user/orders', 2);
$dir->lookup('/user/orders');  // 2
$dir->has('/user/orders');     // true
```

### WorkerStartHandler

```php
namespace Monadial\Nexus\WorkerPool;

interface WorkerStartHandler
{
    public function onWorkerStart(WorkerNode $node): void;
}
```

Implement this interface to set up actors when a worker thread starts. The class name
(string) is passed as a thread argument and re-instantiated fresh in each thread.
Closures cannot cross Swoole thread boundaries, so the handler must be a named class.

```php
use Monadial\Nexus\WorkerPool\WorkerStartHandler;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\Behavior;

final class AppWorkerHandler implements WorkerStartHandler
{
    public function onWorkerStart(WorkerNode $node): void
    {
        $node->spawn(
            Props::fromBehavior(Behavior::receive(
                static fn ($ctx, $msg): Behavior => Behavior::same(),
            )),
            'orders',
        );

        $node->spawn(
            Props::fromBehavior(Behavior::receive(
                static fn ($ctx, $msg): Behavior => Behavior::same(),
            )),
            'payments',
        );
    }
}
```

Pass `AppWorkerHandler::class` to the Swoole bootstrap or the `WorkerPool` DSL `->onStart()` method. The handler is called once per worker thread after its `ActorSystem` boots.
