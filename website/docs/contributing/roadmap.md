---
sidebar_position: 2
title: Roadmap
---

# Roadmap

This page outlines planned features for Nexus. Items are listed roughly in
order of priority.

## Multi-worker scaling

**Status:** Implemented.

Multi-worker scaling is available via the `nexus-cluster` and
`nexus-worker-pool-swoole` packages. See the [Scaling documentation](../scaling/overview.md)
for full details.

This is single-machine scaling via Swoole threads -- utilizing all CPU
cores on one server. Not to be confused with multi-server clustering (see below).

Key features:

- **`WorkerPoolApp`** and **`WorkerPoolBootstrap`** start N worker threads, each
  running an independent `ActorSystem`.
- **`ConsistentHashRing`** determines actor placement without coordination.
- **`WorkerActorRef`** provides location-transparent cross-worker messaging.
- **`ThreadQueueTransport`** passes `Envelope` objects directly via `Thread\Queue`.
  Benchmarked at 260K msgs/sec per worker pair.
- **`ThreadMapDirectory`** provides O(1) shared actor lookups via `Thread\Map`.
- Pure PHP abstractions in `nexus-cluster` are designed to support future
  multi-server clustering without changes to actor code.

## Multi-machine clustering

**Status:** Planned.

TCP-based clustering across multiple hosts. `nexus-cluster` contracts
(`ClusterTransport`, `NodeDirectory`, `NodeHashRing`, `NodeAddress`) are defined.
A future `nexus-cluster-swoole` will provide the TCP implementation.

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

## Single-writer persistence

**Status:** Implemented.

Akka-style single-writer guarantee for persistent actors. Each `ActorSystem`
gets a unique ULID identity stamped on every persisted envelope, enabling
detection of concurrent writes from different systems:

- **Writer identity** -- `ActorSystem::writerId()` returns a `Ulid` assigned at
  startup. All `EventEnvelope`, `SnapshotEnvelope`, and `DurableStateEnvelope`
  carry a `writerId` field stored in a `writer_id` column.
- **`WriterConflictException`** -- thrown when a store detects a write from a
  different writer. Properties: `persistenceId`, `expectedWriter`, `actualWriter`,
  `sequenceNr`.
- **`ReplayFilter`** -- validates writer consistency during event replay with
  configurable modes: `Fail` (throw on interleave), `Warn` (log warning),
  `RepairByDiscardOld` (keep only latest writer's events), `Off` (skip).
  Configurable via `withReplayFilter(ReplayFilterMode)` on behavior builders.
- **Optimistic version checks** -- Event stores use composite primary key
  `(persistence_id, sequence_nr)` for natural conflict detection. Durable state
  stores use version checking (`WHERE version = ?` in DBAL, `#[ORM\Version]` in
  Doctrine). Conflicts throw `ConcurrentModificationException`.
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
of the `Monadial\Nexus\Runtime\Runtime\Runtime` interface is compatible with the
full Nexus actor system.
