---
sidebar_position: 2
title: nexus-runtime
---

# nexus-runtime

Runtime abstractions and async primitives.

**Composer:** `nexus-actors/runtime`

**Namespace:** `Monadial\Nexus\Runtime\`

## Async namespace

`Monadial\Nexus\Runtime\Async\`

| Class / Interface | Description |
|---|---|
| `Future<T>` | Async result handle. Methods: `await()`, `map(Closure)`, `flatMap(Closure)`, `isResolved()`. |
| `FutureSlot<T>` | Runtime-backed resolver for `Future`. Methods: `resolve(object)`, `fail(FutureException)`, `await()`, `isResolved()`. |
| `LazyFutureSlot<T>` | Internal lazy `FutureSlot` used by combinators (`map`, `flatMap`). |

## Runtime namespace

`Monadial\Nexus\Runtime\Runtime\`

| Class / Interface | Description |
|---|---|
| `Runtime` | Runtime contract used by runtimes like Fiber, Swoole, and Step. |
| `Cancellable` | Cancellation handle for scheduled tasks (`cancel()`, `isCancelled()`). |

## Mailbox namespace

`Monadial\Nexus\Runtime\Mailbox\`

| Class / Interface | Description |
|---|---|
| `Mailbox<T>` | Generic mailbox contract used by runtime implementations. |
| `MailboxConfig` | Immutable mailbox configuration (`bounded()`, `unbounded()`, `withCapacity()`, `withStrategy()`). |
| `OverflowStrategy` | Overflow behavior enum (`DropNewest`, `DropOldest`, `Backpressure`, `ThrowException`). |
| `EnqueueResult` | Enqueue result enum (`Accepted`, `Dropped`, `Backpressured`). |

## Exception namespace

`Monadial\Nexus\Runtime\Exception\`

| Class / Interface | Description |
|---|---|
| `FutureException` | Base marker interface for future failures. |
| `FutureTimeoutException` | Marker interface for timeout-style future failures. |
| `MailboxException` | Base mailbox exception. |
| `MailboxClosedException` | Mailbox operation on closed mailbox. |
| `MailboxOverflowException` | Mailbox overflow with throw strategy. |
| `MailboxTimeoutException` | Mailbox operation timed out. |
| `InvalidMailboxConfigException` | Invalid mailbox configuration. |

## Duration

`Monadial\Nexus\Runtime\Duration`

Immutable nanosecond-precision duration value object used by all runtime APIs.

## Runtime interface

The `Runtime` interface abstracts the concurrency primitive. Implementations are
`FiberRuntime` (`nexus-runtime-fiber`), `SwooleRuntime` (`nexus-runtime-swoole`),
and `StepRuntime` (`nexus-runtime-step`).

```php
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Runtime\Cancellable;
use Monadial\Nexus\Runtime\Runtime\Runtime;

interface Runtime
{
    public function name(): string;

    /** @return Mailbox<T> */
    public function createMailbox(MailboxConfig $config): Mailbox;

    /** Returns the write-side slot for the ask pattern. */
    public function createFutureSlot(): FutureSlot;

    public function spawn(callable $actorLoop): string;

    public function scheduleOnce(Duration $delay, callable $callback): Cancellable;

    public function scheduleRepeatedly(
        Duration $initialDelay,
        Duration $interval,
        callable $callback,
    ): Cancellable;

    public function yield(): void;

    public function sleep(Duration $duration): void;

    public function run(): void;

    public function shutdown(Duration $timeout): void;

    public function isRunning(): bool;
}
```

`spawn()` creates a lightweight fiber or coroutine for an actor's message-processing
loop and returns an opaque string identifier. `yield()` gives up the current
timeslice to allow other fibers to run. `sleep()` suspends for at least the given
duration without blocking the scheduler.

## Mailbox contracts

```php
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;

// Unbounded mailbox (default)
$config = MailboxConfig::unbounded();

// Bounded mailbox — throw when full (default overflow strategy)
$config = MailboxConfig::bounded(capacity: 10_000);

// Bounded mailbox with explicit overflow strategy
$config = MailboxConfig::bounded(
    capacity: 10_000,
    strategy: OverflowStrategy::DropNewest,
);

// Mutate an existing config (returns new instance)
$config = $config->withCapacity(5_000)->withStrategy(OverflowStrategy::Backpressure);
```

Overflow strategies:

| Strategy | Behaviour |
|---|---|
| `DropNewest` | Discard the incoming message when full. |
| `DropOldest` | Evict the oldest queued message to make room. |
| `Backpressure` | Block the sender until space is available. |
| `ThrowException` | Throw `MailboxOverflowException` immediately. |

The `Mailbox<T>` interface:

```php
interface Mailbox
{
    /** Returns Accepted, Dropped, or Backpressured. */
    public function enqueue(object $message): EnqueueResult;

    /** Non-blocking poll. Returns null when empty. */
    public function dequeue(): mixed;

    /**
     * Suspends the calling fiber or coroutine until an envelope arrives
     * or the timeout expires. Throws MailboxTimeoutException on timeout,
     * MailboxClosedException if the mailbox has been closed.
     */
    public function dequeueBlocking(Duration $timeout): object;

    public function count(): int;
    public function isFull(): bool;
    public function isEmpty(): bool;
    public function close(): void;
}
```

`dequeueBlocking()` is the primary receive primitive for actor loops. It suspends
the current fiber (or Swoole coroutine) and resumes it as soon as a message is
enqueued, without spinning or polling.

## Duration

`Duration` is an immutable nanosecond-precision value object. All arithmetic
methods return new instances; the original is never mutated.

```php
use Monadial\Nexus\Runtime\Duration;

// Factory methods
$d = Duration::seconds(5);
$d = Duration::millis(200);
$d = Duration::micros(500);
$d = Duration::nanos(1_000_000);
$d = Duration::zero();

// Arithmetic (returns new instances)
$total = Duration::seconds(1)->plus(Duration::millis(500));
$half  = Duration::seconds(2)->dividedBy(2);
$long  = Duration::millis(100)->multipliedBy(10);
$diff  = Duration::seconds(5)->minus(Duration::millis(500));

// Comparison
$d->isGreaterThan(Duration::millis(100)); // bool
$d->isLessThan(Duration::millis(100));    // bool
$d->equals(Duration::millis(200));        // bool
$d->isZero();                             // bool
$d->compareTo(Duration::millis(100));     // -1 | 0 | 1

// Conversion
$d->toNanos();        // int
$d->toMicros();       // int
$d->toMillis();       // int
$d->toSeconds();      // int (truncated)
$d->toSecondsFloat(); // float

// String representation (implements Stringable)
echo Duration::seconds(1)->plus(Duration::millis(500)); // "1s 500ms"
```

## Future and FutureSlot

`Future<T>` is the read-side handle to a pending async result. `FutureSlot<T>`
is the write-side resolution mechanism. The runtime creates slots via
`Runtime::createFutureSlot()`; the actor system uses them internally for the
ask pattern.

```php
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Exception\FutureException;

// The runtime creates the slot; the caller receives the Future.
$slot = $runtime->createFutureSlot();
$future = new Future($slot);

// Resolve from a handler when the result is ready.
$slot->resolve(new OrderConfirmed($orderId));

// Fail the slot if an error occurs.
$slot->fail($someException); // $someException implements FutureException

// Cancel the slot (e.g. on timeout).
$slot->cancel();

// Register a cancellation callback.
$slot->onCancel(function (): void {
    // clean up resources
});

// await() suspends the current fiber/coroutine until resolved or failed.
$result = $future->await(); // throws FutureException on failure or cancellation

// Check without suspending.
$future->isResolved(); // bool

// Cancel from the read side.
$future->cancel();
```

`map()` and `flatMap()` compose futures without blocking:

```php
// Transform the result lazily when it arrives.
$future
    ->map(static fn(OrderConfirmed $c): OrderSummary => new OrderSummary($c->orderId))
    ->flatMap(static fn(OrderSummary $s): Future => $enrichmentFuture)
    ->await();
```

## Cancellable

`scheduleOnce()` and `scheduleRepeatedly()` return a `Cancellable` that lets
callers abort a scheduled callback before it fires:

```php
use Monadial\Nexus\Runtime\Runtime\Cancellable;

$cancellable = $runtime->scheduleOnce(
    Duration::millis(500),
    function (): void {
        // fires once after 500 ms
    },
);

$cancellable->cancel();       // abort if not yet fired
$cancellable->isCancelled();  // bool

$heartbeat = $runtime->scheduleRepeatedly(
    Duration::zero(),           // initial delay
    Duration::seconds(1),       // interval
    function (): void {
        // fires every second
    },
);

// Stop the repeating schedule when no longer needed.
$heartbeat->cancel();
```

## When To Use `nexus-runtime` Only

Use `nexus-runtime` without `nexus-core` when:

- async result composition (`Future`, `map`, `flatMap`, `await`) is needed in non-actor code
- runtime-neutral scheduling contracts are required for adapters or infrastructure code
- deterministic orchestration in tests is needed (for example with `nexus-runtime-step`)
- shared timeout/cancellation primitives (`Duration`, `Cancellable`) are needed in libraries

## Why It Is Useful

- keeps actor-free modules lightweight and decoupled from actor APIs
- lets infrastructure code depend on stable runtime contracts only
- improves testability by using runtime implementations directly
- avoids forcing full actor-system adoption when only async primitives are needed

## Bootstrap

See [Bootstrap Runtime](../runtimes/bootstrap.md) for actor-system and
standalone setup flows.
