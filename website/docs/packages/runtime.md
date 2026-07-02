---
sidebar_position: 2
title: nexus-runtime
related:
  - packages/runtime-fiber
  - packages/runtime-swoole
  - packages/runtime-step
  - packages/core
---

# nexus-runtime

Runtime abstractions and async primitives shared by all Nexus packages: the `Runtime` interface, `Duration`, mailbox contracts, `Future`/`FutureSlot`, and `Cancellable`.

## What's in this package

**`Monadial\Nexus\Runtime\Runtime\`**

- `Runtime` — interface implemented by `FiberRuntime`, `SwooleRuntime`, and `StepRuntime`
- `Cancellable` — `cancel()` + `isCancelled()` handle for scheduled tasks

**`Monadial\Nexus\Runtime\`**

- `Duration` — immutable nanosecond-precision value object; factory methods `seconds`, `millis`, `micros`, `nanos`, `zero`; arithmetic `plus`, `minus`, `multipliedBy`, `dividedBy`; conversion `toNanos`, `toMillis`, `toSecondsFloat`

**`Monadial\Nexus\Runtime\Async\`**

- `Future<T>` — read-side async result handle; `await()`, `map()`, `flatMap()`, `isResolved()`, `cancel()`
- `FutureSlot<T>` — write-side resolver; `resolve()`, `fail()`, `cancel()`, `onCancel()`

**`Monadial\Nexus\Runtime\Mailbox\`**

- `Mailbox<T>` — `enqueue`, `dequeue`, `dequeueBlocking`, `count`, `isFull`, `isEmpty`, `close`
- `MailboxConfig` — `bounded(capacity, strategy)`, `unbounded()`
- `OverflowStrategy` enum — `DropNewest`, `DropOldest`, `Backpressure`, `ThrowException`
- `EnqueueResult` enum — `Accepted`, `Dropped`, `Backpressured`

**`Monadial\Nexus\Runtime\Exception\`**

- `MailboxClosedException`, `MailboxOverflowException`, `MailboxTimeoutException`, `FutureTimeoutException`

## Install

```bash
composer require nexus-actors/runtime
```

## Quick example

```php title="src/Runtime/DurationExample.php"
use Monadial\Nexus\Runtime\Duration;

$timeout = Duration::seconds(5)->plus(Duration::millis(500));
echo $timeout->toMillis();       // 5500
echo $timeout->toSecondsFloat(); // 5.5
echo $timeout;                   // "5s 500ms"

$budget = Duration::seconds(10)->dividedBy(2);
echo $budget->isGreaterThan(Duration::seconds(4)); // true
```

## See also

- [nexus-runtime-fiber](./runtime-fiber.md) — `FiberRuntime` implementation
- [nexus-runtime-swoole](./runtime-swoole.md) — `SwooleRuntime` implementation
- [nexus-runtime-step](./runtime-step.md) — `StepRuntime` for deterministic tests
