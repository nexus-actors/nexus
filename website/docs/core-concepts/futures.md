---
title: Futures
related:
  - core-concepts/ask-pattern
  - core-concepts/actors
  - reference/classes/actor-ref
  - guides/ask-vs-tell
---

# Futures

`Future<R>` is the result-handle for a pending async operation — the consumer side. `FutureSlot<R>` is the producer side: the runtime resolves a slot when the reply arrives, which in turn resolves the paired `Future`. Together they form the plumbing that `ActorRef::ask()` is built on.

## The design

### Future and FutureSlot

`Future` wraps a `FutureSlot` and exposes the read-only contract: `await()`, `isResolved()`, `cancel()`, `map()`, `flatMap()`, and `onCancel()`.

`FutureSlot` is a runtime-specific interface. Each runtime provides its own implementation:

- `FiberFutureSlot` — suspends the current PHP fiber via `Fiber::suspend()` until `resolve()` is called.
- `SwooleFutureSlot` — blocks a Swoole coroutine on a `Channel` until the reply arrives.
- `ImmediateFutureSlot` — already-resolved slot for `Future::resolved()` and `Future::failed()`.
- `LazyFutureSlot` — wraps a `Closure` for deferred execution; used internally by `Future::all()`, `map()`, and `flatMap()`.

You do not construct `FutureSlot` directly. `ActorRef::ask()` creates one via the runtime and hands the `Future` back to you.

### How ask() uses them

When you call `$ref->ask(fn($replyTo) => new GetCount($replyTo), Duration::seconds(5))`:

1. The runtime creates a `FutureSlot` and a temporary `FutureRef` whose `tell()` calls `$slot->resolve()`.
2. The request message is sent to the target actor with `$replyTo` set to the `FutureRef`.
3. A timeout fiber is scheduled; if it fires first, `$slot->fail(new FutureTimeoutException(...))`.
4. `await()` suspends the caller's fiber until the slot is resolved or the timeout fires.

### Future::all() for parallel asks

`Future::all()` collects multiple futures and resolves when all complete, or fails on the first failure.

<!-- verify:skip: illustrates parallel asks requiring a running ActorSystem with live actor refs -->
```php title="src/Actor/AggregatorActor.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Async\FutureResult;
use Monadial\Nexus\Runtime\Duration;

$timeout = Duration::seconds(3);

$futures = [
    'inventory' => $inventoryRef->ask(fn($r) => new GetStock($r), $timeout),
    'pricing'   => $pricingRef->ask(fn($r) => new GetPrice($r), $timeout),
];

/** @var FutureResult<object> $results */
$results = Future::all($futures)->await();

$stock = $results->values['inventory'];
$price = $results->values['pricing'];
```

### Transforming results lazily

`map()` and `flatMap()` transform the result without blocking:

```php title="src/Actor/OrderActor.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;

/** @var Future<OrderConfirmation> $future */
$future = $orderRef
    ->ask(fn($r) => new PlaceOrder($items, $r), Duration::seconds(10))
    ->map(fn(OrderConfirmation $c) => new OrderSummary($c->orderId));

// Fiber suspends here, not at map()
$summary = $future->await();
```

## Tradeoffs

| You gain | You give up |
|---|---|
| Typed async result with fiber suspension | Requires `await()` call in a fiber/coroutine context |
| Composable with `map()`, `flatMap()`, `all()` | `Future::all()` fails-fast on the first error; partial results are discarded |
| Cancellable with `cancel()` + `onCancel()` callbacks | Cancellation is cooperative — the target actor is not notified |

## When to reach for it directly vs using ask()

**Use `ActorRef::ask()`** for the common case. It constructs the `FutureSlot`, wires the timeout, and returns the `Future` in one call.

**Work with `Future` directly** when you need:
- `Future::all()` to fan out to multiple actors and collect all replies.
- `Future::resolved()` or `Future::failed()` to return an already-complete value in a test or stub.
- `map()` / `flatMap()` to chain dependent operations before calling `await()`.

**Implement `FutureSlot`** only when writing a new `Runtime` — application code never implements this interface.

## See also

- [Ask pattern](./ask-pattern.md) — the user-facing API built on top of `Future`.
- [Guides: ask vs tell](../guides/ask-vs-tell.md) — when to use ask and when tell is better.
- [Runtimes](../runtimes/overview.md) — how `FiberRuntime` and `SwooleRuntime` implement `FutureSlot`.
