---
sidebar_position: 2
title: Standalone Runtime Primitives
---

# Standalone Runtime Primitives

`nexus-runtime` can be used without `ActorSystem` when you only need async
composition, scheduling, and timeout/cancellation primitives.

## When This Is Useful

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

## Practical Example (No Actors)

```php
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Step\StepRuntime;

$runtime = new StepRuntime();
$slot = $runtime->createFutureSlot();
$future = new Future($slot);

$runtime->scheduleOnce(Duration::seconds(2), static function () use ($slot): void {
    $slot->resolve((object) ['status' => 'ok']);
});

// Deterministic: nothing happens until time is advanced.
$runtime->advanceTime(Duration::seconds(2));

$result = $future
    ->map(static fn(object $v): object => (object) ['status' => strtoupper($v->status)])
    ->await();
```

## Runtime Contract

Concrete runtime packages (`runtime-fiber`, `runtime-swoole`, `runtime-step`)
implement `Monadial\Nexus\Runtime\Runtime\Runtime`.

Use this when your code should accept runtime implementations without coupling
to actor APIs.
