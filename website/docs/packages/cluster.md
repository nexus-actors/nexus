---
title: nexus-cluster
related:
  - packages/worker-pool
  - packages/worker-pool-swoole
  - packages/messenger
  - scaling/overview
---

# nexus-cluster

Remote contracts for future TCP-based multi-machine clustering — interfaces and value objects only, no transport implementation.

## What's in this package

- `NodeAddress` — value object identifying a node by cluster/datacenter/application/node hierarchy
- `ClusterTransport` — interface for byte-level inter-node message delivery
- `NodeDirectory` — interface mapping actor paths to node addresses
- `NodeHashRing` — consistent hash ring mapping actor names to `NodeAddress` instances

## Install

```bash
composer require nexus-actors/cluster
```

## Quick example

```php title="src/Cluster/NodeSetup.php"
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\NodeHashRing;

$nodeA = new NodeAddress('prod', 'eu-west-1', 'orders', 'node-0');
$nodeB = new NodeAddress('prod', 'eu-west-1', 'orders', 'node-1');

$ring = new NodeHashRing([$nodeA, $nodeB]);
$target = $ring->getNode('order-processor'); // deterministic assignment
echo $target->toString(); // prod/eu-west-1/orders/node-0
```

`NodeHashRing` uses the same CRC32 algorithm as `ConsistentHashRing` in `nexus-worker-pool`, but maps actor names to `NodeAddress` instances rather than integer worker IDs.

## See also

- [nexus-worker-pool](./worker-pool.md) — same-machine multi-core scaling
- [nexus-worker-pool-swoole](./worker-pool-swoole.md) — Swoole thread pool implementation
- [Scaling overview](../scaling/overview.md) — topology guide
