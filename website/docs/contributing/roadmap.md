---
sidebar_position: 2
title: Roadmap
---

# Roadmap

This page outlines planned features for Nexus. Items are listed roughly in
order of priority.

## Multi-process scaling

**Status:** Implemented.

Multi-process scaling is available via the `nexus-cluster` and
`nexus-cluster-swoole` packages. See the [Scaling documentation](../scaling/overview.md)
for full details.

This is single-machine scaling via Swoole's `Process\Pool` -- utilizing all CPU
cores on one server. Not to be confused with multi-server clustering (see below).

Key features:

- **`ClusterBootstrap`** starts a `Swoole\Process\Pool` with N workers, each
  running an independent `ActorSystem`.
- **`ConsistentHashRing`** determines actor placement without coordination.
- **`RemoteActorRef`** provides location-transparent cross-worker messaging.
- **`UnixSocketTransport`** uses AF_UNIX domain sockets with length-prefixed
  framing. Benchmarked at 255K msgs/sec per worker pair.
- **`SwooleTableDirectory`** provides O(1) shared-memory actor lookups.
- Pure PHP abstractions in `nexus-cluster` are designed to support future
  multi-server clustering without changes to actor code.

## Multi-server clustering

**Status:** Planned.

True distributed clustering extends the system across multiple physical or
virtual servers:

- TCP transport for cross-server messaging.
- Distributed actor directory with consistency guarantees.
- Cluster membership and failure detection.
- Rebalancing when nodes join or leave the cluster.

The pure-PHP abstractions from `nexus-cluster` will be reused, with new
transport and directory implementations for the network layer.

## Observability

**Status:** Planned.

Comprehensive observability tooling for production deployments:

- **Metrics** -- Actor count, message throughput, mailbox depth, processing
  latency, supervision events. Integration with Prometheus or OpenTelemetry.
- **Structured logging** -- Contextual log entries with actor path, message
  type, and correlation IDs.
- **Tracing** -- Distributed trace propagation through actor message chains,
  compatible with OpenTelemetry.

## Developer tooling

**Status:** Planned.

Tools to improve the development and debugging experience:

- **Actor inspector** -- Runtime introspection of actor hierarchies, states,
  mailbox depths, and behavior chains.
- **Message tracing** -- Record and replay message flows for debugging
  complex actor interactions.

## Optimistic locking for persistence

**Status:** Implemented.

Concurrency control for persistent actors when multiple processes or cluster
nodes access the same event store or state store:

- **Optimistic locking** -- Each persisted event or state carries a version
  number. On write, the store checks that the version matches the expected
  value. If another process wrote first, a `ConcurrentModificationException` is
  thrown and the actor can retry or escalate via supervision. Zero database
  locks, no contention under normal operation.
- **Event stores** -- The composite primary key `(persistence_id, sequence_nr)`
  provides natural optimistic locking. Duplicate sequence numbers are caught and
  wrapped in `ConcurrentModificationException`.
- **Durable state stores** -- The `version` column is checked on every update.
  DBAL uses `WHERE version = ?`; Doctrine uses `#[ORM\Version]` for automatic
  version management.
- **Injectable serializers** -- All DBAL and Doctrine stores accept a
  `MessageSerializer` constructor parameter (default: `PhpNativeSerializer`),
  allowing custom serialization strategies (JSON, Valinor, etc.).

## Pessimistic locking for persistence

**Status:** Planned.

Pessimistic locking for high-conflict workloads:

- Acquire a database-level lock (e.g. `SELECT ... FOR UPDATE`) before processing
  a command. Guarantees exclusive access but introduces contention.
- Best for workloads where retries would be expensive (financial transactions,
  inventory).

## Additional runtimes

**Status:** Under consideration.

The runtime-agnostic architecture allows new runtime implementations:

- **ReactPHP** -- Event loop integration for ReactPHP-based applications.
- **AMPHP** -- Fiber-based async runtime with native async I/O.
- **FrankenPHP** -- Worker mode integration for FrankenPHP deployments.

Community contributions for additional runtimes are welcome. Any implementation
of the `Monadial\Nexus\Core\Runtime\Runtime` interface is compatible with the
full Nexus actor system.
