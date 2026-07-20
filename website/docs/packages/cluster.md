---
title: nexus-cluster
related:
  - packages/cluster-tcp
  - packages/worker-pool
  - packages/worker-pool-swoole
  - packages/messenger
  - scaling/overview
---

# nexus-cluster

:::caution Experimental
Clustering is experimental and not yet production-hardened. APIs and semantics may change before 1.0.
:::

Remote contracts for TCP-based multi-machine clustering — interfaces and value objects only. The shipped transport implementation lives in [`nexus-cluster-tcp`](./cluster-tcp.md).

## What's in this package

- `NodeAddress` — value object identifying a node by cluster/datacenter/application/node hierarchy. Each of the four segments must match `[a-zA-Z0-9_.-]+` (URL-safe and collision-free); spaces, slashes, and empty segments throw `InvalidArgumentException` at construction, so two distinct addresses can never alias to the same identity key.
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

- [nexus-cluster-tcp](./cluster-tcp.md) — the shipped TCP mesh implementation of these contracts: gossip membership, phi-accrual failure detection, location-transparent `ClusterRef` tell/ask, TLS
- [nexus-worker-pool](./worker-pool.md) — same-machine multi-core scaling
- [nexus-worker-pool-swoole](./worker-pool-swoole.md) — Swoole thread pool implementation
- [Scaling overview](../scaling/overview.md) — topology guide
