# nexus-cluster

Remote contracts for future TCP-based multi-machine clustering.

This package contains **interfaces and value objects only** — no implementation.
A future TCP transport package will implement these interfaces using
TCP transport and a distributed directory.

## Installation

```bash
composer require nexus-actors/cluster
```

## Contracts

### NodeAddress

Identifies a node in the cluster hierarchy:

```php
use Monadial\Nexus\Cluster\NodeAddress;

$node = new NodeAddress(
    cluster: 'production',
    datacenter: 'eu-west-1',
    application: 'nexus-app',
    node: 'node-3',
);

echo $node->toString(); // production/eu-west-1/nexus-app/node-3
```

### ClusterTransport

```php
interface ClusterTransport
{
    public function send(NodeAddress $target, string $data): void;
    public function listen(callable $onMessage): void;
    public function close(): void;
}
```

TCP-level byte transport between nodes. The `$data` string is a serialized envelope.
Implementations provide the actual network transport.

### NodeDirectory

```php
interface NodeDirectory
{
    public function register(string $path, NodeAddress $node): void;
    public function lookup(string $path): ?NodeAddress;
}
```

Maps actor path strings to cluster node addresses for routing.

### NodeHashRing

```php
use Monadial\Nexus\Cluster\NodeHashRing;
use Monadial\Nexus\Cluster\NodeAddress;

$ring = new NodeHashRing([$nodeA, $nodeB, $nodeC]);
$target = $ring->getNode('orders');  // deterministic assignment
```

Same CRC32 algorithm as `WorkerPool\ConsistentHashRing`, but maps names to
`NodeAddress` instances instead of integer worker IDs.

## Relationship to nexus-worker-pool

For same-machine multi-core scaling, use `nexus-worker-pool` and
`nexus-worker-pool-swoole` — they use Swoole threads and need no serialization.

`nexus-cluster` is reserved for cross-machine scaling (different hosts, TCP transport).
See [Scaling Overview](../scaling/overview.md) for the thread-based worker pool.
