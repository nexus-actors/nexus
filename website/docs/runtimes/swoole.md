---
sidebar_position: 4
title: Swoole Runtime
related:
  - runtimes/overview
  - runtimes/fiber
  - scaling/overview
  - runtimes/bootstrap
---

# Swoole Runtime

`SwooleRuntime` implements the `Runtime` interface using Swoole 6.2.1+ coroutines, providing true async I/O, native channel-backed mailboxes, and the concurrency headroom needed for production workloads.

## The design

The runtime wraps Swoole's `Co\run()` block. Calls to `spawn()` and the scheduling methods made before `run()` are queued internally. When `run()` is called:

1. Coroutine hooking is configured if `enableCoroutineHook` is `true`.
2. `Co\run()` is entered.
3. Pending timers are registered.
4. Pending spawns are started as Swoole coroutines via `Coroutine::create()`.
5. `Co\run()` blocks until all coroutines and timers complete.

Calls to `spawn()` or timer methods made while already inside `Co\run()` execute immediately — no queuing needed.

**`SwooleMailbox`** is backed by a `Swoole\Coroutine\Channel`. `enqueue()` pushes an `Envelope` onto the channel; overflow is handled by the configured `OverflowStrategy`. `dequeueBlocking()` calls `$channel->pop($timeoutSeconds)`, which suspends the coroutine until a message arrives or the timeout expires. `close()` drains remaining messages into an internal `SplQueue` before closing the channel, so no messages are lost.

**Timers** use Swoole's native timer API. `scheduleOnce()` calls `Swoole\Timer::after($ms, $callback)`. `scheduleRepeatedly()` uses `Timer::after()` for the initial delay, then `Timer::tick()` for the recurring interval. Both return a `SwooleCancellable` that calls `Timer::clear()` on cancellation. Timers scheduled before `run()` return a `DeferredCancellable` that prevents the timer from being created if cancelled before `run()` processes the pending queue.

**Coroutine hooking** — when `enableCoroutineHook` is `true` (the default), the runtime sets `SWOOLE_HOOK_ALL` before entering `Co\run()`. This transparently converts blocking I/O calls (MySQL, Redis, PDO, file I/O, HTTP clients) into non-blocking coroutine operations. Actors performing I/O automatically yield to other coroutines while waiting.

**`yield()`** resolves to `Coroutine::sleep(0)` rather than `Coroutine::yield()`. This matters for drain loops: the suspending coroutine resumes on the next scheduler tick, giving actor message loops time to observe a closed mailbox and exit cleanly.

## Setup

```php title="src/bootstrap-swoole.php"
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Swoole\SwooleConfig;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;

$runtime = new SwooleRuntime(new SwooleConfig(
    defaultMailboxCapacity: 1000,
    enableCoroutineHook: true,
    maxCoroutines: 100_000,
));

$system = ActorSystem::create('my-system', $runtime);
$system->run();
```

## Configuration

`SwooleConfig` is a `final readonly class` with three parameters, all with sensible defaults:

| Parameter | Default | Description |
|---|---|---|
| `defaultMailboxCapacity` | `1000` | Default channel capacity for bounded mailboxes. |
| `enableCoroutineHook` | `true` | Sets `SWOOLE_HOOK_ALL` for transparent async I/O conversion. |
| `maxCoroutines` | `100_000` | Maximum concurrent coroutines. |

Each parameter has a `with*` builder method for immutable modification:

```php title="src/config.php"
$config = (new SwooleConfig())
    ->withMaxCoroutines(50_000)
    ->withDefaultMailboxCapacity(5000)
    ->withEnableCoroutineHook(false);
```

## Graceful shutdown in thread mode

`SwooleThreadServer` (in `nexus-http-server-swoole-threads`) installs a `BeforeShutdown` listener so SIGTERM/SIGINT triggers a deterministic teardown:

1. SIGTERM reaches the main thread. Swoole invokes the `BeforeShutdown` event.
2. The listener flips a shared `Swoole\Thread\Atomic` flag.
3. Each worker thread runs a watchdog coroutine spawned during `WorkerStart`. The watchdog polls the atomic every 50 ms.
4. When the flag flips, the watchdog calls `ThreadQueueTransport::stop()` (exiting the receive loop on its next backoff tick) and then `ActorSystem::shutdown(timeout)` (broadcasting `PoisonPill`, draining under deadline, and force-closing survivors).
5. Worker threads exit cooperatively before Swoole's reactor-exit timeout.

This wiring is necessary because Swoole's per-worker `WorkerStop` event fires after the reactor exit timeout in thread mode — too late to close mailboxes before the deadlock detector flags blocked coroutines.

## Tradeoffs

Swoole provides true async I/O: blocking PHP calls become non-blocking through coroutine hooking, so actor handlers that query a database or make HTTP requests no longer stall other actors. The coroutine scheduler is preemptive at I/O boundaries, which means the single-threaded blocking problem of `FiberRuntime` does not apply to I/O-heavy workloads.

The cost is a required extension. Not all PHP libraries are coroutine-aware — code that uses blocking I/O without going through hooked functions will still block the scheduler. Debugging coroutine-based code is also more involved than debugging fibers.

## When to reach for it

- Deploying a service with high-concurrency requirements (thousands of concurrent actors or connections).
- Building WebSocket servers, chat systems, or live dashboards that benefit from Swoole's event-driven architecture.
- Running CPU-bound workloads distributed across multiple CPU cores via the [worker pool](../scaling/overview.md).
- Any production workload where async I/O throughput is a requirement.

## See also

- [Fiber Runtime](./fiber.md) — cooperative scheduling without Swoole, for development and CI
- [Scaling Overview](../scaling/overview.md) — multi-worker pools powered by Swoole threads
- [Runtime Overview](./overview.md) — choosing between all three runtimes
