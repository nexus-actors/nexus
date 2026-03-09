# Configuration reference

Complete reference for all configuration surfaces exposed by the Nexus Symfony integration.

---

## Bundle configuration (nexus.yaml)

The bundle configuration lives in `config/packages/nexus.yaml` and is processed by `NexusExtension` during container compilation.

```yaml
nexus:
    # ActorSystem name. Used in log context and actor path prefixes.
    # Each worker creates a system named "{name}-worker-{workerId}".
    # Type: string
    # Default: "app"
    name: my-app

    # Seconds to wait for actors to finish processing when SIGTERM is received.
    # After this timeout, ActorSystem::shutdown() forces a stop regardless
    # of in-flight messages.
    # Type: int
    # Default: 30
    shutdown_timeout: 30

    # Kernel pool configuration.
    # These values are available in the DI container as parameters but do NOT
    # automatically propagate to APP_RUNTIME_OPTIONS. Configure the runtime
    # pool sizes via APP_RUNTIME_OPTIONS (see below).
    kernel_pool:
        # Maximum number of Symfony kernel instances per worker.
        # Type: int
        # Default: 8
        size: 8

        # Maximum number of requests that may queue while all kernels are busy.
        # Requests arriving when the queue is full receive HTTP 503 immediately.
        # Type: int
        # Default: 100
        max_pending: 100
```

### Full annotated example

```yaml
nexus:
    name: order-service
    shutdown_timeout: 60
    kernel_pool:
        size: 16
        max_pending: 200
```

### Container parameters set by the bundle

| Parameter | Type | Source |
|---|---|---|
| `nexus.app_name` | string | `nexus.name` |
| `nexus.shutdown_timeout` | int | `nexus.shutdown_timeout` |
| `nexus.isolated_actors` | `array<string, string>` | Set by `ActorRegistrationPass` at compile time. Maps actor name to props factory service ID. |
| `nexus.worker_start_bootstrappers` | `list<string>` | Set by `WorkerStartBootstrapperPass` at compile time. Ordered list of service IDs implementing `WorkerStartBootstrapper`. |

---

## APP_RUNTIME_OPTIONS

`APP_RUNTIME_OPTIONS` is a JSON object consumed by `NexusRuntime` at process startup. It controls how `NexusRunner` configures the Swoole server and kernel pool. These options are entirely separate from the bundle's `nexus.yaml` configuration and take effect before the Symfony kernel is booted.

```bash
APP_RUNTIME_OPTIONS='{"workers":4,"kernel_pool_size":8,"kernel_pool_max_pending":100,"host":"0.0.0.0","port":8080}'
```

| Option | Type | Default | Description | Tuning guidance |
|---|---|---|---|---|
| `host` | string | `0.0.0.0` | IP address the Swoole server binds to. | Use `127.0.0.1` when a reverse proxy (nginx, Caddy) sits in front. Use `0.0.0.0` to accept connections on all interfaces. |
| `port` | int | `8080` | TCP port the server listens on. | Avoid privileged ports (below 1024) unless running as root. Use a reverse proxy to expose port 80/443. |
| `workers` | int | `4` | Number of Swoole worker threads. | Set to the number of available CPU cores. For pure I/O-bound workloads increase if CPU utilization is low; for CPU-bound workloads keep at or below core count. |
| `kernel_pool_size` | int | `8` | Symfony kernel instances per worker. | Each kernel is one DI container in memory. Start at 8 for I/O-bound applications. Increase until p99 latency stops improving or memory pressure becomes a concern. |
| `kernel_pool_max_pending` | int | `100` | Request queue depth per worker when all kernels are busy. | Set to `2 × kernel_pool_size` as a baseline. Increase for bursty traffic patterns; monitor p99 latency to ensure queued requests are not degrading the tail. |

### Memory formula

```
memory_limit >= workers × (kernel_pool_size + 1) × per_kernel_MB × 1.5
```

The `+ 1` accounts for the management kernel booted per worker in addition to the pool kernels.

### Example configurations

**Minimal development setup (low memory, single core):**

```bash
APP_RUNTIME_OPTIONS='{"workers":2,"kernel_pool_size":2,"kernel_pool_max_pending":20}'
```

```bash
php -d memory_limit=256M public/index.php
```

**I/O-bound production (8-core server, database-heavy):**

```bash
APP_RUNTIME_OPTIONS='{"workers":8,"kernel_pool_size":16,"kernel_pool_max_pending":200}'
```

```bash
php -d memory_limit=4G public/index.php
```

**CPU-bound production (8-core server, computation-heavy):**

```bash
APP_RUNTIME_OPTIONS='{"workers":8,"kernel_pool_size":2,"kernel_pool_max_pending":50}'
```

```bash
php -d memory_limit=1G public/index.php
```

---

## Environment variables

| Variable | Required | Description |
|---|---|---|
| `APP_RUNTIME` | Yes | Must be set to `Monadial\Nexus\Symfony\Runtime\NexusRuntime` to activate the Nexus runtime. |
| `APP_RUNTIME_OPTIONS` | No | JSON object of runtime options. All keys optional; missing keys use built-in defaults. |
| `APP_ENV` | Yes (Symfony standard) | Symfony environment (`dev`, `prod`, `test`). Passed to the kernel factory closure via `$context['APP_ENV']`. |
| `APP_DEBUG` | No (Symfony standard) | Enables Symfony debug mode. Set to `0` in production. |

---

## Service IDs

Services registered in the Symfony DI container by the bundle.

| Service ID | Type | Scope | Description |
|---|---|---|---|
| `nexus.actor_system` | `Monadial\Nexus\Core\Actor\ActorSystem` | Per-worker synthetic | The `ActorSystem` for the current worker. Set on the container by `NexusRunner` during `workerStart`. Not available during container compilation. Also aliased to `ActorSystem::class`. |
| `nexus.runtime` | `Monadial\Nexus\Runtime\Runtime\Runtime` | Per-worker synthetic | The `SwooleEmbeddedRuntime` for the current worker. Set on the container by `NexusRunner` during `workerStart`. Also aliased to `Runtime::class`. |
| `nexus.actor_ref.{name}` | `Monadial\Nexus\Core\Actor\ActorRef` | Per-worker synthetic | `ActorRef` for the isolated actor named `{name}`. Set on the container by `NexusRunner` after spawning the actor. Inject using `#[Autowire(service: 'nexus.actor_ref.{name}')]`. |
| `nexus.coroutine_scope` | `Monadial\Nexus\Symfony\Coroutine\CoroutineScope` | Shared | Coroutine-local scope manager. Provides `initialize(array $factories)` and `get(string $id)` for per-coroutine service instances. |
| `nexus.envelope_context` | `Monadial\Nexus\Symfony\Actor\EnvelopeContext` | Shared | Holds the current actor `Envelope` for the active coroutine. Used by tracing infrastructure to propagate `requestId`, `correlationId`, and `causationId` across the request lifecycle. |
| `nexus.coroutine_context` | `Monadial\Nexus\Symfony\Coroutine\SwooleCoroutineContext` | Shared (internal) | Low-level access to the Swoole coroutine local storage map. Used internally by `CoroutineScope` and `EnvelopeContext`. |

### Accessing ActorSystem and Runtime

Both services are autowired by type:

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Runtime\Runtime;

final class MyService
{
    public function __construct(
        private readonly ActorSystem $actorSystem,
        private readonly Runtime $runtime,
    ) {}
}
```

> **Caution:** These are synthetic services. They are `null` during container compilation and before `NexusRunner` sets them in `workerStart`. Never access them in compiler passes, kernel event listeners that fire during compilation, or in `WorkerStartBootstrapper::onWorkerStart()` before the runner has injected them. The injection order within `NexusRunner` is: bootstrappers → set `nexus.actor_system` / `nexus.runtime` → boot isolated actors → spawn kernel pool → start runtime.

---

## Tagged services

| Tag | Autoconfigured from | Description |
|---|---|---|
| `nexus.worker_start` | `Monadial\Nexus\Symfony\Runtime\WorkerStartBootstrapper` | Services with this tag have `onWorkerStart(ContainerInterface $container, int $workerId)` called once per worker thread during the `workerStart` event, before any HTTP requests are served. The invocation order matches the service priority; services without explicit priority are called in the order they are resolved. |
| `nexus.actor` | `#[Actor]` attribute | Applied automatically by `ActorRegistrationPass` to classes annotated with `#[Actor(ActorType::Isolated, 'name')]`. The pass also registers the corresponding `ActorPropsFactory` and synthetic `ActorRef` definitions. Do not apply this tag manually. |

### Implementing WorkerStartBootstrapper

```php
use Monadial\Nexus\Symfony\Runtime\WorkerStartBootstrapper;
use Psr\Container\ContainerInterface;

final class RedisPoolBootstrapper implements WorkerStartBootstrapper
{
    public function onWorkerStart(ContainerInterface $container, int $workerId): void
    {
        // Initialize a Swoole coroutine-safe connection pool for this worker.
        // Resources initialized here are available to all coroutines in the worker.
        RedisPool::initialize(size: 16, workerId: $workerId);
    }
}
```

Autoconfiguration registers the `nexus.worker_start` tag automatically when the class implements `WorkerStartBootstrapper`. To tag explicitly:

```yaml
# config/services.yaml
App\Infrastructure\RedisPoolBootstrapper:
    tags:
        - { name: nexus.worker_start }
```

### Declaring isolated actors

The `#[Actor]` attribute marks a class as an isolated actor. It is picked up by `ActorRegistrationPass` at compile time.

```php
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Symfony\Attribute\Actor;
use Monadial\Nexus\Symfony\Attribute\ActorType;

#[Actor(ActorType::Isolated, 'my-actor')]
final class MyActor implements ActorHandler
{
    // ...
}
```

`ActorType::Isolated` is the only supported type for auto-discovered actors. It spawns one actor instance per worker at startup, injected into the container at `nexus.actor_ref.my-actor`.

Inject by service ID:

```php
use Monadial\Nexus\Core\Actor\ActorRef;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MyController
{
    public function __construct(
        #[Autowire(service: 'nexus.actor_ref.my-actor')]
        private readonly ActorRef $myActor,
    ) {}
}
```

---

## Compiler passes

The following compiler passes are registered by `NexusBundle::build()` and run during container compilation. They are not configurable directly but their effects are visible through the parameters and service IDs they produce.

| Compiler pass | Effect |
|---|---|
| `ActorRegistrationPass` | Scans all service definitions for `#[Actor(Isolated, name)]`. Registers `ActorPropsFactory` services and synthetic `ActorRef` definitions. Writes the `nexus.isolated_actors` parameter. |
| `WorkerStartBootstrapperPass` | Collects all services tagged `nexus.worker_start`. Writes the `nexus.worker_start_bootstrappers` parameter as an ordered list of service IDs. |
| `CoroutineScopedPass` | Processes services tagged `nexus.coroutine_scoped` (applied via the `#[CoroutineScoped]` attribute). Rewires them to be resolved from `nexus.coroutine_scope` at runtime rather than the shared container, ensuring a fresh instance per Swoole coroutine. |
| `GlobalActorPass` | Handles `ActorType::Shared` actors (reserved for future cross-worker actor addressing). Has no effect on `Isolated` actors. |
