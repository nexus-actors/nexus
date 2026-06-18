---
sidebar_position: 2
title: Roadmap
---

# Roadmap

Where Nexus stands today, what's actively planned, and what's still
under consideration. The list is grouped by status, not priority; within
each section items are ordered roughly by user impact.

## Shipped

The full feature set you can use today, with the public packages that
back them.

### Core actor model

- `ActorSystem`, `ActorContext`, `ActorRef` (`LocalActorRef`,
  `WorkerActorRef`, `DeadLetterRef`).
- Closure-based `Behavior::receive` / `withState` / `setup` + class-based
  `ActorHandler` / `StatefulActorHandler` / `AbstractActor`.
- Lifecycle signals: `PreStart`, `PostStop`, `Terminated`, `ChildFailed`,
  `ReceiveTimeout`.
- Stash / unstash, death watch, scheduled messages, custom mailbox
  config, supervision strategies (one-for-one, all-for-one,
  exponential-backoff).
- Ask pattern returning `Future<R>` — `->await()` to block, `->map()` /
  `->flatMap()` to compose, `Future::all` for fan-out.

### Runtimes

- **`FiberRuntime`** — PHP 8.5 native Fibers, cooperative scheduler,
  priority-queue timers. Used in development and tests.
- **`SwooleRuntime`** — Swoole 6 coroutines, native channel-backed
  mailboxes, true async I/O via `SWOOLE_HOOK_ALL`. Production runtime.
- **`StepRuntime`** — deterministic single-step runtime for unit tests.
  `step()` / `drain()` give exact control over message ordering.

### Single-machine scaling

- `WorkerPool` / `WorkerPoolApp` / `WorkerPoolBootstrap` (Swoole thread
  pool, one `ActorSystem` per worker, shared `Thread\Map` directory).
- `ConsistentHashRing` — placement decided locally, no coordination.
- `WorkerActorRef` — location-transparent cross-worker messaging.
- `ThreadQueueTransport` — direct `Envelope` over `Thread\Queue`
  (~260K msgs/sec/pair).

### HTTP

- `HttpApplication` / `WsApplication` composition root.
- PSR-7/15 routing, handler resolution, attribute-driven param
  resolution (`#[FromActor]`, `#[FromService]`, `#[FromBody]`,
  `#[FromContext]`, `#[FromPrincipal]`).
- Bearer-token auth (`AuthenticationMiddleware`), per-request actor
  scoping, exception mapping (`onException()`).
- Toolkit middlewares: body-size limit, CORS, rate limit.
- Swoole-thread HTTP server with **graceful shutdown** wired through
  `BeforeShutdown` and a per-worker watchdog coroutine — no FATAL
  "all coroutines asleep" lines on `docker compose stop`.
- WebSocket routing + dispatcher (Swoole-thread mode rejects
  channel-mode routes at boot).

### Persistence

- **Event sourcing**: `EventSourcedBehavior` + `Effect::persist/none/
  stash/stop/reply`, snapshots, retention policies, command/event
  handlers, replay filter modes (`Fail`/`Warn`/`RepairByDiscardOld`/
  `Off`).
- **Durable state**: `DurableStateBehavior` for "just save the whole
  thing" aggregates.
- Storage backends: `InMemoryEventStore`, `DbalEventStore`,
  `DoctrineEventStore`, equivalents for snapshots + durable state.
- **Single-writer guarantee**: `ActorSystem::writerId()` (ULID) stamped
  on every persisted envelope, `WriterConflictException` on
  inter-writer drift, optimistic versioning on durable state.

### Doctrine integration

- **`ConnectionPool`** (DBAL): channel-backed, idle-TTL eviction, leak
  detection, PSR-14 events, accurate wait/timeout metrics. PSR-15
  scope middleware lends per-request leases; poisoning narrowed to
  `Doctrine\DBAL\Exception` only.
- **`EntityManagerPool`** (ORM): same lifecycle on top of `ConnectionPool`,
  PSR-15 scope middleware, `clearOnReturn`, `recreateAfter` recycling,
  wait/timeout metrics.
- **`#[Transactional]`** attribute: works on raw `Connection` handlers
  and `EntityManagerInterface` handlers; picks the right wrapping.
- **`PoolExhaustedToServiceUnavailable`**: maps both pools' exhaustion
  to HTTP 503 + `Retry-After: 1`.
- **`EntityBehavior` DSL**: entity-as-actor-state for
  non-event-sourced aggregates with replay policies (`FailIfMissing`,
  `CreateIfMissing`, `OnDemand`), idle passivation (`ReceiveTimeout`),
  optimistic-lock-aware retry, dedicated-connection mode
  (`withConnectionSource`) AND pool-backed mode
  (`withConnectionLifecycle($acquire, $release)`).

### Tooling

- `nexus-psalm` plugin: enforces readonly message classes, mutable-state
  detection in handlers, blocking-call detection inside handler
  closures, mutable-closure capture detection in `Props::fromFactory`,
  type providers for `Props::from*()` and `clone()`.
- Test runtime support: `TestRuntime`, `TestMailbox`, `TestClock`,
  Step runtime for deterministic ordering.
- Docker-only dev loop: `make` targets for unit, fiber, swoole,
  cluster, persistence, doctrine, http, http-swoole, mutation testing,
  Psalm, PHPCS, PHP-CS-Fixer, GrumPHP pre-commit gate.

## In progress

These are partially shipped — the contracts are in the codebase and the
pieces that work today are usable, but the full surface area is still
being filled in.

### Multi-machine clustering

The `nexus-cluster` package ships the contracts (`NodeAddress`,
`ClusterTransport`, `NodeDirectory`, `NodeHashRing`) so that actor code
is forward-compatible with a future TCP transport. A real
TCP-based implementation is the next piece. ETA: open.

### Observability

PSR-14 events are emitted today across pools, HTTP, and persistence; a
turnkey Prometheus / OpenTelemetry bridge is not yet shipped. Structured
logging via `nexus-logger` works (async, mailbox-backed, Monolog-handler
compatible). Distributed tracing through actor chains is on the
roadmap but not yet started.

## Planned

Designed but not yet under active development.

### Developer tooling

- **Actor inspector** — runtime introspection of actor hierarchies,
  mailbox depths, behavior chains.
- **Message tracing** — record/replay message flows for debugging
  complex actor interactions.

### Symfony integration

A `nexus-symfony` bundle for deep Symfony interop:

- **Messenger transport** — actors as Symfony Messenger handlers.
- **Actor-aware DI** — register behaviors as services.
- **Swoole runtime integration** — Symfony HTTP kernel inside Swoole
  workers alongside the actor system.
- **Console commands** — inspect the actor hierarchy, dump mailbox
  depths.
- **Event dispatcher bridge** — Symfony events ↔ actor messages.
- **Profiler panel** — Web Debug Toolbar showing actor stats.

## Under consideration

Open questions where we'd love community input.

### Additional runtimes

The `Runtime` interface is small and stable. Any implementation works
with the rest of the framework:

- **ReactPHP** — event loop integration.
- **AMPHP** — fiber-based async runtime with native async I/O.
- **FrankenPHP** — worker-mode integration.

Contributions for any of these are welcome.
