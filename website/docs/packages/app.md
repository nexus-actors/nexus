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
- `StartedApp` — the started application: the live `ActorSystem` plus a registry of the spawned root actor handles, keyed by name
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
    ->onStart(function (StartedApp $app): void {
        // Called after all actors are spawned. Retrieve typed handles by name:
        $app->ref('orders')->tell(new WarmUp());
    })
    ->run(new SwooleRuntime());
```

`run()` starts the runtime event loop and blocks until the system shuts down. All registered actors are spawned under `/user` in registration order before `onStart` fires.

`start()` returns a `StartedApp` instead of blocking — use it when you need to wire OS signal handlers or other infrastructure around the loop. It exposes the spawned root handles by name and owns shutdown:

```php title="src/bootstrap.php" verify:skip
$app = NexusApp::create('my-app')
    ->actor('orders', Props::fromBehavior($orderBehavior))
    ->start(new SwooleRuntime());

$orders = $app->ref('orders');   // typed handle to the 'orders' root actor
$orders->tell(new WarmUp());

$app->run();                     // blocks; or $app->shutdown($timeout) to drain
```

`ref()` throws `UnknownRootActorException` for a name that was never registered; use `has()` to probe, or `refs()` for the whole registry. Reach the underlying system via `$app->system()`.

:::note Upgrade note
`start()` now returns a `StartedApp`, and the `onStart` callback receives that `StartedApp` rather than the raw `ActorSystem`. Callers that previously used the returned system directly should call `$app->system()`; `onStart` callbacks typed `function (ActorSystem $system)` should be retyped to `function (StartedApp $app)` and reach the system via `$app->system()`. `run()` is unchanged.
:::

For multi-worker deployments, use `WorkerPoolApp` or `WorkerPoolBootstrap` from `nexus-worker-pool-swoole` instead.

## See also

- [Scaling / bootstrap](../scaling/bootstrap.md) — multi-worker entry point
- [nexus-runtime-swoole](./runtime-swoole.md) — production runtime
- [nexus-runtime-fiber](./runtime-fiber.md) — development runtime
