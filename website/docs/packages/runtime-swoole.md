---
sidebar_position: 3
title: nexus-runtime-swoole
related:
  - packages/runtime
  - packages/runtime-fiber
  - runtimes/swoole
---

# nexus-runtime-swoole

Swoole 6.2.1+ coroutine runtime for Nexus — true async I/O, native channels, and multi-process scaling.

## What's in this package

- `SwooleRuntime` — implements `Runtime`; wraps `Co\run()` and uses `Swoole\Coroutine::create()` for actor spawning
- `SwooleConfig` — `maxCoroutines`, `defaultMailboxCapacity`, `enableCoroutineHook`; all fields `readonly`
- `SwooleMailbox` — backed by `Swoole\Coroutine\Channel`; native coroutine suspension on `dequeueBlocking()`
- `SwooleCancellable` — wraps a Swoole timer ID; `cancel()` calls `Swoole\Timer::clear()`
- `DeferredCancellable` — internal handle for timers scheduled before `Co\run()` starts

## Install

```bash
composer require nexus-actors/runtime-swoole
```

## Quick example

<!-- verify:skip: requires a running Swoole coroutine context -->
```php title="src/bootstrap.php" verify:skip
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Swoole\SwooleConfig;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;

$runtime = new SwooleRuntime(new SwooleConfig(maxCoroutines: 50_000));
$system  = ActorSystem::create('app', $runtime);

$system->spawn(Props::fromBehavior(Behavior::receive(
    static fn($ctx, $msg): Behavior => Behavior::same(),
)), 'worker');

$system->run(); // enters Co\run(), blocks until all coroutines finish
```

`run()` enters `Co\run()`, which blocks until all coroutines and timers complete. For HTTP/WebSocket deployments, pass a `SwooleRuntime` to the server runner via `nexus-http-server-swoole` or `nexus-http-server-swoole-threads`.

## See also

- [nexus-runtime](./runtime.md) — `Runtime` interface, `Duration`, and mailbox contracts
- [nexus-runtime-fiber](./runtime-fiber.md) — fiber runtime for development
- [nexus-http-server-swoole](./http-server-swoole.md) — Swoole HTTP server runner
