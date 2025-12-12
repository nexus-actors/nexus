---
sidebar_position: 2
title: Configuration
---

# Cluster Configuration

## ClusterConfig

`ClusterConfig` is an immutable value object that defines the cluster topology.

```php
use Monadial\Nexus\Cluster\ClusterConfig;

$config = ClusterConfig::withWorkers(
    workerCount: 8,          // Number of worker processes
    tableSize: 65536,        // Swoole\Table capacity for actor directory
    socketDir: '/tmp/nexus', // Unix socket directory (optional)
);
```

| Parameter | Default | Description |
|---|---|---|
| `workerCount` | *(required)* | Number of worker processes. Must be >= 1. Typically set to the number of CPU cores. |
| `tableSize` | `65536` | Maximum number of entries in the `Swoole\Table` actor directory. Must be a power of 2. |
| `socketDir` | `/tmp/nexus-cluster-{pid}` | Directory for Unix domain socket files. Auto-generated if not specified. |

### Choosing workerCount

A good starting point is the number of CPU cores available:

```php
$config = ClusterConfig::withWorkers((int) shell_exec('nproc'));
```

For I/O-bound workloads, you may benefit from more workers than cores. For
CPU-bound workloads, match the core count.

### Choosing tableSize

The `tableSize` determines how many actors can be registered in the shared
directory. Set it to at least the expected maximum number of actors across all
workers. The value must be a power of 2 (Swoole requirement).

## ConsistentHashRing

The hash ring determines which worker owns each actor. It is built identically
on every worker from the `workerCount` alone -- no coordination needed.

```php
use Monadial\Nexus\Cluster\ConsistentHashRing;

$ring = new ConsistentHashRing(workerCount: 8);

$workerId = $ring->getWorker('orders');   // deterministic: always same worker
$workerId = $ring->getWorker('payments'); // may be a different worker
```

The ring uses crc32 hashing with 150 virtual nodes per worker for even
distribution. With 8 workers and 100 actors, each worker typically owns 10-15
actors (within ~50% of the ideal 12.5).

### How placement works

When `ClusterNode::spawn()` is called:

1. The hash ring maps the actor name to a worker ID.
2. If the worker ID matches the current worker, the actor is spawned locally.
3. If it maps to a different worker, a `RemoteActorRef` is returned.

All workers call `spawn()` for every actor with the same name and Props.
Only the owning worker actually creates the actor -- the others get remote
references. This avoids serializing `Props` (which may contain closures) across
processes.

## ClusterSerializer

The `ClusterSerializer` interface handles envelope serialization for IPC:

```php
use Monadial\Nexus\Cluster\Serialization\ClusterSerializer;
use Monadial\Nexus\Core\Mailbox\Envelope;

interface ClusterSerializer
{
    public function serialize(Envelope $envelope): string;
    public function deserialize(string $data): Envelope;
}
```

The default implementation, `PhpNativeClusterSerializer`, uses PHP's native
`serialize()` / `unserialize()`. This is optimal for same-machine IPC where
all workers share the same code and class definitions.

For custom serialization (e.g., JSON or Protocol Buffers), implement the
`ClusterSerializer` interface and pass it to `ClusterBootstrap::withSerializer()`.
