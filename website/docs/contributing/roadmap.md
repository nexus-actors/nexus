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

## Concurrency control for persistence

**Status:** Implemented.

Configurable concurrency control for persistent actors via `LockingStrategy`,
supporting both optimistic and pessimistic modes per actor:

- **`LockingStrategy`** value object with `optimistic()` and
  `pessimistic(PessimisticLockProvider)` factories. Configurable per actor via
  `withLockingStrategy()` on both functional builders and class-based actors.
- **Optimistic mode** (default) -- zero-overhead pass-through. Event stores use
  composite primary key `(persistence_id, sequence_nr)` for natural conflict
  detection. Durable state stores use version checking (`WHERE version = ?` in
  DBAL, `#[ORM\Version]` in Doctrine). Conflicts throw
  `ConcurrentModificationException`.
- **Pessimistic mode** -- acquires an exclusive database lock before command
  processing, re-reads state from the store (catches writes by other processes),
  then processes the command and persists within the lock scope. Best for
  high-conflict workloads where retries are expensive (financial transactions,
  inventory).
- **`DbalPessimisticLockProvider`** uses a `nexus_persistence_lock` table with
  `SELECT ... FOR UPDATE` for row-level locking.
- **`DoctrinePessimisticLockProvider`** is a convenience wrapper delegating to
  the DBAL provider via the EntityManager's connection.
- **Injectable serializers** -- All DBAL and Doctrine stores accept a
  `MessageSerializer` constructor parameter (default: `PhpNativeSerializer`),
  allowing custom serialization strategies (JSON, Valinor, etc.).

## Symfony integration

**Status:** Planned.

A `nexus-symfony` bundle providing deep integration between Nexus and the
Symfony framework:

- **Symfony Messenger transport** -- Dispatch Symfony Messenger messages to Nexus
  actors. Actors act as message handlers, benefiting from supervision, mailbox
  backpressure, and concurrent processing.
- **Actor-aware Dependency Injection** -- Register actor behaviors as services
  in the Symfony container. Inject dependencies (repositories, API clients,
  loggers) into actor factories via standard Symfony DI.
- **Swoole Runtime integration** -- Run the full Symfony HTTP kernel inside
  Swoole workers alongside the actor system. Handle HTTP requests and actor
  messages in the same process with shared async I/O.
- **Console commands** -- Artisan-style commands to inspect running actors, dump
  the actor hierarchy, and manage the cluster from the CLI.
- **Event dispatcher bridge** -- Bridge between Symfony's `EventDispatcher` and
  Nexus actor messages. Symfony events can trigger actor messages and vice versa.
- **Profiler integration** -- Web Debug Toolbar panel showing actor count,
  message throughput, mailbox depths, and supervision events during development.

The goal is for Symfony applications to use actors as naturally as they use
services and message handlers today -- with full access to Symfony's ecosystem
(Doctrine, Security, Messenger, Cache) from within actors.

## Additional runtimes

**Status:** Under consideration.

The runtime-agnostic architecture allows new runtime implementations:

- **ReactPHP** -- Event loop integration for ReactPHP-based applications.
- **AMPHP** -- Fiber-based async runtime with native async I/O.
- **FrankenPHP** -- Worker mode integration for FrankenPHP deployments.

Community contributions for additional runtimes are welcome. Any implementation
of the `Monadial\Nexus\Core\Runtime\Runtime` interface is compatible with the
full Nexus actor system.
