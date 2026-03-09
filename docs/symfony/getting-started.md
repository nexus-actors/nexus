# Getting started

This guide walks through every step needed to run a Nexus-powered Symfony application: verifying the runtime environment, installing the bundle, configuring the server, and writing a first actor wired into an HTTP controller.

---

## Prerequisites

### PHP 8.5 (ZTS build)

Nexus uses Swoole's thread mode (`SWOOLE_THREAD`), which requires a **ZTS** (Zend Thread Safety) PHP binary. Standard FPM or NTS builds will not work.

Verify the build:

```bash
php -i | grep 'Thread Safety'
```

Expected output:

```
Thread Safety => enabled
```

If the output reads `disabled`, install a ZTS PHP build. Consult the project's [Docker setup](../../docker/Dockerfile) for a reference image that includes PHP 8.5 ZTS + Swoole.

### Swoole 6.0 (with `--enable-swoole-thread`)

Verify Swoole is installed and was compiled with thread support:

```bash
php --ri swoole | grep -E 'swoole|thread'
```

Expected output includes:

```
swoole => enabled
Version => 6.x.x
...
thread => enabled
```

If `thread => enabled` is missing, Swoole was compiled without `--enable-swoole-thread`. Recompile or install a pre-built image that includes thread support.

### Symfony 7.x

The bundle uses the Symfony Runtime component introduced in Symfony 6.x and is tested against Symfony 7.x.

```bash
php bin/console --version
```

Expected output:

```
Symfony 7.x.x (env: dev, debug: true)
```

---

## Step 1: Install the bundle

```bash
composer require monadial/nexus-symfony
```

This pulls in the following packages as transitive dependencies:

| Package | Role |
|---|---|
| `monadial/nexus-core` | Actor model: `ActorSystem`, `ActorRef`, `Behavior`, `Props` |
| `monadial/nexus-runtime-swoole` | Swoole coroutine runtime |
| `symfony/runtime` | Symfony Runtime component (entry-point abstraction) |

---

## Step 2: Register the bundle

Add `NexusBundle` to `config/bundles.php`:

```php
<?php

declare(strict_types=1);

return [
    Monadial\Nexus\Symfony\NexusBundle::class    => ['all' => true],
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ... other bundles
];
```

The bundle registers four compiler passes during container compilation:

- **`ActorRegistrationPass`** — scans for `#[Actor]`-annotated classes and registers synthetic `ActorRef` services.
- **`WorkerStartBootstrapperPass`** — collects services implementing `WorkerStartBootstrapper` for per-worker initialization.
- **`CoroutineScopedPass`** — rewires `#[CoroutineScoped]` services to be isolated per Swoole coroutine.
- **`GlobalActorPass`** — reserved for future cross-worker actor addressing.

---

## Step 3: Configure the runtime

The Symfony Runtime component delegates process bootstrap to a pluggable `Runtime` class. Replace the standard `public/index.php` with the following:

```php
<?php

declare(strict_types=1);

use App\Kernel;
use Monadial\Nexus\Symfony\Runtime\NexusRuntime;

$_SERVER['APP_RUNTIME'] = NexusRuntime::class;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static fn(array $context): Kernel => new Kernel(
    $context['APP_ENV'],
    (bool) $context['APP_DEBUG'],
);
```

`NexusRuntime` captures the kernel factory closure. At startup it passes the closure to `NexusRunner`, which:

1. Starts a `Swoole\Http\Server` in `SWOOLE_THREAD` mode.
2. On each `workerStart` event, boots an `ActorSystem` backed by `SwooleEmbeddedRuntime`.
3. Spawns a `KernelPoolActor` that manages N independent Symfony kernel instances per worker.
4. Spawns all actors declared with `#[Actor(ActorType::Isolated, ...)]`.
5. Injects synthetic services (`nexus.actor_system`, `nexus.runtime`, `nexus.actor_ref.*`) into the kernel DI container.

Workers do not share memory. Each worker thread is a fully isolated PHP environment.

---

## Step 4: Create the bundle configuration

Create `config/packages/nexus.yaml`:

```yaml
nexus:
    # ActorSystem name. Each worker creates a system named "{name}-worker-{id}".
    name: my-app

    # Seconds to wait for in-flight actor messages to drain on SIGTERM.
    shutdown_timeout: 30

    kernel_pool:
        # Symfony kernel instances per worker. One kernel handles at most one
        # request at a time; concurrency equals this value.
        size: 8

        # Requests queued while all kernels are busy. Requests that exceed
        # this limit receive HTTP 503 immediately.
        max_pending: 100
```

All keys have defaults (`name: app`, `shutdown_timeout: 30`, `size: 8`, `max_pending: 100`) and can be omitted entirely for a minimal setup.

---

## Step 5: Set the runtime environment variable

`NexusRuntime` is activated via the `APP_RUNTIME` environment variable. Server options are passed as a JSON object in `APP_RUNTIME_OPTIONS`.

Add the following to `.env.local` for local development:

```dotenv
APP_RUNTIME=Monadial\Nexus\Symfony\Runtime\NexusRuntime
APP_RUNTIME_OPTIONS={"workers":4,"kernel_pool_size":8,"kernel_pool_max_pending":100,"host":"0.0.0.0","port":8080}
```

Available `APP_RUNTIME_OPTIONS` keys:

| Key | Default | Description |
|---|---|---|
| `host` | `0.0.0.0` | IP address the server binds to. |
| `port` | `8080` | TCP port the server listens on. |
| `workers` | `4` | Number of Swoole worker threads. Set to CPU core count. |
| `kernel_pool_size` | `8` | Symfony kernel instances per worker. |
| `kernel_pool_max_pending` | `100` | Request queue depth per worker when all kernels are busy. |

See [configuration-reference.md](configuration-reference.md) for the complete reference and memory sizing formula.

---

## Step 6: Start the server

```bash
php -d memory_limit=512M public/index.php
```

A successful startup prints output similar to:

```
Swoole v6.x.x is available
[2026-03-09 12:00:00] Nexus server starting on 0.0.0.0:8080
[2026-03-09 12:00:00] Worker 0 started — ActorSystem nexus-worker-0 ready
[2026-03-09 12:00:00] Worker 1 started — ActorSystem nexus-worker-1 ready
[2026-03-09 12:00:00] Worker 2 started — ActorSystem nexus-worker-2 ready
[2026-03-09 12:00:00] Worker 3 started — ActorSystem nexus-worker-3 ready
```

Worker initialization is asynchronous. The kernel pool instances boot in the background after each `workerStart` event fires. Requests arriving during this brief sub-second window receive `HTTP 503 Worker initializing`. The server is ready once all workers have completed pool initialization.

---

## Step 7: Verify the server responds

```bash
curl -s http://localhost:8080/
```

A Symfony application with a route at `/` returns its response. Without any application routes, Symfony returns a `404` response — this confirms the server is up and routing through the Symfony kernel:

```json
{"status": 404, "message": "No route found for \"GET /\""}
```

---

## Step 8: Add a first actor

This section walks through a complete, self-contained example: a product catalog actor that caches lookups in memory and serves them via an HTTP endpoint.

### Define the messages

Actor messages are `readonly` classes. The Nexus Psalm plugin enforces this constraint when static analysis is enabled.

**`src/Actor/Message/GetProduct.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actor\Message;

final readonly class GetProduct
{
    public function __construct(public string $id) {}
}
```

**`src/Actor/Message/ProductFound.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actor\Message;

final readonly class ProductFound
{
    public function __construct(
        public string $id,
        public string $name,
        public float $price,
    ) {}
}
```

**`src/Actor/Message/ProductNotFound.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actor\Message;

final readonly class ProductNotFound
{
    public function __construct(public string $id) {}
}
```

### Define the actor

Annotate the class with `#[Actor(ActorType::Isolated, 'catalog')]`. The second argument is the **actor name** — it determines the actor path within the system and the service ID used for injection (`nexus.actor_ref.catalog`).

**`src/Actor/CatalogActor.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actor;

use App\Actor\Message\GetProduct;
use App\Actor\Message\ProductFound;
use App\Actor\Message\ProductNotFound;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Symfony\Attribute\Actor;
use Monadial\Nexus\Symfony\Attribute\ActorType;

#[Actor(ActorType::Isolated, 'catalog')]
final class CatalogActor implements ActorHandler
{
    /** @var array<string, array{name: string, price: float}> */
    private array $catalog = [
        'chair-001' => ['name' => 'Ergonomic Chair', 'price' => 299.99],
        'desk-001'  => ['name' => 'Standing Desk',   'price' => 499.99],
        'hub-001'   => ['name' => 'USB-C Hub',        'price' => 79.99],
    ];

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if ($message instanceof GetProduct) {
            return $this->handleGetProduct($ctx, $message);
        }

        return Behavior::unhandled();
    }

    private function handleGetProduct(ActorContext $ctx, GetProduct $message): Behavior
    {
        $entry = $this->catalog[$message->id] ?? null;

        if ($entry === null) {
            $ctx->reply(new ProductNotFound($message->id));

            return Behavior::same();
        }

        $ctx->reply(new ProductFound($message->id, $entry['name'], $entry['price']));

        return Behavior::same();
    }
}
```

`ActorType::Isolated` means one actor instance is spawned per Swoole worker at startup. State is local to that worker. No cross-worker sharing occurs.

`$ctx->reply()` sends the response back to the `ask()` caller. `Behavior::same()` returns control to the actor system and keeps the current behavior active.

### Wire the actor into a controller

Inject the `ActorRef` using `#[Autowire(service: 'nexus.actor_ref.catalog')]`. The service ID is always `nexus.actor_ref.{name}` where `{name}` matches the second argument of `#[Actor]`.

**`src/Controller/CatalogController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Actor\Message\GetProduct;
use App\Actor\Message\ProductFound;
use App\Actor\Message\ProductNotFound;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends AbstractController
{
    public function __construct(
        #[Autowire(service: 'nexus.actor_ref.catalog')]
        private readonly ActorRef $catalogActor,
    ) {}

    /** @return Future<JsonResponse> */
    #[Route('/catalog/{id}', methods: ['GET'])]
    public function show(string $id): Future
    {
        return $this->catalogActor
            ->ask(new GetProduct($id), Duration::seconds(5))
            ->map(static function (ProductFound|ProductNotFound $result) use ($id): JsonResponse {
                if ($result instanceof ProductNotFound) {
                    return new JsonResponse(
                        ['error' => "Product {$id} not found"],
                        Response::HTTP_NOT_FOUND,
                    );
                }

                return new JsonResponse([
                    'id'    => $result->id,
                    'name'  => $result->name,
                    'price' => $result->price,
                ]);
            });
    }
}
```

`ask()` returns a `Future<T>`. The `FutureResponseListener` registered by `NexusBundle` intercepts the `Future` on the `kernel.view` event and calls `await()`, which suspends the current Swoole coroutine until the actor replies. Other coroutines — serving other concurrent requests — continue running while this one waits.

### Test the actor endpoint

Restart the server, then send a request:

```bash
curl -s http://localhost:8080/catalog/chair-001
```

Expected response:

```json
{"id": "chair-001", "name": "Ergonomic Chair", "price": 299.99}
```

Request for an unknown product:

```bash
curl -s http://localhost:8080/catalog/unknown-id
```

Expected response (HTTP 404):

```json
{"error": "Product unknown-id not found"}
```

---

## Step 9: Docker quick-start

The following `Dockerfile` and `docker-compose.yml` provide a minimal containerized setup for local development and CI.

### `docker/Dockerfile`

```dockerfile
FROM php:8.5-cli-zts AS php-swoole

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# Install Swoole with thread support.
RUN pecl install swoole-6.0.0 \
    && docker-php-ext-enable swoole

# Install Composer.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
```

### `docker-compose.yml`

```yaml
services:
    app:
        build:
            context: .
            dockerfile: docker/Dockerfile
            target: php-swoole
        ports:
            - "8080:8080"
        volumes:
            - .:/app
        working_dir: /app
        environment:
            APP_DEBUG: "0"
            APP_ENV: prod
            APP_RUNTIME: Monadial\Nexus\Symfony\Runtime\NexusRuntime
            APP_RUNTIME_OPTIONS: '{"workers":4,"kernel_pool_size":8,"kernel_pool_max_pending":100,"host":"0.0.0.0","port":8080}'
            APP_SECRET: change-me-in-production
        command: php -d memory_limit=512M public/index.php
```

Start the stack:

```bash
docker compose up --build
```

Once running, verify:

```bash
curl -s http://localhost:8080/catalog/chair-001
```

---

## Common issues

| Symptom | Cause | Resolution |
|---|---|---|
| `Thread Safety => disabled` | NTS PHP binary in use. | Install a ZTS PHP 8.5 build. Verify with `php -i \| grep 'Thread Safety'`. |
| `thread => disabled` in `php --ri swoole` | Swoole compiled without `--enable-swoole-thread`. | Recompile Swoole with the flag or use the project's Docker image. |
| `HTTP 503 Worker initializing` on first requests | Kernel pool is still booting. This is expected for up to ~1 second after startup. | Add a readiness probe that retries for 2–5 seconds before declaring the container healthy. |
| `The "nexus.actor_system" service is synthetic` | A service or compiler pass accesses `ActorSystem` at container compile time. | Access `ActorSystem` only inside request handlers, actor `handle()` methods, or `WorkerStartBootstrapper::onWorkerStart()`. |
| `Swoole\Exception: API must be called in the coroutine` | A service calls a Swoole coroutine API outside of a coroutine context (e.g., in a service constructor). | Move Swoole coroutine calls inside request handlers or actor methods. |
| `AskTimeoutException` | The actor did not reply within the timeout window. | Check that `$ctx->reply()` is called in all branches of the handler. Increase `Duration::seconds(N)` for slow operations. |
| Memory exhaustion / OOM | `memory_limit` is too low for the configured pool size. | Use the formula `workers × (kernel_pool_size + 1) × per_kernel_MB × 1.5` to size the limit. See [configuration-reference.md](configuration-reference.md#memory-formula). |

---

## What's next

| Topic | Guide |
|---|---|
| Actor declaration, DI injection, `ask()` vs `tell()`, lifecycle hooks | [actors-in-symfony.md](actors-in-symfony.md) |
| `KernelPoolActor` internals, backpressure model, crash recovery | [kernel-pool.md](kernel-pool.md) |
| Doctrine and coroutine-safe database access | [doctrine-database.md](doctrine-database.md) |
| Testing actors in isolation and integration tests | [testing.md](testing.md) |
| Production deployment, process supervision, health checks | [deployment.md](deployment.md) |
| All configuration keys and memory sizing | [configuration-reference.md](configuration-reference.md) |
| Performance tuning, benchmarks, worker and pool sizing | [performance.md](performance.md) |
| Diagnosing startup errors, 503s, timeouts | [troubleshooting.md](troubleshooting.md) |
