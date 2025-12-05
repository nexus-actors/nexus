---
sidebar_position: 2
title: Roadmap
---

# Roadmap

This page outlines planned features for Nexus. Items are listed roughly in
order of priority.

## Multi-process clustering

**Status:** In design phase.

The first clustering milestone adds multi-process support within a single
server. The design introduces two new packages:

- **nexus-cluster** -- Pure PHP abstractions for clustering: `ClusterConfig`,
  directory interfaces, transport interfaces, and `RemoteActorRef`.
- **nexus-cluster-swoole** -- Swoole-specific implementations using
  `Swoole\Process\Pool` for worker management, Unix socket IPC for
  cross-worker messaging, and `Swoole\Table` for a shared actor directory.

Key design decisions:

- Each worker process runs an independent `ActorSystem` with `SwooleRuntime`.
- A `ClusterNode` per worker handles cross-worker message routing.
- Consistent hashing determines which worker owns a given actor, enabling
  deterministic placement without a central coordinator.
- `RemoteActorRef` implements the `ActorRef` interface, providing location
  transparency -- actor code never knows if a target is local or remote.
- The directory and transport abstractions in `nexus-cluster` are designed
  to support future multi-server implementations without changes to actor code.

## Multi-server clustering

**Status:** Planned.

The second clustering milestone extends the system across multiple physical
or virtual servers:

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

## Additional runtimes

**Status:** Under consideration.

The runtime-agnostic architecture allows new runtime implementations:

- **ReactPHP** -- Event loop integration for ReactPHP-based applications.
- **AMPHP** -- Fiber-based async runtime with native async I/O.
- **FrankenPHP** -- Worker mode integration for FrankenPHP deployments.

Community contributions for additional runtimes are welcome. Any implementation
of the `Monadial\Nexus\Core\Runtime\Runtime` interface is compatible with the
full Nexus actor system.
