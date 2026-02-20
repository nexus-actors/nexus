---
sidebar_position: 3
title: nexus-runtime-swoole
---

# nexus-runtime-swoole

Swoole-based runtime using Swoole 5.0+ coroutines and native channels.
Requires the Swoole PHP extension.

**Composer:** `nexus-actors/runtime-swoole`

**Namespace:** `Monadial\Nexus\Runtime\Swoole\`

## Classes

### SwooleRuntime

Implements `Monadial\Nexus\Core\Runtime\Runtime`.

The production runtime. Wraps Swoole's `Co\run()` block and uses
`Swoole\Coroutine::create()` for spawning actors. Calls made before `run()`
are queued and executed when the coroutine context is entered.

```php
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Runtime\Swoole\SwooleConfig;

$runtime = new SwooleRuntime(new SwooleConfig(maxCoroutines: 50_000));
```

**Key methods (from Runtime interface):**

- `name(): string` -- Returns `'swoole'`.
- `createMailbox(MailboxConfig): Mailbox` -- Returns a new `SwooleMailbox`.
- `spawn(callable): string` -- Creates a Swoole coroutine (or queues it if called before `run()`). Returns an ID like `'swoole-0'`.
- `scheduleOnce(Duration, callable): Cancellable` -- Uses `Swoole\Timer::after()`. Returns `SwooleCancellable` or `DeferredCancellable`.
- `scheduleRepeatedly(Duration, Duration, callable): Cancellable` -- Uses `Timer::after()` for the initial delay, then `Timer::tick()` for repeating.
- `yield(): void` -- Calls `Coroutine::yield()`.
- `sleep(Duration): void` -- Calls `Coroutine::sleep()`.
- `run(): void` -- Enters `Co\run()`, which blocks until all coroutines and timers complete.
- `shutdown(Duration): void` -- Clears all tracked timers so `Co\run()` can exit.
- `isRunning(): bool` -- Returns whether the runtime is inside `Co\run()`.

### SwooleConfig

Immutable configuration for the Swoole runtime.

```php
final readonly class SwooleConfig
{
    public function __construct(
        public int $defaultMailboxCapacity = 1000,
        public bool $enableCoroutineHook = true,
        public int $maxCoroutines = 100_000,
    ) {}

    public function withDefaultMailboxCapacity(int $capacity): self;
    public function withEnableCoroutineHook(bool $enable): self;
    public function withMaxCoroutines(int $max): self;
}
```

### SwooleMailbox

Implements `Monadial\Nexus\Core\Mailbox\Mailbox`.

Backed by a `Swoole\Coroutine\Channel`. Coroutine suspension and resumption
for blocking dequeue are handled natively by Swoole. On `close()`, remaining
messages are drained from the channel into an internal `SplQueue` so they can
still be consumed after the channel is closed.

Supports all `OverflowStrategy` modes for bounded mailboxes.

### SwooleCancellable

Implements `Monadial\Nexus\Core\Actor\Cancellable`.

Wraps a Swoole timer ID. Calling `cancel()` invokes `Swoole\Timer::clear()`
on the underlying timer.

### DeferredCancellable

Implements `Monadial\Nexus\Core\Actor\Cancellable`.

Used for timers scheduled before `Co\run()` starts. Holds a shared boolean
reference. When `cancel()` is called, the flag prevents the timer from being
created when `run()` processes pending actions. This is an internal class.
