---
title: NexusApp
sidebar_position: 25
related:
  - getting-started/quick-start
  - reference/classes/actor-system
  - reference/classes/runtime
---

# NexusApp

Fluent bootstrap entry point for Nexus actor applications; registers named actors,
optional lifecycle hooks, and starts the system with a single `run()` call.

## What it does

`NexusApp` is the top-level application kernel for single-process Nexus deployments.
It collects actor definitions via `actor()`, an optional post-spawn callback via
`onStart()`, and then either:

- `run(Runtime)` — spawns all actors, fires the start callback, and blocks in the
  runtime event loop until the system shuts down.
- `start(Runtime)` — spawns actors and fires the callback, but returns the
  `ActorSystem` without blocking. Use this when you need to wire OS signal handling,
  bind HTTP servers, or perform other setup before handing control to the event loop.

All registration methods return `$this`, so the entire bootstrap fits in a single
fluent expression.

`NexusApp` is intentionally minimal. For worker-pool (multi-process/multi-thread)
deployments see `WorkerPoolApp` in the `nexus-worker-pool-swoole` package.

## Example

```php title="src/bootstrap.php"
use Monadial\Nexus\App\NexusApp;
use Monadial\Nexus\Runtime\Runtime\FiberRuntime;

NexusApp::create('shop')
    ->actor('orders', Props::fromBehavior($orderBehavior))
    ->actor('payments', Props::fromFactory(fn() => new PaymentActor()))
    ->actor('notifications', Props::fromContainer($container, NotificationActor::class))
    ->onStart(function (ActorSystem $system): void {
        // All actors are spawned at this point.
        $system->deadLetters(); // warm up the dead-letter channel
    })
    ->run(new FiberRuntime());
```

Non-blocking variant for HTTP + actors together:

```php title="src/bootstrap.php"
$app = NexusApp::create('api')
    ->actor('orders', Props::fromBehavior($orderBehavior));

$system = $app->start(new SwooleRuntime());

// Bind HTTP server before starting the event loop
$httpServer = new SwooleHttpServer('0.0.0.0', 8080);
$httpServer->start();

$system->run(); // blocks here
```

## Key methods

- `static create(string $name): self` — create a new `NexusApp`; the name is passed
  to `ActorSystem::create()` and appears in logs and actor paths.
- `actor(string $name, Props $props): self` — register an actor to be spawned on
  startup.
- `onStart(callable(ActorSystem): void $callback): self` — register a callback
  invoked after all actors are spawned but before the event loop starts.
- `start(Runtime $runtime, ?LoggerInterface $logger = null): ActorSystem` — spawn
  actors, fire the start callback, return the system without blocking.
- `run(Runtime $runtime, ?LoggerInterface $logger = null): void` — calls `start()`
  then `ActorSystem::run()`.
- `name(): string` — the application name supplied to `create()`.
- `actors(): list<ActorDefinition>` — the registered actor definitions (useful for
  testing).

## Full API reference

[Full class and method signatures](https://api.nexusactors.com/classes/Monadial-Nexus-App-NexusApp.html)

## See also

- [Quick start](../../getting-started/quick-start) — first app walk-through using
  `NexusApp`
- [ActorSystem](actor-system) — the underlying system; `start()` returns one
- [Runtime](runtime) — choose `FiberRuntime`, `SwooleRuntime`, or `StepRuntime`
