# nexus-worker-pool

Core worker pool abstractions and implementations. Pure PHP — no Swoole dependency.

## Installation

```bash
composer require nexus-actors/worker-pool
```

## WorkerNode

The central coordinator for one worker thread. Handles actor spawn routing,
transport listening, and the worker ask protocol.

```php
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Monadial\Nexus\WorkerPool\Directory\InMemoryWorkerDirectory;
use Monadial\Nexus\WorkerPool\Transport\InMemoryWorkerTransport;

$node = new WorkerNode(
    workerId: 0,
    system: $actorSystem,
    transport: $transport,
    ring: new ConsistentHashRing(workerCount: 4),
    directory: $directory,
);

$node->start();  // begin listening on transport

$ref = $node->spawn(Props::fromBehavior($behavior), 'orders');
$ref->tell(new PlaceOrder($items));
```

### Methods

| Method | Description |
|--------|-------------|
| `spawn(Props $props, string $name): ActorRef` | Spawn actor locally or return WorkerActorRef for remote worker |
| `actorFor(string $path): ?ActorRef` | Look up a registered actor by path |
| `start(): void` | Register the transport listener |
| `workerId(): int` | This worker's ID |
| `system(): ActorSystem` | The underlying ActorSystem |

## WorkerActorRef

Implements `ActorRef<T>` for actors on other workers. `tell()` wraps the message
in an `Envelope` and pushes it to the target worker's transport inbox.

No serializer is involved — the transport implementation handles object delivery
(e.g. `Thread\Queue` copies the object internally).

## ConsistentHashRing

```php
use Monadial\Nexus\WorkerPool\ConsistentHashRing;

$ring = new ConsistentHashRing(workerCount: 4);
$workerId = $ring->getWorker('orders');  // 0, 1, 2, or 3
```

CRC32-based hash ring with 150 virtual nodes per worker. Immutable and `readonly`.

## WorkerPoolConfig

```php
$config = WorkerPoolConfig::withThreads(8);
echo $config->workerCount; // 8
```

## WorkerTransport (interface)

```php
interface WorkerTransport
{
    public function send(int $targetWorker, Envelope $envelope): void;
    public function listen(callable $onEnvelope): void;
    public function close(): void;
}
```

### InMemoryWorkerTransport (test double)

```php
$transport = new InMemoryWorkerTransport();
$transport->send(1, $envelope);

$sent = $transport->getSentTo(1);   // list<Envelope> sent to worker 1
$all  = $transport->getSent();      // all sent entries with targetWorker

$transport->receive($envelope);     // simulate an incoming envelope
```

## WorkerDirectory (interface)

```php
interface WorkerDirectory
{
    public function register(string $path, int $workerId): void;
    public function lookup(string $path): ?int;
    public function has(string $path): bool;
}
```

### InMemoryWorkerDirectory (test double)

```php
$dir = new InMemoryWorkerDirectory();
$dir->register('/user/orders', 2);
$dir->lookup('/user/orders');  // 2
$dir->has('/user/orders');     // true
```

## WorkerStartHandler (interface)

```php
interface WorkerStartHandler
{
    public function onWorkerStart(WorkerNode $node): void;
}
```

Implement this interface to set up actors when a worker thread starts.
Pass the class name (string) to `WorkerPoolBootstrap::withHandler()`.
