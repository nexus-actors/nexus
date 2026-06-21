---
sidebar_position: 4
title: Standalone Runtime (Without Actors)
---

# Standalone Runtime (Without Actors)

You can use Nexus runtime implementations directly, without creating an
`ActorSystem`.

This is useful when your project needs async orchestration primitives, but not
actor lifecycle/supervision/message protocols yet.

## Good Use Cases

- wrapping callback-based APIs into `Future`
- orchestrating retries/timeouts in infrastructure modules
- deterministic test orchestration with manual time control
- shared library code that should not depend on `nexus-core`
- building framework adapters around callback/timer APIs
- orchestrating background workflows without actor hierarchy
- sharing `Duration` + `Future` abstractions across packages
- writing deterministic tests with `StepRuntime` and `VirtualClock`

## Install

```bash
# Runtime contracts + Future primitives
composer require nexus-actors/runtime

# Optional deterministic runtime implementation for tests
composer require --dev nexus-actors/runtime-step
```

## Example 1: Deterministic One-Shot Workflow

```php
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Step\StepRuntime;

$runtime = new StepRuntime();
// Result placeholder managed by the runtime (resolve/fail + await).
$resultSlot = $runtime->createFutureSlot();
$future = new Future($resultSlot);

$runtime->scheduleOnce(Duration::millis(250), static function () use ($resultSlot): void {
    $resultSlot->resolve((object) ['count' => 21]);
});

$runtime->advanceTime(Duration::millis(250));

$result = $future
    ->map(static fn(object $v): object => (object) ['count' => $v->count * 2])
    ->await();
```

## Example 2: Timeout / Failure Mapping

```php
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\FutureTimeoutException;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use RuntimeException;

final class QueryTimeout extends RuntimeException implements FutureTimeoutException {}

$runtime = new StepRuntime();
$resultSlot = $runtime->createFutureSlot();
$future = new Future($resultSlot);

$runtime->scheduleOnce(Duration::seconds(1), static function () use ($resultSlot): void {
    $resultSlot->fail(new QueryTimeout('query timed out'));
});

$runtime->advanceTime(Duration::seconds(1));

try {
    $future->await();
} catch (QueryTimeout $e) {
    // map/log/retry
}
```

## Runtime Contract

Concrete runtime packages (`runtime-fiber`, `runtime-swoole`, `runtime-step`)
implement `Monadial\Nexus\Runtime\Runtime\Runtime`.

Use this when your code should accept runtime implementations without coupling
to actor APIs.

## Why Start Runtime-Only

- smaller surface area while introducing concurrency gradually
- deterministic tests before adopting full actor model
- clean separation between domain logic and runtime mechanics
- easy migration path: add `nexus-core` later when actors become valuable

## Next

- Fast setup: [Bootstrap Runtime](./bootstrap.md)
- Runtime contracts and implementation matrix: [Runtime Overview](./overview.md)
- Package surface: [nexus-runtime](../packages/runtime.md)
