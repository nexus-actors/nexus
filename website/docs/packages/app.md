---
sidebar_position: 8
title: nexus-app
---

# nexus-app

Application kernel for Nexus actor applications. Provides a declarative API
for registering actors and running them in single-process mode.

**Namespace:** `Monadial\Nexus\App\`

## Classes

### NexusApp

Builder for defining and running an actor application.

```php
final class NexusApp
{
    public static function create(string $name): self;
    public function actor(string $name, Props $props): self;
    public function onStart(callable $callback): self;
    public function actors(): array;
    public function run(Runtime $runtime): void;
}
```

Usage:

```php
use Monadial\Nexus\App\NexusApp;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;

NexusApp::create('my-app')
    ->actor('orders', Props::fromBehavior($orderBehavior))
    ->actor('payments', Props::fromBehavior($paymentBehavior))
    ->onStart(function (ActorSystem $system): void {
        // Called after all actors are spawned
    })
    ->run(new SwooleRuntime());
```

### ActorDefinition

Immutable value object holding an actor's name and Props.

```php
/** @psalm-immutable */
final readonly class ActorDefinition
{
    public function __construct(
        public string $name,
        public Props $props,
    ) {}
}
```

## Single-process vs multi-worker

`NexusApp::run()` runs all actors in a single process. For multi-worker
scaling, use `WorkerPoolApp` or `WorkerPoolBootstrap` from
`nexus-worker-pool-swoole`. See [Scaling Bootstrap](../scaling/bootstrap.md).

```php
// Single process
$app->run(new SwooleRuntime());
```
