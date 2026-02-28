---
sidebar_position: 1
title: Runtime overview
---

# Runtime overview

Start here for setup: [Bootstrap runtime](./bootstrap.md).

The `Runtime` interface is the abstraction that decouples actor code from the
underlying concurrency mechanism. All scheduling, mailbox creation, and
fiber/coroutine management flow through this single interface, making actor
behaviors completely portable between runtimes.

## The Runtime interface

```php
namespace Monadial\Nexus\Runtime\Runtime;

interface Runtime
{
    public function name(): string;

    public function createMailbox(MailboxConfig $config): Mailbox;

    public function createFutureSlot(): FutureSlot;

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

- **`name()`** -- Returns a string identifier for the runtime (`'fiber'`, `'swoole'`, or `'step'`).
- **`createMailbox()`** -- Creates a runtime-specific `Mailbox` implementation from a `MailboxConfig`.
- **`createFutureSlot()`** -- Creates a runtime-specific slot implementation for `Future`.
- **`spawn()`** -- Registers a callable as a new concurrent task (fiber or coroutine) and returns an identifier string.
- **`scheduleOnce()`** -- Schedules a one-shot callback after `$delay`. Returns a `Cancellable` handle.
- **`scheduleRepeatedly()`** -- Schedules a recurring callback with an initial delay and a fixed interval. Returns a `Cancellable` handle.
- **`yield()`** -- Cooperatively yields execution to other tasks.
- **`sleep()`** -- Suspends the current task for the given `Duration`.
- **`run()`** -- Starts the event loop. Blocks until all tasks and timers complete or `shutdown()` is called.
- **`shutdown()`** -- Signals the runtime to stop within the given timeout.
- **`isRunning()`** -- Returns whether the event loop is currently active.

## Three implementations

Nexus ships with three runtime implementations:

| Runtime | Class | Extension required | Concurrency model | Use case |
|---|---|---|---|---|
| Fiber | `FiberRuntime` | None | PHP 8.1+ native Fibers | Development |
| Swoole | `SwooleRuntime` | Swoole 5.0+ | Swoole coroutines | Production |
| Step | `StepRuntime` | None | Manual stepping (Fibers internally) | Testing |

All three implement the `Runtime` interface identically. Actor code never
references a specific runtime class -- it depends only on the `Runtime`
interface and runtime abstractions (`Mailbox`, `Cancellable`, `Duration`).

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

For deterministic testing, use the Step runtime with its virtual clock:

```php
use Monadial\Nexus\Runtime\Step\StepRuntime;

$runtime = new StepRuntime();
$system = ActorSystem::create('test-system', $runtime, clock: $runtime->clock());
```

All actor behaviors, Props definitions, and supervision strategies remain
identical across runtimes.

## When to use which

**StepRuntime** is the right choice when:

- Writing unit or integration tests for actor behavior.
- Deterministic, reproducible message processing order is required.
- Time control is needed (advance virtual clock, trigger timers on demand).
- State verification is needed after each individual message.

**FiberRuntime** is the right choice when:

- Developing locally without installing extensions.
- The application handles moderate concurrency (tens to hundreds of actors).
- Running single-process CLI tools or simple services.
- A zero-dependency setup for CI pipelines is preferred.

**SwooleRuntime** is the right choice when:

- Running in production with high-concurrency requirements.
- The workload involves thousands of concurrent actors or 100K+ connections.
- True async I/O via Swoole's coroutine hooking is needed (database, HTTP, filesystem).
- [Multi-process scaling](../scaling/overview.md) across all CPU cores is required.
