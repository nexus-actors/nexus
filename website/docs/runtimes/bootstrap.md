---
sidebar_position: 2
title: Bootstrap Runtime
---

# Bootstrap Runtime

This page is the fastest way to bootstrap Nexus runtime usage.

Use one of two tracks:

- Track A: bootstrap an actor system with Fiber, Swoole, or Step runtime
- Track B: use standalone runtime primitives (`Future`, `FutureSlot`) without actors

## Choose Your Runtime Path

| Path | Install | Best for |
|---|---|---|
| Fiber actor runtime | `composer require nexus-actors/core nexus-actors/runtime-fiber` | Local development and CI without extensions |
| Swoole actor runtime | `composer require nexus-actors/core nexus-actors/runtime-swoole` | Production workloads and async I/O |
| Step actor runtime | `composer require nexus-actors/core nexus-actors/runtime-step` | Deterministic testing with manual stepping |
| Standalone runtime primitives | `composer require nexus-actors/runtime` | Async composition without actor system APIs |

## Track A: Actor System Bootstrap

### Fiber (development default)

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

$runtime = new FiberRuntime();
$system = ActorSystem::create('app', $runtime);

// spawn actors, then run
$system->run();
```

### Swoole (production)

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Swoole\SwooleConfig;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;

$runtime = new SwooleRuntime(new SwooleConfig(
    defaultMailboxCapacity: 1000,
    enableCoroutineHook: true,
    maxCoroutines: 100_000,
));

$system = ActorSystem::create('app', $runtime);
$system->run();
```

### Step (tests)

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Step\StepRuntime;

$runtime = new StepRuntime();
$system = ActorSystem::create('test-system', $runtime, clock: $runtime->clock());

// manual execution for deterministic assertions
$runtime->step();
$runtime->advanceTime(\Monadial\Nexus\Runtime\Duration::seconds(1));
```

## Track B: Standalone Runtime Primitives

You can compose futures without bootstrapping an actor system.
Use this minimal bootstrap:

```php
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Step\StepRuntime;

$runtime = new StepRuntime();
// Result placeholder managed by the runtime (resolve/fail + await).
$resultSlot = $runtime->createFutureSlot();
$future = new Future($resultSlot);

$runtime->scheduleOnce(Duration::millis(100), static fn() => $resultSlot->resolve((object) ['ok' => true]));
$runtime->advanceTime(Duration::millis(100));
$result = $future->await();
```

For complete standalone guidance and richer examples, see:
[Runtime Without Actors](./standalone.md).

## Bootstrap Checklist

You are bootstrapped when:

- dependencies are installed for exactly one chosen path
- namespace imports compile with `Monadial\Nexus\Runtime\...`
- actor path: `ActorSystem::create(...)` runs with selected runtime
- standalone path: `Future` resolves and combinators execute as expected

## Next

- Runtime details: [Runtime Overview](./overview.md)
- Fiber deep dive: [Fiber Runtime](./fiber.md)
- Swoole deep dive: [Swoole Runtime](./swoole.md)
- Deterministic testing: [Step Runtime](./step.md)
- Package reference: [nexus-runtime](../packages/runtime.md)
