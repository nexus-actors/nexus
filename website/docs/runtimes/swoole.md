---
sidebar_position: 3
title: Swoole Runtime
---

# Swoole Runtime

`SwooleRuntime` uses Swoole 5.0+ coroutines for concurrent actor execution. It
provides true async I/O, native channel-backed mailboxes, and support for
high-concurrency workloads.

## Setup

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Runtime\Swoole\SwooleConfig;

$runtime = new SwooleRuntime(new SwooleConfig(
    defaultMailboxCapacity: 1000,
    enableCoroutineHook: true,
    maxCoroutines: 100_000,
));

$system = ActorSystem::create('my-system', $runtime);
```

## SwooleConfig

`SwooleConfig` is a `final readonly class` with three parameters, all with
sensible defaults:

```php
final readonly class SwooleConfig
{
    public function __construct(
        public int $defaultMailboxCapacity = 1000,
        public bool $enableCoroutineHook = true,
        public int $maxCoroutines = 100_000,
    ) {}
}
```

| Parameter | Default | Description |
|---|---|---|
| `defaultMailboxCapacity` | `1000` | Default channel capacity for mailboxes. |
| `enableCoroutineHook` | `true` | When true, sets `SWOOLE_HOOK_ALL` to enable coroutine hooking for async I/O. |
| `maxCoroutines` | `100_000` | Maximum number of concurrent coroutines. |

Each parameter has a `with*` method for immutable modification:

```php
$config = new SwooleConfig();
$config = $config->withMaxCoroutines(50_000);
$config = $config->withEnableCoroutineHook(false);
$config = $config->withDefaultMailboxCapacity(5000);
```

## Architecture

### SwooleRuntime

The runtime wraps Swoole's `Co\run()` block. Calls to `spawn()` and
`scheduleOnce()`/`scheduleRepeatedly()` made before `run()` are queued
internally. When `run()` is called:

1. Coroutine hooking is configured if `enableCoroutineHook` is true.
2. `Co\run()` is entered.
3. All pending timers are executed.
4. All pending spawns are started as Swoole coroutines via `Coroutine::create()`.
5. `Co\run()` blocks until all coroutines and timers complete.

If `spawn()` or timer methods are called while already inside `Co\run()`, they
execute immediately.

### SwooleMailbox

`SwooleMailbox` is backed by a `Swoole\Coroutine\Channel`. Messages are pushed
and popped through the channel, with Swoole handling coroutine suspension and
resumption natively.

Key behaviors:

- **`enqueue()`** pushes an `Envelope` onto the channel. Overflow is handled
  according to the configured `OverflowStrategy`.
- **`dequeueBlocking()`** calls `$channel->pop($timeoutSeconds)`, which
  suspends the coroutine until a message is available or the timeout expires.
- **`close()`** drains remaining messages from the channel into an internal
  `SplQueue` backup before closing the channel, ensuring no messages are lost.

### Timers

Timers use Swoole's native timer API:

- **`scheduleOnce()`** calls `Swoole\Timer::after($ms, $callback)`.
- **`scheduleRepeatedly()`** uses `Timer::after()` for the initial delay, then
  `Timer::tick()` for the recurring interval.

Both return a `SwooleCancellable` that wraps the timer ID and calls
`Timer::clear()` on cancellation.

For timers scheduled before `run()`, a `DeferredCancellable` is returned. It
holds a shared boolean reference that prevents the timer from being created
when `run()` processes pending actions.

### Coroutine hooking

When `enableCoroutineHook` is `true` (the default), the runtime sets
`SWOOLE_HOOK_ALL` before entering `Co\run()`. This transparently converts
blocking I/O operations (MySQL, Redis, PDO, file I/O, HTTP clients) into
non-blocking coroutine operations. Actors performing I/O automatically yield
to other coroutines while waiting.

## Use cases

The Swoole runtime is designed for:

- **Production deployments** -- True async I/O and efficient coroutine
  scheduling for high-throughput services.
- **High concurrency** -- Supports 100K+ concurrent connections with minimal
  memory overhead per coroutine.
- **CPU-bound workloads** -- Combined with Swoole's process pool, work can be
  distributed across multiple CPU cores.
- **Real-time applications** -- WebSocket servers, chat systems, and live
  dashboards benefit from Swoole's event-driven architecture.
- **Multi-worker scaling** -- The Swoole runtime powers the worker pool, where
  multiple worker threads coordinate via `Thread\Queue` (one inbox per worker)
  and a shared `Thread\Map` actor directory. See
  [Scaling Overview](../scaling/overview.md).

## Limitations

- Requires the Swoole PHP extension (5.0+).
- Not all PHP libraries are coroutine-aware. Libraries that use blocking I/O
  without going through hooked functions will block the coroutine scheduler.
- Debugging coroutine-based code can be more complex than debugging fiber-based
  code.
