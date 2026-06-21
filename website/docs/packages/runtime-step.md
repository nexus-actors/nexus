---
sidebar_position: 4
title: nexus-runtime-step
related:
  - packages/runtime
  - packages/runtime-fiber
  - runtimes/step
---

# nexus-runtime-step

Deterministic runtime for testing — manual message stepping, virtual time, and guaranteed ordering.

## What's in this package

- `StepRuntime` — implements `Runtime`; replaces the automatic tick loop with `step()`, `drain()`, and `advanceTime()`
- `StepMailbox` — always suspends `dequeueBlocking()`, so each message requires an explicit `step()` call
- `VirtualClock` — deterministic PSR-20 clock; starts at `2026-01-01T00:00:00+00:00` by default; `advance(Duration)` and `set(DateTimeImmutable)` move time forward
- `StepCancellable` — boolean-flag cancellation handle

## Install

```bash
composer require nexus-actors/runtime-step
```

## Quick example

<!-- verify:skip: requires a running actor system -->
```php title="tests/Integration/CounterTest.php" verify:skip
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Monadial\Nexus\Runtime\Step\VirtualClock;
use Monadial\Nexus\Runtime\Duration;

$clock   = new VirtualClock();
$runtime = new StepRuntime($clock);
$system  = ActorSystem::create('test', $runtime);

$ref = $system->spawn(Props::fromBehavior($counterBehavior), 'counter');
$ref->tell(new Increment());

$runtime->step();           // process exactly one message
$runtime->advanceTime(Duration::seconds(5)); // fire scheduled timers

self::assertSame(1, $captured);
```

`drain()` processes all pending messages in deterministic order. Use `advanceTime()` to fire `scheduleOnce` and `scheduleRepeatedly` callbacks without waiting for real wall time.

## See also

- [nexus-runtime](./runtime.md) — `Runtime` interface and mailbox contracts
- [nexus-runtime-fiber](./runtime-fiber.md) — fiber runtime for development
