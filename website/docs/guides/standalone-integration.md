---
title: How to use the runtime without a full actor system
related:
  - runtimes/standalone
  - runtimes/fiber
  - core-concepts/ask-pattern
  - runtimes/overview
---

# How to use the runtime without a full actor system

When you need async primitives — concurrent requests, parallel fetches, fire-and-forget work — but do not need actor supervision trees, you can use `Future` and the fiber runtime directly. This avoids the overhead of an `ActorSystem` and is the right fit for one-off scripts, CLI commands, and integration glue code.

## Solution

<!-- verify:skip: illustrates standalone fiber usage requiring the runtime to be running -->
```php title="src/Script/ParallelFetch.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Async\LazyFutureSlot;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

$runtime = new FiberRuntime();

// Spawn two concurrent tasks
$futureA = new Future(new LazyFutureSlot(static fn() => fetchRemoteData('https://api.example.com/a')));
$futureB = new Future(new LazyFutureSlot(static fn() => fetchRemoteData('https://api.example.com/b')));

// Wait for both
$combined = Future::all(['a' => $futureA, 'b' => $futureB]);
$runtime->run();

$results = $combined->await();
```

For the simpler case of running a single async closure, the standalone runtime documented in `runtimes/standalone.md` provides a higher-level helper.

## How it works

`Future<R>` is a handle to a pending result. It wraps a `FutureSlot` — the internal resolution mechanism that each runtime implements using its own suspension primitive. `FiberRuntime` suspends the calling fiber via `Fiber::suspend()` when `await()` is called and resumes it when the slot is resolved.

`Future::all()` creates a combined future that resolves when all input futures resolve. Internally it uses `LazyFutureSlot`, which defers execution until `await()` is called — no fibers are spawned until the runtime starts processing.

`Future::resolved($value)` creates an already-completed future, useful for testing and for composing with `flatMap()` without actually suspending.

## Variations

### Transforming a future result

`Future::map()` transforms the resolved value lazily — the callback runs when the future resolves, not when `map()` is called:

<!-- verify:skip: illustrates Future::map usage -->
```php title="src/Script/TransformFuture.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\Runtime\Async\Future;

$rawFuture = fetchOrderFuture($orderId);

$summaryFuture = $rawFuture->map(
    static fn(Order $order) => new OrderSummary($order->id, $order->total),
);

$summary = $summaryFuture->await();
```

### Chaining dependent futures

`Future::flatMap()` chains a second async operation that depends on the first result:

<!-- verify:skip: illustrates Future::flatMap chaining -->
```php title="src/Script/ChainedFutures.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\Runtime\Async\Future;

$orderFuture = fetchOrderFuture($orderId);

$invoiceFuture = $orderFuture->flatMap(
    static fn(Order $order) => generateInvoiceFuture($order),
);

$invoice = $invoiceFuture->await();
```

### Cancellation

Futures can be cancelled. Register a cancellation callback to clean up resources:

<!-- verify:skip: illustrates Future cancellation with onCancel -->
```php title="src/Script/CancellableFetch.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\Runtime\Async\Future;

$future = fetchWithTimeoutFuture($url);
$future->onCancel(static fn() => cleanupConnection());

// Cancel if it takes too long
if ($tookTooLong) {
    $future->cancel();
}
```

## Caveats

:::caution await() must be called inside a running fiber
`Future::await()` suspends the current fiber. If called from the main thread (outside a fiber), it throws because there is no fiber to suspend. Wrap standalone code in `$runtime->spawn(fn() => ...)` or use `$runtime->run()` to drive the fiber scheduler.
:::

- **`LazyFutureSlot` defers execution.** The closure passed to `LazyFutureSlot` does not run until `await()` is called. If the future is never awaited, the work never runs.
- **`FutureException` covers timeout and cancellation.** `FutureTimeoutException` and `FutureCancelledException` both extend `FutureException`. Catch the base class unless you need to distinguish them.
- **`Future::all()` fails fast.** If any input future fails, the combined future fails immediately with the first error. Remaining futures are not cancelled — they continue running but their results are discarded.
- **No supervision.** Standalone futures have no supervisor. Unhandled exceptions in a `LazyFutureSlot` propagate to the `await()` caller as `FutureException`. Add your own `try/catch` around `await()` when failure handling matters.

## Related

- [Standalone runtime](../runtimes/standalone.md) — higher-level helpers for running closures as fibers
- [Ask pattern](../core-concepts/ask-pattern.md) — `ask()` returns a `Future`; the concepts are identical
- [Fiber runtime](../runtimes/fiber.md) — how the fiber scheduler drives future resolution
