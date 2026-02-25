---
sidebar_position: 4
title: nexus-runtime-step
---

# nexus-runtime-step

Deterministic runtime for testing. Manual message stepping, virtual time, and
guaranteed ordering.

**Composer:** `nexus-actors/runtime-step`

**Namespace:** `Monadial\Nexus\Runtime\Step\`

## Classes

### StepRuntime

Implements `Monadial\Nexus\Runtime\Runtime\Runtime`.

The testing runtime. Uses PHP Fibers internally but replaces the automatic tick
loop with manual control. Each `step()` call processes exactly one message.

```php
use Monadial\Nexus\Runtime\Step\StepRuntime;

$runtime = new StepRuntime();
```

Optionally accepts a `VirtualClock`:

```php
$clock = new VirtualClock(new DateTimeImmutable('2025-01-01T00:00:00+00:00'));
$runtime = new StepRuntime($clock);
```

**Key methods (from Runtime interface):**

- `name(): string` -- Returns `'step'`.
- `createMailbox(MailboxConfig): Mailbox` -- Returns a new `StepMailbox`.
- `spawn(callable): string` -- Creates a `Fiber` from the callable and returns an ID like `'step-0'`.
- `scheduleOnce(Duration, callable): Cancellable` -- Stores timer with virtual fire time. Returns `StepCancellable`.
- `scheduleRepeatedly(Duration, Duration, callable): Cancellable` -- Stores repeating timer with virtual fire time.
- `yield(): void` -- No-op.
- `sleep(Duration): void` -- No-op.
- `run(): void` -- Calls `drain()` (processes all pending messages).
- `shutdown(Duration): void` -- Closes all mailboxes and cleans up fibers.
- `isRunning(): bool` -- Returns whether `run()` is currently executing.

**Step API:**

- `step(): bool` -- Process one message. Returns `false` if idle.
- `drain(): void` -- Process all pending messages.
- `advanceTime(Duration): void` -- Advance virtual clock and fire due timers.

**Inspection:**

- `clock(): VirtualClock` -- Returns the virtual clock instance.
- `pendingMessageCount(): int` -- Total unprocessed messages across all mailboxes.
- `isIdle(): bool` -- Whether any actor has work to do.

### StepMailbox

Implements `Monadial\Nexus\Core\Mailbox\Mailbox`.

`SplQueue`-backed mailbox that always suspends the fiber in
`dequeueBlocking()`, even when messages are available. This guarantees each
message requires an explicit `step()` call to be processed.

Exposes `hasWaitingFiber()` and `getWaitingFiber()` for the runtime to query
which mailboxes have suspended actors ready to resume.

Supports all `OverflowStrategy` modes for bounded mailboxes.

### VirtualClock

Implements `Psr\Clock\ClockInterface`.

Deterministic clock for testing. Starts at `2026-01-01T00:00:00+00:00` by
default.

```php
$clock = new VirtualClock();
$clock->now();                          // 2026-01-01T00:00:00+00:00
$clock->advance(Duration::seconds(5));  // move forward 5 seconds
$clock->set(new DateTimeImmutable(...));// jump to a specific time
```

### StepCancellable

Implements `Monadial\Nexus\Core\Actor\Cancellable`.

Simple boolean-flag cancellable. Calling `cancel()` sets the flag; timers check
it before firing and skip cancelled entries.
