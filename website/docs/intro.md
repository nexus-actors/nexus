---
sidebar_position: 1
title: Introduction
---

# Introduction

Nexus is a production-grade actor system for PHP 8.5+, drawing inspiration from
Akka (JVM) and OTP (Erlang/Elixir). It brings the actor model -- lightweight
concurrent entities communicating through asynchronous message passing -- to the
PHP ecosystem for the first time as a fully typed, composable library.

## The problem

PHP has long been treated as a request-response language. Building concurrent
systems, fault-tolerant services, or distributed computing pipelines typically
means reaching for external queues, cron jobs, and glue code across multiple
infrastructure components. This works, but it spreads concurrency concerns
across operational tooling rather than expressing them in your application code.

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

If your team already writes PHP and needs concurrent or fault-tolerant behavior
without adopting an entirely new language or platform, Nexus fits that gap.

## Packages

Nexus is organized as a monorepo of focused packages:

| Package | Composer name | Purpose |
|---|---|---|
| **nexus-core** | `monadial/nexus-core` | Actors, behaviors, supervision, mailboxes, and the `ActorSystem` entry point. Pure abstractions with no runtime dependency. |
| **nexus-runtime-fiber** | `monadial/nexus-runtime-fiber` | Fiber-based runtime using PHP 8.1+ native fibers with cooperative scheduling. Ideal for development and testing. |
| **nexus-runtime-swoole** | `monadial/nexus-runtime-swoole` | Swoole-based runtime using coroutines and native channels. Designed for production workloads with true parallelism. |
| **nexus-cluster** | `monadial/nexus-cluster` | Pure PHP clustering abstractions: consistent hash ring, transport and directory interfaces, `RemoteActorRef`, `ClusterNode`. |
| **nexus-cluster-swoole** | `monadial/nexus-cluster-swoole` | Swoole clustering: `UnixSocketTransport`, `SwooleTableDirectory`, `ClusterBootstrap` with `Process\Pool`. |
| **nexus-serialization** | `monadial/nexus-serialization` | Valinor-based message serialization with a type registry for wire-format encoding and decoding. |
| **nexus-app** | `monadial/nexus-app` | Application kernel for declarative actor registration and single-process execution. |
| **nexus-psalm** | `monadial/nexus-psalm` | Psalm plugin providing static analysis support for actor message protocols and behavior types. |

A meta-package `monadial/nexus` pulls in `nexus-core`, `nexus-runtime-fiber`,
and `nexus-serialization` for convenience.

## Design principles

- **Immutable behaviors.** Actor message handlers are `readonly` value objects.
  Swapping behavior means returning a new `Behavior` instance, never mutating
  the current one.
- **PSR compatibility.** Nexus integrates with `psr/log`, `psr/clock`,
  `psr/container`, and `psr/event-dispatcher` out of the box.
- **Runtime-agnostic core.** The `nexus-core` package contains zero runtime
  coupling. You choose the runtime (Fiber or Swoole) at the composition root.
- **Type safety.** All public APIs use generics tracked by Psalm. The
  `nexus-psalm` plugin ensures your message protocols are consistent at analysis
  time.

## Next steps

- [Installation](./getting-started/installation.md) -- set up Nexus in your project.
- [Quick Start](./getting-started/quick-start.md) -- build your first actor in five minutes.
- [Key Concepts](./getting-started/concepts.md) -- understand the actor model from a PHP perspective.
