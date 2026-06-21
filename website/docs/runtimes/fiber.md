---
sidebar_position: 3
title: Fiber Runtime
related:
  - runtimes/overview
  - runtimes/swoole
  - runtimes/step
  - runtimes/bootstrap
---

# Fiber Runtime

`FiberRuntime` implements the `Runtime` interface using PHP 8.1+ native fibers — no extensions required, no configuration needed, and actor behaviors run identically to their Swoole counterparts.

## The design

The runtime maintains a map of PHP `Fiber` instances keyed by string ID. Each actor runs in its own fiber, spawned when `spawn()` is called. The `run()` method enters a tick loop that:

1. Iterates all fibers — starting unstarted ones and resuming suspended ones.
2. Advances timers via `FiberScheduler::advanceTimers()`.
3. Removes terminated fibers.
4. Exits when no fibers or pending timers remain, or when `shutdown()` has been requested and all fibers have completed.

Between ticks the loop sleeps 100 microseconds to avoid busy-spinning, unless a mailbox enqueue has signaled a wakeup.

**`FiberScheduler`** manages one-shot and repeating timers as a sorted list of `TimerEntry` objects. Each entry holds a callback, a fire time, an optional repeat interval, and a `FiberCancellable` handle. On each `advanceTimers()` call, entries whose fire time has passed are fired, repeating entries are rescheduled, and cancelled entries are discarded.

**`FiberMailbox`** is an `SplQueue`-backed mailbox that implements blocking dequeue via fiber suspension:

- `enqueue(Envelope)` pushes onto the queue and resumes any waiting fiber.
- `dequeueBlocking()` suspends the actor's fiber with `Fiber::suspend('mailbox_wait')` when the queue is empty. The fiber is registered as a waiter. On the next tick, when a message arrives, the runtime resumes it.

This means actors consume no CPU while waiting for messages — they suspend themselves and the tick loop moves on.

## Setup

`FiberRuntime` takes no constructor arguments.

```php title="src/bootstrap.php"
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

$runtime = new FiberRuntime();
$system = ActorSystem::create('my-system', $runtime);
```

## Cooperative scheduling

All concurrency in the Fiber runtime is cooperative. Actors yield at two points:

- **`dequeueBlocking()`** — when the mailbox is empty, the actor's fiber suspends until a message arrives.
- **`yield()`** — explicit yield via `$runtime->yield()`, which calls `Fiber::suspend('yield')`.

Because scheduling is cooperative, an actor performing a long computation without yielding blocks all other actors until it finishes. For CPU-intensive work, either break computation into smaller chunks separated by explicit yields, or switch to `SwooleRuntime` where coroutine hooking makes I/O non-blocking.

## Tradeoffs

The Fiber runtime's chief advantage is zero dependencies — it works on any PHP 8.1+ installation. CI pipelines run without a Swoole-enabled container. Debugging is familiar: fiber stack traces read like synchronous code.

The tradeoff is single-threaded execution. All fibers share one OS thread, so blocking I/O anywhere in an actor handler stalls every other actor. Timer resolution is bounded by the tick interval (approximately 100 microseconds under low load, higher under contention).

## When to reach for it

- Developing locally without Swoole installed.
- Running single-process CLI tools, queue consumers, or simple services where moderate concurrency is sufficient.
- Executing CI pipelines that need actor tests without extension requirements.
- Prototyping actor designs before deploying to the Swoole runtime.

## See also

- [Swoole Runtime](./swoole.md) — true async I/O, coroutine hooking, production configuration
- [Step Runtime](./step.md) — deterministic testing with virtual clock and manual stepping
- [Runtime Overview](./overview.md) — choosing between all three runtimes
