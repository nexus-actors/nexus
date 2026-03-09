# Getting started

## Install the package

```bash
composer require monadial/nexus-symfony
```

The package pulls in `monadial/nexus-core` and `monadial/nexus-runtime-swoole` as transitive dependencies.

## Register the bundle

Add `NexusBundle` to `config/bundles.php`:

```php
return [
    // ... existing bundles
    Monadial\Nexus\Symfony\NexusBundle::class => ['all' => true],
];
```

## Configure the bundle

Create `config/packages/nexus.yaml`:

```yaml
nexus:
    name: my-app           # identifies the ActorSystem per worker
    shutdown_timeout: 30   # seconds to wait for in-flight requests on SIGTERM
```

The bundle registers `nexus.actor_system` (aliased to `ActorSystem`) and `nexus.runtime` (aliased to `Runtime`) as synthetic services that are set on the container by `NexusRunner` during the Swoole `workerStart` event.

## Configure the runtime

Set the `APP_RUNTIME` environment variable to point to `NexusRuntime` and pass options via `APP_RUNTIME_OPTIONS`:

```dotenv
APP_RUNTIME=Monadial\Nexus\Symfony\Runtime\NexusRuntime
APP_RUNTIME_OPTIONS={"workers":4,"kernel_pool_size":8,"kernel_pool_max_pending":100}
```

### Runtime options reference

| Option | Default | Description |
|---|---|---|
| `host` | `0.0.0.0` | Bind address for the Swoole HTTP server |
| `port` | `8080` | Bind port |
| `workers` | `4` | Number of Swoole worker processes |
| `kernel_pool_size` | `8` | Symfony kernels per worker (concurrency within a worker) |
| `kernel_pool_max_pending` | `100` | Maximum queued requests before returning 503 |

`APP_RUNTIME_OPTIONS` is a JSON object. All keys are optional; unset keys fall back to the defaults above.

## Entry point

The Symfony entry point (`public/index.php`) requires no changes beyond the standard Symfony Runtime setup:

```php
<?php

use App\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static fn(array $context) => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
```

`NexusRuntime::getResolver()` captures the kernel factory closure; `NexusRuntime::getRunner()` passes it to `NexusRunner` alongside the merged options.

## Start the server

```bash
php -d memory_limit=2G public/index.php
```

Or with Docker:

```bash
docker compose up app
```

## Minimal docker-compose.yml

```yaml
services:
    app:
        image: your-app-image   # PHP 8.5 ZTS + Swoole 6.0
        ports:
            - "8080:8080"
        environment:
            APP_ENV: prod
            APP_RUNTIME: Monadial\Nexus\Symfony\Runtime\NexusRuntime
            APP_RUNTIME_OPTIONS: '{"workers":4,"kernel_pool_size":8,"kernel_pool_max_pending":100}'
            APP_SECRET: "%env(APP_SECRET)%"
        command: php -d memory_limit=2G public/index.php
```

## Verify the server is running

```bash
curl http://localhost:8080/
```

The response confirms the runtime is active. Worker initialization is asynchronous; a 503 with body `Worker initializing` is returned if a request arrives before the kernel pool has finished booting — this window is typically sub-second.

## What happens at startup

1. `NexusRunner::run()` creates a `Swoole\Http\Server` in threaded mode (`SWOOLE_THREAD`) with `worker_num` workers.
2. Each worker's `workerStart` event fires a Swoole coroutine that:
   a. Invokes the kernel factory to create a bootstrap `KernelInterface` instance.
   b. Boots the kernel and calls any registered `WorkerStartBootstrapper` services.
   c. Injects `nexus.actor_system` and `nexus.runtime` into the container.
   d. Spawns isolated actors declared with `#[Actor(ActorType::Isolated, 'name')]`.
   e. Spawns `KernelPoolActor` with `kernel_pool_size` child `KernelActor` instances.
   f. Starts the Swoole embedded runtime event loop.
3. Incoming HTTP requests are dispatched via `ActorRef::ask()` to the pool, with a 30-second timeout.
