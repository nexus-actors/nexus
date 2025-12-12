---
sidebar_position: 1
title: Clustering Overview
---

# Clustering Overview

Nexus clustering distributes actors across multiple worker processes on a single
machine, utilizing all available CPU cores. Each worker runs an independent
`ActorSystem` with its own `SwooleRuntime`, while a shared directory and Unix
socket transport enable transparent cross-worker messaging.

## Architecture

```
                    ┌─────────────────────────────────┐
                    │        Swoole Process\Pool       │
                    ├────────┬────────┬────────┬───────┤
                    │Worker 0│Worker 1│Worker 2│  ...  │
                    │        │        │        │       │
                    │ Actor  │ Actor  │ Actor  │ Actor │
                    │ System │ System │ System │ System│
                    │        │        │        │       │
                    │Cluster │Cluster │Cluster │Cluster│
                    │ Node   │ Node   │ Node   │ Node  │
                    └───┬────┴───┬────┴───┬────┴───┬───┘
                        │        │        │        │
                    Unix Socket IPC (AF_UNIX)
                        │        │        │        │
                    ┌───┴────────┴────────┴────────┴───┐
                    │     Swoole\Table (shared memory)  │
                    │         Actor Directory            │
                    └──────────────────────────────────┘
```

### Key components

- **`ClusterNode`** -- Per-worker coordinator. Routes messages locally or via
  transport based on a consistent hash ring. Each worker has exactly one.
- **`ConsistentHashRing`** -- Deterministic mapping from actor names to worker
  IDs using crc32 with 150 virtual nodes. Same output on all workers, no
  coordination needed.
- **`RemoteActorRef`** -- Implements `ActorRef<T>` for cross-worker messaging.
  Actor code never knows if a reference is local or remote.
- **`UnixSocketTransport`** -- AF_UNIX domain sockets with length-prefixed
  binary framing. Non-blocking via Swoole coroutines.
- **`SwooleTableDirectory`** -- Shared-memory actor directory backed by
  `Swoole\Table`. O(1) lookups across all worker processes.

## Location transparency

The `ActorRef<T>` interface is identical for local and remote actors:

```php
// This code works regardless of where the actor lives
$ref->tell(new ProcessOrder($orderId));
```

When `ClusterNode::spawn()` is called, the hash ring determines which worker
owns the actor. If the actor belongs to the current worker, a `LocalActorRef`
is returned and the actor runs locally. If it belongs to another worker, a
`RemoteActorRef` is returned that serializes messages and sends them over Unix
sockets to the owning worker.

## Message flow

1. Actor calls `$ref->tell($message)` on a `RemoteActorRef`.
2. The message is wrapped in an `Envelope` and serialized by `ClusterSerializer`.
3. The serialized bytes are sent via `UnixSocketTransport` to the target worker.
4. The target worker's transport listener deserializes the envelope.
5. The envelope is delivered to the local actor's mailbox via `enqueueEnvelope()`.
6. The actor processes the message as if it were sent locally.

## Performance

Benchmarked on a single machine (all workers in one process pool):

| Metric | Result |
|---|---|
| Cross-worker throughput | 233K msgs/sec per worker pair |
| Cross-worker round-trip latency | 20.8 us/roundtrip |
| Serialization throughput | 196K serialize+deserialize cycles/sec |
| Multi-worker fan-out (4 workers) | 204K msgs/sec aggregate |

## Package split

Clustering is split across two packages:

| Package | Purpose |
|---|---|
| **nexus-cluster** | Pure PHP interfaces and abstractions. No Swoole dependency. |
| **nexus-cluster-swoole** | Swoole implementations: `UnixSocketTransport`, `SwooleTableDirectory`, `ClusterBootstrap`. |

This separation means the clustering abstractions (`Transport`, `ActorDirectory`,
`ClusterSerializer`) can be implemented for other runtimes or transport layers
(e.g., TCP for multi-server clustering) without modifying actor code.
