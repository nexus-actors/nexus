# Getting started

This guide walks through installing and configuring the Nexus Symfony integration, starting the server, and writing a first actor wired into a controller.

## Requirements

Before installing, verify that the runtime environment meets these prerequisites:

| Requirement | Minimum version | Notes |
|---|---|---|
| PHP | 8.5 (ZTS build) | ZTS (Zend Thread Safety) is required for Swoole thread mode. Verify with `php -i \| grep "Thread Safety"`. |
| Swoole | 6.0 | Must be compiled with `--enable-swoole-thread`. Verify with `php --ri swoole \| grep "thread"`. |
| Symfony | 7.0 | The bundle uses the Symfony Runtime component. |

> **Caution:** Standard FPM or NTS PHP builds will not work. The Swoole thread mode (`SWOOLE_THREAD`) that powers the Nexus runtime requires a ZTS PHP binary. Consult the project's Docker setup for a reference image.

---

## Step 1: Install the package

```bash
composer require monadial/nexus-symfony
```

This pulls in `monadial/nexus-core`, `monadial/nexus-runtime-swoole`, and `symfony/runtime` as transitive dependencies.

---

## Step 2: Register the bundle

Add `NexusBundle` to `config/bundles.php`:

```php
<?php

return [
    Monadial\Nexus\Symfony\NexusBundle::class => ['all' => true],
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ... other bundles
];
```

The bundle registers compiler passes that scan for `#[Actor]`-annotated classes, configure synthetic services, and wire autoconfiguration tags.

---

## Step 3: Configure the bundle

Create `config/packages/nexus.yaml`:

```yaml
nexus:
    # Name used to identify the ActorSystem in logs and tracing output.
    # Each worker creates an ActorSystem named "{name}-worker-{id}".
    name: my-app

    # Seconds to wait for in-flight actor messages to drain on SIGTERM.
    # After this timeout the actor system forces a stop.
    shutdown_timeout: 30
```

Both keys are optional. The defaults are `name: app` and `shutdown_timeout: 30`.

---

## Step 4: Configure the entry point

The standard Symfony entry point in `public/index.php` requires no special modifications. The file must return a callable that the Runtime component invokes to obtain a kernel:

```php
<?php

declare(strict_types=1);

use App\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static fn(array $context): Kernel => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
```

`NexusRuntime::getResolver()` captures this closure. `NexusRuntime::getRunner()` passes it to `NexusRunner`, which invokes it once per worker thread to boot the management kernel and once per kernel pool slot to boot each request-handling kernel.

---

## Step 5: Set the runtime environment variables

Set `APP_RUNTIME` and `APP_RUNTIME_OPTIONS` wherever the environment is configured. In `.env.local` for local development:

```dotenv
APP_RUNTIME=Monadial\Nexus\Symfony\Runtime\NexusRuntime
APP_RUNTIME_OPTIONS={"workers":4,"kernel_pool_size":4,"kernel_pool_max_pending":100}
```

In a Docker Compose service:

```yaml
environment:
    APP_RUNTIME: Monadial\Nexus\Symfony\Runtime\NexusRuntime
    APP_RUNTIME_OPTIONS: '{"workers":4,"kernel_pool_size":4,"kernel_pool_max_pending":100}'
```

`APP_RUNTIME_OPTIONS` is a JSON object. All keys are optional and fall back to built-in defaults. See [Runtime options reference](runtime.md#app_runtime_options-reference) for the full table.

---

## Step 6: Start the server

```bash
php -d memory_limit=512M public/index.php
```

For production configurations with larger pools:

```bash
php -d memory_limit=2G public/index.php
```

With Docker Compose:

```bash
docker compose up app
```

A successful startup prints output similar to:

```
Swoole v6.x is available
Swoole\Http\Server: worker_num=4, hook_flags=...
Worker 0 started
Worker 1 started
Worker 2 started
Worker 3 started
```

Worker initialization is asynchronous. The pool kernels boot in the background after the `workerStart` event fires. Requests arriving during this sub-second window receive `HTTP 503 Worker initializing`. The server is ready for traffic once all workers have set their `poolRef`.

---

## Step 7: First actor — a counter

This section walks through creating an isolated actor that maintains a counter, exposing it via an HTTP endpoint.

### Define the messages

Messages are `readonly` classes. The Nexus Psalm plugin enforces this when static analysis is enabled.

```php
<?php
// src/Actor/Message/Increment.php
declare(strict_types=1);

namespace App\Actor\Message;

readonly class Increment {}
```

```php
<?php
// src/Actor/Message/GetCount.php
declare(strict_types=1);

namespace App\Actor\Message;

readonly class GetCount {}
```

```php
<?php
// src/Actor/Message/CountResult.php
declare(strict_types=1);

namespace App\Actor\Message;

readonly class CountResult
{
    public function __construct(public readonly int $value) {}
}
```

### Define the actor

Annotate the class with `#[Actor(ActorType::Isolated, 'name')]`. The `name` argument determines both the actor path within the system and the service ID used for injection (`nexus.actor_ref.counter`).

```php
<?php
// src/Actor/CounterActor.php
declare(strict_types=1);

namespace App\Actor;

use App\Actor\Message\CountResult;
use App\Actor\Message\GetCount;
use App\Actor\Message\Increment;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Symfony\Attribute\Actor;
use Monadial\Nexus\Symfony\Attribute\ActorType;

#[Actor(ActorType::Isolated, 'counter')]
final class CounterActor implements ActorHandler
{
    private int $count = 0;

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return match (true) {
            $message instanceof Increment => $this->onIncrement(),
            $message instanceof GetCount  => $this->onGetCount($ctx),
            default                       => Behavior::unhandled(),
        };
    }

    private function onIncrement(): Behavior
    {
        $this->count++;

        return Behavior::same();
    }

    private function onGetCount(ActorContext $ctx): Behavior
    {
        $ctx->reply(new CountResult($this->count));

        return Behavior::same();
    }
}
```

> **Note:** `ActorType::Isolated` means one actor instance per worker. Each worker has its own counter. If global state across workers is required, use a shared external store (Redis, a database) rather than actor-local state.

### Inject the actor reference into a controller

Use `#[Autowire(service: 'nexus.actor_ref.counter')]` to inject the `ActorRef`. The service ID is derived from the `name` argument of the `#[Actor]` attribute.

```php
<?php
// src/Controller/CounterController.php
declare(strict_types=1);

namespace App\Controller;

use App\Actor\Message\CountResult;
use App\Actor\Message\GetCount;
use App\Actor\Message\Increment;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class CounterController extends AbstractController
{
    public function __construct(
        #[Autowire(service: 'nexus.actor_ref.counter')]
        private readonly ActorRef $counter,
    ) {}

    #[Route('/counter/increment', methods: ['POST'])]
    public function increment(): JsonResponse
    {
        $this->counter->tell(new Increment());

        return new JsonResponse(['status' => 'incremented'], 202);
    }

    /** @return Future<JsonResponse> */
    #[Route('/counter', methods: ['GET'])]
    public function get(): Future
    {
        return $this->counter
            ->ask(new GetCount(), Duration::seconds(5))
            ->map(static fn(CountResult $result) => new JsonResponse(['count' => $result->value]));
    }
}
```

`tell()` is fire-and-forget — the controller returns immediately with a 202. `ask()` returns a `Future<JsonResponse>`. The `FutureResponseListener` registered by `NexusBundle` intercepts the `Future` on the `kernel.view` event and calls `await()`, which suspends the current Swoole coroutine until the actor replies. Other requests continue to be served while this coroutine is suspended.

---

## Step 8: Verify

Start the server and send requests:

```bash
# Increment three times
curl -s -X POST http://localhost:8080/counter/increment
curl -s -X POST http://localhost:8080/counter/increment
curl -s -X POST http://localhost:8080/counter/increment

# Read the current count
curl -s http://localhost:8080/counter
```

Expected response from the final call:

```json
{"count":3}
```

> **Note:** Because `ActorType::Isolated` creates one actor per worker, repeated requests may be routed to different workers. If the server runs with `workers: 4`, each worker maintains its own counter. For cross-worker totals, aggregate the per-worker values or use a shared external store.

---

## Common issues

| Symptom | Cause | Resolution |
|---|---|---|
| `The "nexus.actor_system" service is synthetic and cannot be used at this stage.` | A service references `ActorSystem` during DI compilation rather than at runtime. | Ensure `ActorSystem` is only used inside request handlers, actor constructors, or `WorkerStartBootstrapper` implementations — never in compiler passes or static factory methods. |
| `HTTP 503 Worker initializing` on first requests | The kernel pool has not finished booting. | This is expected during the first sub-second after startup. Add a readiness probe that retries for 2–5 seconds before declaring the pod healthy. |
| Memory exhaustion / OOM kill | The `memory_limit` is too low for the configured pool size. | Calculate `workers × (kernel_pool_size + 1) × per_kernel_MB × 1.5` and set `memory_limit` above that figure. See [Memory considerations](runtime.md#memory-considerations). |
| `Failed opening required '...'` for the entry script | `SCRIPT_FILENAME` was a relative path and the worker thread has a different CWD. | `NexusRunner` makes the path absolute automatically. If the error persists, pass an absolute path explicitly: `php -d memory_limit=2G /var/www/public/index.php`. |
| `Swoole\Exception: API must be called in the coroutine` | A service calls `Coroutine::getCid()` or similar before the coroutine starts. | Call coroutine APIs only inside request handlers or actor `handle()` methods, not in service constructors. |
| Actor messages are not `readonly` | The Nexus Psalm plugin reports a `ReadonlyMessageRule` violation. | Declare message classes with the `readonly` modifier: `readonly class MyMessage { ... }`. |
