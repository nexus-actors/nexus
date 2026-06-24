---
sidebar_position: 2
title: Bootstrap Runtime
related:
  - runtimes/overview
  - runtimes/fiber
  - runtimes/swoole
  - runtimes/step
---

# Bootstrap Runtime

This page shows the minimum code to get any Nexus runtime running — pick the path that matches your use case and you will have a working actor system or standalone future in under a minute.

## Choosing a path

| Path | Install | Best for |
|---|---|---|
| Fiber actor runtime | `composer require nexus-actors/core nexus-actors/runtime-fiber` | Local development and CI without extensions |
| Swoole actor runtime | `composer require nexus-actors/core nexus-actors/runtime-swoole` | Production workloads and async I/O |
| Step actor runtime | `composer require nexus-actors/core nexus-actors/runtime-step` | Deterministic testing with manual stepping |
| Standalone runtime primitives | `composer require nexus-actors/runtime` | Async composition without actor system APIs |

## Actor system bootstrap

### Fiber (development default)

`FiberRuntime` needs no configuration. Create the runtime, pass it to `ActorSystem::create()`, spawn actors, then call `run()`.

```php title="src/bootstrap-fiber.php"
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

$runtime = new FiberRuntime();
$system = ActorSystem::create('app', $runtime);

// spawn actors here

$system->run();
```

### Swoole (production)

Pass a `SwooleConfig` to control mailbox capacity, coroutine hooking, and the coroutine ceiling.

```php title="src/bootstrap-swoole.php"
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

Pass `$runtime->clock()` to the actor system so actors share the same virtual clock. Time and message processing are both under your control.

```php title="tests/bootstrap-step.php"
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Step\StepRuntime;

$runtime = new StepRuntime();
$system = ActorSystem::create('test-system', $runtime, clock: $runtime->clock());

// send messages, then manually advance execution
$runtime->step();
$runtime->advanceTime(\Monadial\Nexus\Runtime\Duration::seconds(1));
```

## Standalone runtime primitives

You can compose futures without bootstrapping an `ActorSystem`. This minimal approach works when you need async orchestration but not full actor lifecycle management.

```php title="src/standalone.php"
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Step\StepRuntime;

$runtime = new StepRuntime();
$resultSlot = $runtime->createFutureSlot();
$future = new Future($resultSlot);

$runtime->scheduleOnce(
    Duration::millis(100),
    static fn() => $resultSlot->resolve((object) ['ok' => true]),
);

$runtime->advanceTime(Duration::millis(100));
$result = $future->await();
```

For richer examples and motivation, see [Standalone Runtime](./standalone.md).

## Bootstrap checklist

You are bootstrapped when:

- dependencies are installed for exactly one chosen path
- namespace imports compile under `Monadial\Nexus\Runtime\…`
- actor path: `ActorSystem::create(…)` runs without error
- standalone path: `Future` resolves and combinators execute as expected

## See also

- [Runtime Overview](./overview.md) — the `Runtime` interface contract and when to choose each implementation
- [Fiber Runtime](./fiber.md) — cooperative scheduling deep dive
- [Swoole Runtime](./swoole.md) — configuration, coroutine hooking, graceful shutdown
- [Step Runtime](./step.md) — `step()`, `drain()`, `advanceTime()`, and testing patterns
