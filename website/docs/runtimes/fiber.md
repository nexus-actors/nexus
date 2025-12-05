---
sidebar_position: 2
title: Fiber Runtime
---

# Fiber Runtime

`FiberRuntime` is the default runtime for Nexus. It uses PHP 8.1+ native Fibers
for cooperative multitasking, requiring no extensions beyond a standard PHP
installation.

## Setup

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

$runtime = new FiberRuntime();
$system = ActorSystem::create('my-system', $runtime);
```

No configuration is needed. The constructor creates an internal `FiberScheduler`
for timer management.

## Architecture

### FiberRuntime

The runtime maintains a map of PHP `Fiber` instances keyed by ID. When
`spawn()` is called, a new `Fiber` is created from the provided callable and
stored for later execution. The `run()` method enters a tick loop that:

1. Iterates all fibers -- starting unstarted ones and resuming suspended ones.
2. Advances timers via `FiberScheduler::advanceTimers()`.
3. Removes terminated fibers.
4. Exits when no fibers or pending timers remain, or when `shutdown()` has been
   requested and all fibers have completed.

Between ticks, the loop sleeps for 100 microseconds to avoid busy spinning,
unless a mailbox enqueue has signaled a wakeup.

### FiberScheduler

`FiberScheduler` manages one-shot and repeating timers as a sorted list of
`TimerEntry` objects. Each entry holds a callback, a `DateTimeImmutable` fire
time, an optional repeat interval, and a `FiberCancellable` handle.

On each `advanceTimers()` call, the scheduler fires all entries whose fire time
has passed, reschedules repeating entries, and discards cancelled ones. Entries
are inserted in sorted order to avoid full re-sorts.

### FiberMailbox

`FiberMailbox` is an `SplQueue`-backed mailbox that implements blocking dequeue
via fiber suspension.

```
enqueue(Envelope) --> SplQueue --> resumeWaiter()
dequeueBlocking() --> while (empty) { Fiber::suspend('mailbox_wait') }
```

When `dequeueBlocking()` is called on an empty mailbox from within a fiber, the
fiber suspends itself with `Fiber::suspend('mailbox_wait')`. The fiber is
registered as a waiter. When a new message is enqueued, the mailbox signals
the waiter, and the runtime's next tick resumes the fiber.

This approach means actors naturally yield when idle -- they consume no CPU time
while waiting for messages.

## Cooperative scheduling

All concurrency in the Fiber runtime is cooperative. Actors yield at two points:

1. **`dequeueBlocking()`** -- When the mailbox is empty, the actor's fiber
   suspends until a message arrives.
2. **`yield()`** -- Explicit yield via `$runtime->yield()`, which calls
   `Fiber::suspend('yield')`.

Because scheduling is cooperative, a single actor that performs a long
computation without yielding will block all other actors until it finishes.
For CPU-intensive work, consider breaking computation into chunks or switching
to the Swoole runtime.

## Use cases

The Fiber runtime is well-suited for:

- **Development and testing** -- Zero-dependency setup. Works on any PHP 8.1+
  installation without extensions.
- **Single-process applications** -- CLI tools, queue consumers, and simple
  services where moderate concurrency is sufficient.
- **CI pipelines** -- Tests run without requiring a Swoole-enabled container.
- **Prototyping** -- Quick iteration on actor designs before deploying to Swoole.

## Limitations

- All fibers run in a single thread. There is no true parallelism.
- I/O operations (database queries, HTTP requests) block the entire event loop
  unless they are non-blocking.
- Timer resolution is limited to the tick interval (approximately 100
  microseconds under low load).
