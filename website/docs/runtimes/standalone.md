---
sidebar_position: 5
title: Standalone Runtime
related:
  - runtimes/overview
  - runtimes/bootstrap
  - runtimes/step
  - packages/runtime
---

# Standalone Runtime

You can use Nexus runtime implementations directly, without creating an `ActorSystem` — this is useful when your project needs async orchestration primitives but not actor lifecycle management, supervision, or message protocols.

## The design

The `nexus-actors/runtime` package contains the `Runtime` interface, `Duration`, `Future`, and `FutureSlot` — everything needed to compose async workflows — with no dependency on `nexus-core`. The concrete runtime packages (`runtime-fiber`, `runtime-swoole`, `runtime-step`) implement `Runtime` and can be used standalone.

This separation matters when:

- you want to introduce async composition gradually, before committing to the actor model
- a shared library package needs `Duration` and `Future` abstractions without pulling in actor APIs
- you are writing deterministic tests using `StepRuntime` and a virtual clock, but the code under test does not spawn actors
- an infrastructure module needs retry/timeout logic that should not depend on `nexus-core`

## Install

```bash
# Runtime contracts and Future primitives
composer require nexus-actors/runtime

# Optional: deterministic runtime for tests
composer require --dev nexus-actors/runtime-step
```

## Example 1: Deterministic one-shot workflow

`StepRuntime` gives you a virtual clock and manual timer execution. Schedule a callback, advance time, and the `Future` resolves synchronously.

```php title="src/Infra/AsyncWorkflow.php"
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Step\StepRuntime;

$runtime = new StepRuntime();
$resultSlot = $runtime->createFutureSlot();
$future = new Future($resultSlot);

$runtime->scheduleOnce(
    Duration::millis(250),
    static function () use ($resultSlot): void {
        $resultSlot->resolve((object) ['count' => 21]);
    },
);

$runtime->advanceTime(Duration::millis(250));

$result = $future
    ->map(static fn(object $v): object => (object) ['count' => $v->count * 2])
    ->await();
```

## Example 2: Timeout and failure mapping

`FutureSlot::fail()` propagates an exception through the `Future`. Catch it at the call site or let a supervisor handle it.

```php title="src/Infra/QueryClient.php"
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\FutureTimeoutException;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use RuntimeException;

final class QueryTimeout extends RuntimeException implements FutureTimeoutException {}

$runtime = new StepRuntime();
$resultSlot = $runtime->createFutureSlot();
$future = new Future($resultSlot);

$runtime->scheduleOnce(
    Duration::seconds(1),
    static function () use ($resultSlot): void {
        $resultSlot->fail(new QueryTimeout('query timed out'));
    },
);

$runtime->advanceTime(Duration::seconds(1));

try {
    $future->await();
} catch (QueryTimeout $e) {
    // log, retry, or propagate
}
```

## Tradeoffs

Running standalone removes the actor supervision tree and message routing overhead, which is appropriate for infrastructure code that manages its own async lifecycle. The tradeoff is that you give up location transparency, death watch, and the structured concurrency guarantees that come with the full actor system. When your orchestration logic grows to the point where you need child actors, passivation, or per-message state machines, add `nexus-core` and migrate.

## When to reach for it

- Wrapping callback-based APIs into `Future` for use in other packages.
- Orchestrating retries and timeouts in an infrastructure module that must stay decoupled from actor APIs.
- Writing deterministic tests for async code using `StepRuntime` and `VirtualClock`.
- Building framework adapters around callback or timer APIs before the project is ready for full actor adoption.

## See also

- [Bootstrap Runtime](./bootstrap.md) — fast setup for all four installation paths
- [Runtime Overview](./overview.md) — full `Runtime` interface contract
- [Step Runtime](./step.md) — `advanceTime()`, `VirtualClock`, and deterministic testing patterns
