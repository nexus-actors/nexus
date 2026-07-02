---
sidebar_position: 2
title: nexus-runtime-fiber
related:
  - packages/runtime
  - packages/runtime-swoole
  - packages/runtime-step
  - runtimes/fiber
---

# nexus-runtime-fiber

PHP 8.1+ native Fiber runtime for Nexus — cooperative multitasking with no extensions required.

## What's in this package

- `FiberRuntime` — implements `Runtime`; manages a map of `Fiber` instances and a `FiberScheduler`; `run()` enters a tick loop
- `FiberMailbox` — `SplQueue`-backed mailbox; `dequeueBlocking()` suspends the current fiber until a message arrives
- `FiberScheduler` — sorted timer queue; `scheduleOnce`, `scheduleRepeatedly`, `advanceTimers`
- `FiberCancellable` — boolean-flag cancellation handle

## Install

```bash
composer require nexus-actors/runtime-fiber
```

## Quick example

<!-- verify:skip: requires a running actor system -->
```php title="src/bootstrap.php" verify:skip
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Runtime\Duration;

$runtime = new FiberRuntime();
$system  = ActorSystem::create('app', $runtime);

$ref = $system->spawn(Props::fromBehavior(Behavior::receive(
    static fn($ctx, $msg): Behavior => Behavior::same(),
)), 'worker');

$ref->tell(new MyMessage());
$runtime->scheduleOnce(Duration::millis(100), fn() => $system->shutdown(Duration::seconds(1)));
$system->run();
```

Each actor runs in its own PHP Fiber. The runtime tick loop starts and resumes fibers, then advances the scheduler. Use `nexus-runtime-swoole` for production workloads requiring true async I/O.

## See also

- [nexus-runtime](./runtime.md) — `Runtime` interface, `Duration`, and mailbox contracts
- [nexus-runtime-swoole](./runtime-swoole.md) — Swoole coroutine runtime for production
- [nexus-runtime-step](./runtime-step.md) — deterministic step runtime for tests
