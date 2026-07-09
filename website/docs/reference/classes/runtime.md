---
title: Runtime
sidebar_position: 13
related:
  - runtimes/overview
  - reference/classes/actor-system
  - reference/classes/mailbox-config
---

# Runtime

The concurrency backend abstraction — swap between Fiber, Swoole, and Step (test) without changing actor code.

## What it does

`Runtime` is the interface that decouples actor code from its concurrency model. `ActorSystem` takes a `Runtime` at construction time and delegates all scheduling, mailbox creation, fiber/coroutine spawning, and timer management to it. Because actors only ever call methods on `ActorContext` — never on the runtime directly — the same business logic runs unchanged across all three built-in implementations.

**`FiberRuntime`** runs each actor in its own PHP Fiber. Fibers suspend cooperatively when waiting for mailbox messages, which makes the runtime lightweight and easy to debug locally. This is the default for development and for environments without Swoole.

**`SwooleRuntime`** runs actors as Swoole coroutines and uses Swoole channels for mailboxes, giving access to true async I/O, multi-process scaling, and the Swoole event loop. Use this in production.

**`StepRuntime`** is a deterministic test runtime. Calling `step()` processes exactly one pending message; `drain()` processes all queued messages. No wall-clock timers fire unless explicitly triggered. Use it in unit tests to control execution order precisely.

You only implement `Runtime` directly when integrating a new concurrency backend. Otherwise, instantiate one of the three provided implementations and pass it to `ActorSystem::create()`.

## Example

```php title="src/bootstrap.php"
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Duration;

// Development / testing: Fiber runtime
$runtime = new FiberRuntime();
$system  = ActorSystem::create('my-app', $runtime);

$ref = $system->spawn(Props::fromBehavior($behavior), 'worker');
$ref->tell(new DoWork('task-1'));

$runtime->scheduleOnce(
    Duration::seconds(5),
    fn () => $system->shutdown(Duration::seconds(1)),
);

$system->run(); // blocks until shutdown

// Production: Swoole runtime (identical actor code)
$runtime = new SwooleRuntime();
$system  = ActorSystem::create('my-app', $runtime);
```

## Key methods

- `name(): string` — human-readable runtime identifier used in log output and diagnostics.
- `createMailbox(MailboxConfig $config): Mailbox` — allocate a new mailbox backed by the runtime's native queue (SplQueue for Fiber, Swoole channel for Swoole).
- `spawn(callable $actorLoop): string` — launch a Fiber or coroutine to run the actor's message-processing loop; returns an opaque spawn ID.
- `defer(callable $task): void` — hand a one-shot task to the runtime to run asynchronously as soon as possible; fire-and-forget (no ID, no delay). Use it instead of `spawn()` when you don't need to track the work, or instead of `scheduleOnce()` with a zero delay.
- `scheduleOnce(Duration $delay, callable $callback): Cancellable` — fire a callback once after `$delay`; returns a handle to cancel it.
- `scheduleRepeatedly(Duration $initialDelay, Duration $interval, callable $callback): Cancellable` — fire a callback on a fixed interval until cancelled.
- `yield(): void` — voluntarily yield control to the scheduler (important in tight processing loops).
- `sleep(Duration $duration): void` — suspend the current fiber/coroutine for the given duration without blocking the event loop.
- `run(): void` — start the event loop; blocks until `shutdown()` is called.
- `shutdown(Duration $timeout): void` — initiate orderly shutdown; waits up to `$timeout` for in-flight messages to drain.
- `isRunning(): bool` — `true` after `run()` has been entered and before `shutdown()` completes.

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Runtime-Runtime-Runtime.html)

## See also

- [Runtimes overview](../../runtimes/overview) — comparison of Fiber, Swoole, and Step runtimes with guidance on when to use each
- [ActorSystem](actor-system) — the entry point that accepts a `Runtime` instance
- [MailboxConfig](mailbox-config) — passed to `createMailbox()` to control capacity and overflow behaviour
