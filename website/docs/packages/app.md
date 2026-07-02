---
sidebar_position: 8
title: nexus-app
related:
  - packages/core
  - packages/runtime-fiber
  - packages/runtime-swoole
  - scaling/bootstrap
---

# nexus-app

Declarative bootstrap kernel for single-process Nexus applications.

## What's in this package

- `NexusApp` — fluent builder that registers actors and starts the event loop
- `ActorDefinition` — immutable value object pairing an actor name with its `Props`

## Install

```bash
composer require nexus-actors/app
```

## Quick example

<!-- verify:skip: requires a running actor system -->
```php title="src/bootstrap.php" verify:skip
use Monadial\Nexus\App\NexusApp;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;

NexusApp::create('my-app')
    ->actor('orders', Props::fromBehavior($orderBehavior))
    ->actor('payments', Props::fromFactory(fn() => new PaymentActor()))
    ->onStart(function (ActorSystem $system): void {
        // Called after all actors are spawned.
    })
    ->run(new SwooleRuntime());
```

`run()` starts the runtime event loop and blocks until the system shuts down. All registered actors are spawned under `/user` before `onStart` fires.

For multi-worker deployments, use `WorkerPoolApp` or `WorkerPoolBootstrap` from `nexus-worker-pool-swoole` instead.

## See also

- [Scaling / bootstrap](../scaling/bootstrap.md) — multi-worker entry point
- [nexus-runtime-swoole](./runtime-swoole.md) — production runtime
- [nexus-runtime-fiber](./runtime-fiber.md) — development runtime
