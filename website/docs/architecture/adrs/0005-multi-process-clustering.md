---
title: "ADR 0005: Multi-Process Clustering via Swoole IPC"
sidebar_position: 5
---

# ADR 0005: Multi-Process Clustering via Swoole IPC

## Status

Superseded by [ADR 0008](0008-worker-pool-cluster-separation.md)

> This ADR describes the original multi-process clustering design using `Process\Pool`
> and Unix socket IPC (`UnixSocketTransport`, `SwooleTableDirectory`). The architecture
> was replaced by a Swoole thread-based worker pool in ADR 0008. The content is preserved
> for historical context.

## Context

A single PHP process, even with Swoole coroutines, is limited to one CPU core. For production workloads, actors must be distributed across multiple processes on the same machine. This requires:

1. A way to route messages to actors on other processes.
2. A shared directory so any process can locate any actor.
3. Transparent location: sending a message to a remote actor should look identical to sending locally.

## Decision

Implement multi-process clustering using Swoole's IPC primitives:

- **`Swoole\Process\Pool`**: Manages worker processes with automatic restart on crash.
- **Unix socket transport**: Each worker binds a Unix domain socket. Messages are length-prefixed binary frames.
- **`SwooleTableDirectory`**: Shared-memory actor directory using `Swoole\Table`. All workers can read/write actor locations.
- **`ConsistentHashRing`**: Deterministic actor placement using CRC32 hashing with virtual nodes for even distribution.
- **`RemoteActorRef`**: Implements `ActorRef` — serializes the message and sends it via transport. Callers cannot distinguish local from remote refs.
- **`ClusterBootstrap`**: Orchestrates startup — creates process pool, initializes transport and directory, runs the actor system on each worker.

The cluster layer is split into two packages:
- `nexus-cluster`: Pure PHP interfaces and abstractions (no Swoole dependency).
- `nexus-cluster-swoole`: Swoole-specific implementations.

## Consequences

- Actors are automatically distributed across workers via consistent hashing.
- Adding/removing workers rebalances with minimal disruption.
- Cross-process messaging adds serialization overhead (~50μs per message).
- The cluster package abstractions allow future transport implementations (TCP, shared memory).
- Location transparency means existing actor code works unchanged in a cluster.
