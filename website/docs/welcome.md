---
sidebar_position: 1
title: Introduction
slug: /
related:
  - core-concepts/nexus-thesis
  - getting-started/installation
  - getting-started/quick-start
  - getting-started/concepts
---

# Introduction

:::caution Under active development
Nexus is a pre-1.0 toolkit under active development and not yet production-hardened. The core actor model API is the most settled surface; supervision, persistence, worker-pool, cluster, and WebSocket subsystems are still evolving and APIs may change.
:::

Nexus is an actor system for PHP 8.5+, bringing the actor model — lightweight concurrent entities communicating through asynchronous message passing — to the PHP ecosystem as a fully typed, composable library.

## The problem

PHP has long been treated as a request-response language. Building concurrent systems, fault-tolerant services, or distributed computing pipelines typically means reaching for external queues, cron jobs, and glue code across multiple infrastructure components. This works, but it spreads concurrency concerns across operational tooling rather than expressing them in application code.

Nexus addresses this by giving PHP developers a structured concurrency model where:

- **Concurrent workloads** are expressed as actors that process messages one at a time, eliminating shared-state bugs by design.
- **Fault tolerance** is built into the hierarchy: parent actors supervise their children and decide how to handle failures (restart, stop, escalate) without bringing down the entire system.
- **Distributed computing** becomes possible through location-transparent actor references — the same `ActorRef` interface works whether the actor is local, in another process, or on another machine.

## Who Nexus is for

Nexus is designed for PHP teams building:

- **Event-driven systems** — CQRS/ES architectures, domain event processing, and reactive pipelines.
- **Task processing** — background job execution with supervision, retries, and backpressure built in.
- **Real-time applications** — WebSocket servers, chat systems, live dashboards, and notification fanout running on Swoole.
- **Long-running services** — daemons and workers that must stay up, self-heal, and handle partial failures gracefully.

## Packages

Nexus is organized as a monorepo of focused packages, each published independently to Packagist:

| Package | Composer name | Purpose |
|---|---|---|
| **nexus-core** | `nexus-actors/core` | Actors, behaviors, supervision, and the `ActorSystem` entry point. |
| **nexus-runtime** | `nexus-actors/runtime` | Shared runtime abstractions: `Runtime`, `Duration`, `Cancellable`, mailbox contracts. |
| **nexus-runtime-fiber** | `nexus-actors/runtime-fiber` | Fiber-based runtime. Cooperative scheduling, no extensions required. |
| **nexus-runtime-swoole** | `nexus-actors/runtime-swoole` | Swoole-based runtime using coroutines and native channels. |
| **nexus-runtime-step** | `nexus-actors/runtime-step` | Deterministic step-by-step runtime for tests. |
| **nexus-worker-pool** | `nexus-actors/worker-pool` | Local thread-based worker pool with consistent-hash routing. |
| **nexus-worker-pool-swoole** | `nexus-actors/worker-pool-swoole` | Swoole thread primitives: `Thread\Queue` transport, `Thread\Map` directory. |
| **nexus-cluster** | `nexus-actors/cluster` | Remote contracts for future TCP-based multi-machine clustering. |
| **nexus-persistence** | `nexus-actors/persistence` | Event sourcing and durable state abstractions with in-memory stores. |
| **nexus-persistence-dbal** | `nexus-actors/persistence-dbal` | Doctrine DBAL storage backends for persistence. |
| **nexus-persistence-doctrine** | `nexus-actors/persistence-doctrine` | Doctrine ORM adapter for persistence. |
| **nexus-doctrine-dbal** | `nexus-actors/doctrine-dbal` | Coroutine-aware DBAL `ConnectionPool` + HTTP middleware + `#[Transactional]`. |
| **nexus-doctrine-orm** | `nexus-actors/doctrine-orm` | Pooled `EntityManagerInterface` injection + `EntityBehavior` DSL for aggregate actors. |
| **nexus-serialization** | `nexus-actors/serialization` | Valinor-based message serialization with a type registry. |
| **nexus-logger** | `nexus-actors/logger` | Async PSR-3 logger backed by a `LogActor` mailbox with Monolog-compatible handlers. |
| **nexus-http** | `nexus-actors/http` | PSR-7/15-based HTTP routing core: route registry, handler-param resolution, exception mapper. |
| **nexus-http-ws** | `nexus-actors/http-ws` | `HttpApplication` / `WsApplication` composition root and WebSocket router. |
| **nexus-http-toolkit** | `nexus-actors/http-toolkit` | Reusable middlewares (rate limit, body size limit, CORS) and helper resolvers. |
| **nexus-http-auth** | `nexus-actors/http-auth` | Bearer-token authentication middleware + `Principal` + `#[FromPrincipal]` resolver. |
| **nexus-http-server-swoole** | `nexus-actors/http-server-swoole` | Swoole HTTP server runner (worker-process mode). |
| **nexus-http-server-swoole-threads** | `nexus-actors/http-server-swoole-threads` | Swoole thread-mode HTTP server with graceful-shutdown hooks. |
| **nexus-app** | `nexus-actors/app` | Application kernel for declarative actor registration and single-process execution. |
| **nexus-psalm** | `nexus-actors/psalm` | Psalm plugin providing static analysis support for actor message protocols. |

A meta-package `nexus-actors/nexus` pulls in `nexus-core`, `nexus-runtime-fiber`, and `nexus-serialization` for convenience.

## Design principles

- **Immutable behaviors.** Actor message handlers are `readonly` value objects. Swapping behavior means returning a new `Behavior` instance, never mutating the current one.
- **PSR compatibility.** Nexus integrates with `psr/log`, `psr/clock`, `psr/container`, and `psr/event-dispatcher` out of the box.
- **Runtime-agnostic actor APIs.** Actor code depends on interfaces, not a concrete runtime implementation. The runtime (Fiber, Swoole, or Step) is chosen at the composition root.
- **Type safety.** All public APIs use generics tracked by Psalm. The `nexus-psalm` plugin ensures message protocols are consistent at analysis time.

## Next steps

- [Nexus Thesis](./core-concepts/nexus-thesis.md) — decide quickly whether actor architecture is the right fit.
- [Installation](./getting-started/installation.md) — set up Nexus in your project.
- [Quick Start](./getting-started/quick-start.md) — build your first actor in five minutes.
- [Key Concepts](./getting-started/concepts.md) — understand the actor model from a PHP perspective.
