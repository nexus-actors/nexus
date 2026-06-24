---
title: "ADR 0008: Worker Pool / Cluster Separation"
sidebar_position: 8
related:
  - architecture/adrs/0005-multi-process-clustering
  - architecture/adrs/0007-remote-ask-protocol
  - scaling/overview
---

# ADR 0008: Worker Pool / Cluster Separation

## Status

Accepted

## Context

The original `nexus-cluster` (see ADR 0005) used OS-process isolation via `Swoole\Process\Pool`, Unix socket IPC, and `php_serialize` for all cross-worker messages. This introduced ~50 µs of serialization overhead per message hop.

Swoole 6.0 introduced `SWOOLE_THREAD` mode with `Thread\Queue` and `Thread\Map` primitives that allow PHP objects to be shared between threads without explicit serialization. The internal copy is handled by Swoole's thread engine.

These are two fundamentally different scaling models:

- **Local thread-based**: Multiple OS threads in the same process. Objects pass via `Thread\Queue` copies. No network or serialization overhead.
- **Remote node-based** (future): Multiple machines connected via TCP. Requires a serialization wire format for cross-network message delivery.

The original `nexus-cluster` tried to serve both models in one package, creating unnecessary coupling and preventing optimal implementations for either.

## Decision

Split into three packages with clear boundaries:

**`nexus-worker-pool`** — Pure PHP, local worker pool contracts and implementation.

- `WorkerNode`: per-worker coordinator, routes via consistent hash ring.
- `WorkerTransport` / `WorkerDirectory`: envelope-based interfaces (no serializer).
- `WorkerActorRef`: cross-worker reference that sends `Envelope` objects directly.
- `WorkerAsk*` protocol: request/reply/cancel/ack control flow (see ADR 0007).
- `InMemoryWorkerTransport` / `InMemoryWorkerDirectory`: test doubles.
- Depends only on `nexus-core` and `nexus-runtime`.

**`nexus-worker-pool-swoole`** — Swoole thread primitives. Requires ZTS PHP 8.5+ and Swoole 6.0+ compiled with `--enable-swoole-thread`.

- `ThreadQueueTransport`: `Thread\Queue`-backed transport with adaptive-poll coroutine.
- `ThreadMapDirectory`: `Thread\Map`-backed shared actor directory.
- `WorkerRunnable` + `WorkerPoolBootstrap` + `WorkerPoolApp`: thread pool bootstrap.
- Depends on `nexus-worker-pool`, `nexus-core`, and `nexus-runtime-swoole`.

**`nexus-cluster`** — Stripped to remote contracts only. No implementation.

- `NodeAddress`: multi-machine node identity (`cluster/datacenter/application/node`).
- `ClusterTransport`: TCP-level send/listen interface.
- `NodeDirectory`: actor path → `NodeAddress` mapping.
- `NodeHashRing`: consistent hash ring over `NodeAddress` list.
- Depends only on `nexus-core`.

`nexus-cluster-swoole` (the Swoole process-based implementation) is deleted entirely. A future TCP cluster implementation will live in a new `nexus-cluster-swoole` package that implements the `ClusterTransport` and `NodeDirectory` contracts.

## Consequences

### Positive

- No serialization overhead for same-machine workers. Objects cross thread boundaries via `Thread\Queue`'s internal copy mechanism.
- Clean architectural boundary: local scaling (worker pool) vs remote scaling (future cluster) have independent packages with independent interfaces.
- `nexus-worker-pool` has no Swoole dependency — usable with any PHP runtime that supports threads.
- `nexus-cluster` is a thin contracts package — easy to depend on without pulling in Swoole.

### Trade-offs

- The `WorkerAsk*` protocol mirrors the old `RemoteAsk*` naming; they are equivalent protocols. When the TCP cluster is built, it will use a similar protocol under `ClusterAsk*` or equivalent naming.
- `WorkerActorRef` does not enforce `#[MessageType]` for actual serialization — the Psalm rule `NonSerializableRemoteMessageRule` is kept as a convention check for forward compatibility when messages may eventually cross a serialization boundary.
