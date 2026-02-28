---
sidebar_position: 4
title: Runtime without actors
---

# Runtime without actors

Nexus runtime implementations can be used directly, without creating an
`ActorSystem`.

This is useful when the project needs async orchestration primitives, but not
actor lifecycle/supervision/message protocols yet.

## Good use cases

- wrapping callback-based APIs into `Future`
- orchestrating retries/timeouts in infrastructure modules
- deterministic test orchestration with manual time control
- shared library code that should not depend on `nexus-core`

## Install

```bash
composer require nexus-actors/runtime
composer require --dev nexus-actors/runtime-step
```

## Example 1: Deterministic one-shot workflow

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

## Example 2: Timeout / failure mapping

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

## Why start runtime-only

- smaller surface area while introducing concurrency gradually
- deterministic tests before adopting full actor model
- clean separation between domain logic and runtime mechanics
- easy migration path: add `nexus-core` later when actors become valuable

## Next

- Fast setup: [Bootstrap runtime](./bootstrap.md)
- Runtime contracts and implementation matrix: [Runtime overview](./overview.md)
- Package surface: [nexus-runtime](../packages/runtime.md)
