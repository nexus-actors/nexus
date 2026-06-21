---
sidebar_position: 1
title: Introduction
---

# Introduction

:::caution Under active development
Nexus is under active development. APIs are stabilising and the core actor model,
supervision, and persistence APIs are considered stable. Worker-pool and cluster
APIs may still evolve.
:::

Nexus is an actor system for PHP 8.5+, drawing inspiration from Akka (JVM) and
OTP (Erlang/Elixir). It brings the actor model -- lightweight concurrent
entities communicating through asynchronous message passing -- to the PHP
ecosystem as a fully typed, composable library.

## The problem

PHP has long been treated as a request-response language. Building concurrent
systems, fault-tolerant services, or distributed computing pipelines typically
means reaching for external queues, cron jobs, and glue code across multiple
infrastructure components. This works, but it spreads concurrency concerns
across operational tooling rather than expressing them in application code.

Nexus addresses this by giving PHP developers a structured concurrency model
where:

- **Concurrent workloads** are expressed as actors that process messages one at
  a time, eliminating shared-state bugs by design.
- **Fault tolerance** is built into the hierarchy: parent actors supervise their
  children and decide how to handle failures (restart, stop, escalate) without
  bringing down the entire system.
- **Distributed computing** becomes possible through location-transparent actor
  references -- the same `ActorRef` interface works whether the actor is local,
  in another process, or on another machine.

## Who Nexus is for

Nexus is designed for PHP teams building:

- **Event-driven systems** -- CQRS/ES architectures, domain event processing,
  and reactive pipelines.
- **Task processing** -- background job execution with supervision, retries, and
  backpressure built in.
- **Real-time applications** -- WebSocket servers, chat systems, live dashboards,
  and notification fanout running on Swoole.
- **Long-running services** -- daemons and workers that must stay up, self-heal,
  and handle partial failures gracefully.

For PHP teams that need concurrent or fault-tolerant behavior without adopting
an entirely new language or platform, Nexus fits that gap.

## Packages

Nexus is organized as a monorepo of focused packages:

| Package | Composer name | Purpose |
|---|---|---|
| **nexus-core** | `nexus-actors/core` | Actors, behaviors, supervision, and the `ActorSystem` entry point. Depends on `nexus-runtime` for shared runtime and mailbox contracts. |
| **nexus-runtime** | `nexus-actors/runtime` | Shared runtime abstractions and concurrency primitives: `Runtime`, `Duration`, `Cancellable`, mailbox contracts, and `Future`/`FutureSlot`. |
| **nexus-runtime-fiber** | `nexus-actors/runtime-fiber` | Fiber-based runtime using PHP 8.1+ native fibers with cooperative scheduling. Ideal for development and testing. |
| **nexus-runtime-swoole** | `nexus-actors/runtime-swoole` | Swoole-based runtime using coroutines and native channels. Designed for production workloads with true parallelism. |
| **nexus-runtime-step** | `nexus-actors/runtime-step` | Deterministic step-by-step runtime for tests. Use `step()` + `VirtualClock` for fully reproducible message and timer behavior. |
| **nexus-cluster** | `nexus-actors/cluster` | Remote contracts for future TCP-based multi-machine clustering. |
| **nexus-worker-pool** | `nexus-actors/worker-pool` | Local thread-based worker pool with consistent-hash routing. |
| **nexus-worker-pool-swoole** | `nexus-actors/worker-pool-swoole` | Swoole thread primitives: `Thread\Queue` transport, `Thread\Map` directory, `Thread\Pool` bootstrap. |
| **nexus-persistence** | `nexus-actors/persistence` | Event sourcing and durable state abstractions. Effects, snapshots, retention policies, concurrency control, and in-memory stores for testing. |
| **nexus-persistence-dbal** | `nexus-actors/persistence-dbal` | Doctrine DBAL storage backends for persistence. SQL-backed event, snapshot, and durable state stores. |
| **nexus-persistence-doctrine** | `nexus-actors/persistence-doctrine` | Doctrine ORM adapter for persistence. Entity-based stores using `EntityManagerInterface`. |
| **nexus-doctrine-dbal** | `nexus-actors/doctrine-dbal` | Coroutine-aware DBAL `ConnectionPool` + HTTP middleware/resolver + `#[Transactional]` + actor-side helpers. General-purpose data access (separate from `persistence-dbal` which is event-sourcing storage). |
| **nexus-doctrine-orm** | `nexus-actors/doctrine-orm` | Pooled `EntityManagerInterface` injection for HTTP handlers + `EntityBehavior` DSL: treat a Doctrine entity as the state of an aggregate actor with single-writer semantics and optional idle-passivation. |
| **nexus-serialization** | `nexus-actors/serialization` | Valinor-based message serialization with a type registry for wire-format encoding and decoding. |
| **nexus-logger** | `nexus-actors/logger` | Async PSR-3 logger backed by a `LogActor` mailbox, with Monolog-compatible handlers and a synchronous fallback for the pre-actor boot window. |
| **nexus-http** | `nexus-actors/http` | PSR-7/15-based HTTP routing core: route registry, handler-param resolution registry, exception mapper, `ResponseInterface` helpers. |
| **nexus-http-ws** | `nexus-actors/http-ws` | `HttpApplication` / `WsApplication` composition root, `CompiledApplication` runtime, WebSocket router, dispatcher, and channel registry. |
| **nexus-http-toolkit** | `nexus-actors/http-toolkit` | Reusable middlewares (rate limit, body size limit, CORS, etc.) and helper resolvers that work with both server runners. |
| **nexus-http-auth** | `nexus-actors/http-auth` | Bearer-token authentication middleware + `Principal` + `#[FromPrincipal]` param resolver. |
| **nexus-http-server-swoole** | `nexus-actors/http-server-swoole` | Swoole HTTP server runner (worker-process mode). Bridges PSR-7 requests to the compiled application; installs graceful-shutdown hooks. |
| **nexus-http-server-swoole-threads** | `nexus-actors/http-server-swoole-threads` | Swoole thread-mode HTTP server. Per-thread `ActorSystem`, shared `Thread\Map` directory, `BeforeShutdown` watchdog for clean exits. |
| **nexus-app** | `nexus-actors/app` | Application kernel for declarative actor registration and single-process execution. |
| **nexus-psalm** | `nexus-actors/psalm` | Psalm plugin providing static analysis support for actor message protocols and behavior types. |

A meta-package `nexus-actors/nexus` pulls in `nexus-core`, `nexus-runtime-fiber`,
and `nexus-serialization` for convenience.

## Design principles

- **Immutable behaviors.** Actor message handlers are `readonly` value objects.
  Swapping behavior means returning a new `Behavior` instance, never mutating
  the current one.
- **PSR compatibility.** Nexus integrates with `psr/log`, `psr/clock`,
  `psr/container`, and `psr/event-dispatcher` out of the box.
- **Runtime-agnostic actor APIs.** Actor code depends on interfaces, not a
  concrete runtime implementation. The runtime (Fiber, Swoole, or Step) is
  chosen at the composition root.
- **Type safety.** All public APIs use generics tracked by Psalm. The
  `nexus-psalm` plugin ensures message protocols are consistent at analysis
  time.
- **Explicit nullable types.** Where values are conditionally present, Nexus uses
  nullable types (`?ActorRef`, `?SupervisionStrategy`) enforced by Psalm's nullability
  analysis. There are no optional wrappers or monadic containers in the public API.

## Next steps

- [Nexus Thesis](./core-concepts/nexus-thesis.md) -- decide quickly whether actor architecture is the right fit.
- [Installation](./getting-started/installation.md) -- set up Nexus in your project.
- [Quick Start](./getting-started/quick-start.md) -- build your first actor in five minutes.
- [Key Concepts](./getting-started/concepts.md) -- understand the actor model from a PHP perspective.
