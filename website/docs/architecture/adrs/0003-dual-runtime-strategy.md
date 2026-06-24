---
title: "ADR 0003: Dual Runtime Strategy — Fiber and Swoole"
sidebar_position: 3
related:
  - runtimes/fiber
  - runtimes/swoole
  - architecture/adrs/0001-actor-model-architecture
  - architecture/adrs/0008-worker-pool-cluster-separation
---

# ADR 0003: Dual Runtime Strategy — Fiber and Swoole

## Status

Accepted

## Context

PHP offers two concurrency mechanisms relevant to an actor system:

1. **Fibers** (PHP 8.1+): Cooperative coroutines built into the language. No extensions needed. Single-threaded, cooperative scheduling. Good for development and testing.
2. **Swoole** (extension): Full coroutine runtime with real async I/O, channels, timers, and process management. Production-grade but requires a C extension.

Choosing one runtime limits adoption. Fiber-only means no production performance. Swoole-only means a hard dependency on an extension that not all environments support.

## Decision

Support both runtimes behind a shared `Runtime` interface:

- **`Runtime`** interface defines: `run()`, `spawn()`, `createMailbox()`, `scheduleOnce()`, `scheduleRepeatedly()`, `cancel()`.
- **`FiberRuntime`**: Actors run as PHP Fibers. Mailboxes use `SplQueue`. Timers are cooperative. Ideal for development, testing, and CI.
- **`SwooleRuntime`**: Actors run as Swoole coroutines. Mailboxes use `Swoole\Coroutine\Channel`. Timers use Swoole's native timer. Ideal for production.
- **`StepRuntime`**: Deterministic runtime for testing. Messages are processed one at a time via explicit `tick()` calls. `VirtualClock` for time control.

Actor code is completely runtime-agnostic. The same `Behavior<T>` runs on any runtime.

## Consequences

- `Props`, `Behavior`, and `ActorRef` have no runtime dependency.
- Runtime selection happens at `ActorSystem::create()` time.
- The `Mailbox` interface abstracts the message queue implementation.
- Performance characteristics differ: Swoole handles real concurrency, Fiber is single-threaded.
- The Step runtime enables deterministic, reproducible test scenarios.
- Each runtime ships as a separate package: `nexus-runtime-fiber`, `nexus-runtime-swoole`, `nexus-runtime-step`.
