# ADR 0005: Multi-Process Clustering via Swoole IPC

## Status

Accepted

## Context

A single PHP process, even with Swoole coroutines, is limited to one CPU core. For production workloads, actors must be distributed across multiple processes on the same machine. This requires:

1. A way to route messages to actors on other processes.
2. A shared directory so any process can locate any actor.
3. Transparent location: sending a message to a remote actor should look identical to sending locally.

## Decision

Implement multi-thread clustering using Swoole's thread primitives:

- **`Swoole\Thread`**: Manages worker threads within a single process.
- **Thread queue transport**: Each worker has a `Swoole\Thread\Queue` for receiving messages. Lock-free, zero-copy.
- **`ThreadMapDirectory`**: Thread-shared actor directory using `Swoole\Thread\Map`. All workers can read/write actor locations.
- **`ConsistentHashRing`**: Deterministic actor placement using CRC32 hashing with virtual nodes for even distribution.
- **`RemoteActorRef`**: Implements `ActorRef` — serializes the message and sends it via transport. Callers cannot distinguish local from remote refs.
- **`ThreadClusterBootstrap`**: Orchestrates startup — creates threads, initializes transport and directory, runs the actor system on each worker.

The cluster layer is split into two packages:
- `nexus-cluster`: Pure PHP interfaces and abstractions (no Swoole dependency).
- `nexus-cluster-swoole-thread`: Swoole thread-specific implementations.

**Note:** The original implementation used `Swoole\Process\Pool` with Unix socket transport and `SwooleTableDirectory` (`nexus-cluster-swoole`). This was superseded by the thread-based approach for better performance and simpler architecture.

## Consequences

- Actors are automatically distributed across workers via consistent hashing.
- Adding/removing workers rebalances with minimal disruption.
- Cross-process messaging adds serialization overhead (~50μs per message).
- The cluster package abstractions allow future transport implementations (TCP, shared memory).
- Location transparency means existing actor code works unchanged in a cluster.
