---
sidebar_position: 2
title: nexus-runtime-fiber
---

# nexus-runtime-fiber

Fiber-based runtime using PHP 8.1+ native Fibers for cooperative multitasking.
No extensions required.

**Composer:** `nexus-actors/runtime-fiber`

**Namespace:** `Monadial\Nexus\Runtime\Fiber\`

## Classes

### FiberRuntime

Implements `Monadial\Nexus\Runtime\Runtime\Runtime`.

The main runtime class. Manages a map of `Fiber` instances and a
`FiberScheduler` for timer management. The `run()` method enters a tick loop
that starts/resumes fibers and advances timers until all work is complete.

```php
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

$runtime = new FiberRuntime();
```

**Key methods (from Runtime interface):**

- `name(): string` -- Returns `'fiber'`.
- `createMailbox(MailboxConfig): Mailbox` -- Returns a new `FiberMailbox`.
- `spawn(callable): string` -- Creates a `Fiber` from the callable and returns an ID like `'fiber-0'`.
- `scheduleOnce(Duration, callable): Cancellable` -- Delegates to `FiberScheduler`.
- `scheduleRepeatedly(Duration, Duration, callable): Cancellable` -- Delegates to `FiberScheduler`.
- `yield(): void` -- Calls `Fiber::suspend('yield')` if inside a fiber.
- `sleep(Duration): void` -- Calls `usleep()`.
- `run(): void` -- Starts the tick loop.
- `shutdown(Duration): void` -- Signals the loop to exit after all fibers complete.
- `isRunning(): bool` -- Returns whether the tick loop is active.

### FiberMailbox

Implements `Monadial\Nexus\Core\Mailbox\Mailbox`.

Array-backed (`SplQueue`) mailbox with blocking dequeue via fiber suspension.
When `dequeueBlocking()` is called on an empty mailbox, the current fiber
suspends itself. It is resumed when a new message is enqueued.

Supports all `OverflowStrategy` modes for bounded mailboxes.

### FiberScheduler

Internal timer manager. Maintains a sorted list of `TimerEntry` objects.
Provides:

- `scheduleOnce(Duration, Closure, DateTimeImmutable): Cancellable`
- `scheduleRepeatedly(Duration, Duration, Closure, DateTimeImmutable): Cancellable`
- `advanceTimers(DateTimeImmutable): void`
- `hasPendingTimers(): bool`

### FiberCancellable

Implements `Monadial\Nexus\Core\Actor\Cancellable`.

Simple boolean-flag cancellable. Calling `cancel()` sets the flag; the
scheduler checks it on the next `advanceTimers()` pass and skips cancelled
entries.

### TimerEntry

Internal `readonly` value object holding a timer's callback, fire time,
repeat flag, interval, and `FiberCancellable` reference.
