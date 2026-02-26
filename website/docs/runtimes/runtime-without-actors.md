---
sidebar_position: 4
title: Runtime Without Actors
---

# Runtime Without Actors

You can use Nexus runtime implementations directly, without creating an
`ActorSystem`.

This is useful when your project needs async orchestration primitives, but not
actor lifecycle/supervision/message protocols yet.

## Good Use Cases

- wrapping callback-based APIs into `Future`
- orchestrating retries/timeouts in infrastructure modules
- deterministic test orchestration with manual time control
- shared library code that should not depend on `nexus-core`

## Install

```bash
composer require nexus-actors/runtime
composer require --dev nexus-actors/runtime-step
```

## Example 1: Deterministic One-Shot Workflow

```php
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Step\StepRuntime;

$runtime = new StepRuntime();
$slot = $runtime->createFutureSlot();
$future = new Future($slot);

$runtime->scheduleOnce(Duration::millis(250), static function () use ($slot): void {
    $slot->resolve((object) ['count' => 21]);
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
$slot = $runtime->createFutureSlot();
$future = new Future($slot);

$runtime->scheduleOnce(Duration::seconds(1), static function () use ($slot): void {
    $slot->fail(new QueryTimeout('query timed out'));
});

$runtime->advanceTime(Duration::seconds(1));

try {
    $future->await();
} catch (QueryTimeout $e) {
    // map/log/retry
}
```

## Why Start Runtime-Only

- smaller surface area while introducing concurrency gradually
- deterministic tests before adopting full actor model
- clean separation between domain logic and runtime mechanics
- easy migration path: add `nexus-core` later when actors become valuable

## Next

- Fast setup: [Bootstrap Runtime](./bootstrap.md)
- Runtime contracts and implementation matrix: [Runtime Overview](./overview.md)
- Package surface: [nexus-runtime](../packages/runtime.md)
