---
sidebar_position: 1
title: Runtime Overview
---

# Runtime Overview

The `Runtime` interface is the abstraction that decouples actor code from the
underlying concurrency mechanism. All scheduling, mailbox creation, and
fiber/coroutine management flow through this single interface, making actor
behaviors completely portable between runtimes.

## The Runtime interface

```php
namespace Monadial\Nexus\Core\Runtime;

interface Runtime
{
    public function name(): string;

    public function createMailbox(MailboxConfig $config): Mailbox;

    public function spawn(callable $actorLoop): string;

    public function scheduleOnce(Duration $delay, callable $callback): Cancellable;

    public function scheduleRepeatedly(Duration $initialDelay, Duration $interval, callable $callback): Cancellable;

    public function yield(): void;

    public function sleep(Duration $duration): void;

    public function run(): void;

    public function shutdown(Duration $timeout): void;

    public function isRunning(): bool;
}
```

Each method serves a specific role in the actor lifecycle:

- **`name()`** -- Returns a string identifier for the runtime (`'fiber'` or `'swoole'`).
- **`createMailbox()`** -- Creates a runtime-specific `Mailbox` implementation from a `MailboxConfig`.
- **`spawn()`** -- Registers a callable as a new concurrent task (fiber or coroutine) and returns an identifier string.
- **`scheduleOnce()`** -- Schedules a one-shot callback after `$delay`. Returns a `Cancellable` handle.
- **`scheduleRepeatedly()`** -- Schedules a recurring callback with an initial delay and a fixed interval. Returns a `Cancellable` handle.
- **`yield()`** -- Cooperatively yields execution to other tasks.
- **`sleep()`** -- Suspends the current task for the given `Duration`.
- **`run()`** -- Starts the event loop. Blocks until all tasks and timers complete or `shutdown()` is called.
- **`shutdown()`** -- Signals the runtime to stop within the given timeout.
- **`isRunning()`** -- Returns whether the event loop is currently active.

## Two implementations

Nexus ships with two runtime implementations:

| Runtime | Class | Extension required | Concurrency model |
|---|---|---|---|
| Fiber | `FiberRuntime` | None | PHP 8.1+ native Fibers |
| Swoole | `SwooleRuntime` | Swoole 5.0+ | Swoole coroutines |

Both implement the `Runtime` interface identically. Actor code never references
a specific runtime class -- it depends only on the `Runtime` interface and the
core abstractions (`Mailbox`, `Cancellable`, `Duration`).

## Runtime pluggability

The runtime is injected at the composition root when creating an `ActorSystem`:

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

$system = ActorSystem::create('my-system', new FiberRuntime());
```

Switching to Swoole in production requires changing only this one line:

```php
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Runtime\Swoole\SwooleConfig;

$system = ActorSystem::create('my-system', new SwooleRuntime(new SwooleConfig()));
```

All actor behaviors, Props definitions, and supervision strategies remain
identical across runtimes.

## When to use which

**FiberRuntime** is the right choice when:

- You are developing or testing locally and do not want to install extensions.
- Your application handles moderate concurrency (tens to hundreds of actors).
- You are running single-process CLI tools or simple services.
- You need a zero-dependency setup for CI pipelines.

**SwooleRuntime** is the right choice when:

- You are running in production with high-concurrency requirements.
- Your workload involves thousands of concurrent actors or 100K+ connections.
- You need true async I/O via Swoole's coroutine hooking (database, HTTP, filesystem).
- You need [multi-process scaling](../scaling/overview.md) to utilize all CPU cores.
