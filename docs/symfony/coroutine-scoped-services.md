# Coroutine-scoped services

## Why services need coroutine scope

### The PHP-FPM mental model and why it breaks under Swoole

In a PHP-FPM deployment every HTTP request runs in its own OS process. The Symfony service container is constructed fresh for each request and torn down at the end. Stateful services — an `EntityManager` holding an identity map, an HTTP client with an open connection, a `RequestContext` storing the authenticated user — are private to one process. No two requests share an object graph.

Swoole changes this model fundamentally. A single OS process hosts multiple coroutines, each handling a different HTTP request concurrently. The Symfony container is built once at worker startup and reused for every request handled by that worker. A service created the first time the container is asked for it remains in memory indefinitely, serving all subsequent requests in the same worker.

For genuinely stateless services — a `ProductRepository` that holds no state, a `PriceCalculator` that takes inputs and returns outputs — this is desirable. The object is allocated once and reused thousands of times. For stateful services the picture is different.

### The shared-state race

Consider a `RequestTracer` that records the request ID, start time, and current user:

```php
final class RequestTracer
{
    private string $requestId;
    private float  $startedAt;
    private string $userId;

    public function start(string $requestId, string $userId): void
    {
        $this->requestId = $requestId;
        $this->startedAt = microtime(true);
        $this->userId    = $userId;
    }

    public function elapsedMs(): float
    {
        return (microtime(true) - $this->startedAt) * 1000;
    }
}
```

Under PHP-FPM this works correctly. Under Swoole with a shared singleton, two concurrent requests race on the same object:

```
Time ──────────────────────────────────────────────────────────►

Coroutine A (Request 1)          Coroutine B (Request 2)
─────────────────────────────    ──────────────────────────────
$tracer->start('req-111', 'alice')
                                 $tracer->start('req-222', 'bob')
// Swoole suspends A to do I/O
                                 $tracer->elapsedMs()
                                 // reads startedAt written by B — OK so far
// A resumes
$tracer->elapsedMs()
// reads startedAt written by B, not A
// WRONG: measures bob's elapsed time for alice's request

$tracer->userId
// returns 'bob' — alice's log lines now carry the wrong user ID
```

The interleaving is not visible in the code. No mutex, no lock, no warning. The corruption is silent.

The same pattern appears in any service that mutates instance state during a request:

- `EntityManager` — the identity map accumulates objects from multiple requests; `clear()` at the end of one request disrupts another request's unit of work still in flight.
- HTTP client sessions — cookie jars, authentication tokens, keep-alive connections.
- Database connections borrowed without a pool — AUTOCOMMIT state, active transactions, prepared statement handles.
- Monolog channel state — request-scoped log context fields.

### The Swoole coroutine model

Each Swoole coroutine is a lightweight green thread within a single OS thread. Coroutines are cooperative: a coroutine runs exclusively until it yields at a suspend point (a network call, a channel read, `Co::sleep()`). At that point the scheduler switches to a different coroutine.

Every Swoole coroutine has a numeric ID returned by `Coroutine::getCid()`. This ID is unique within a worker and positive for all coroutines; it is `-1` outside a coroutine context (CLI, test runners). The coroutine ID is the natural key for coroutine-local storage.

Swoole provides `Coroutine::getContext()` — an `ArrayObject` private to the current coroutine. Data written to it is invisible to any other coroutine. The data lives for the lifetime of the coroutine and is garbage-collected when the coroutine ends.

```
Worker thread
│
├── Coroutine 1 (Request A)
│   └── Coroutine::getContext()  ← private to coroutine 1
│       ['__nexus_scope__'] = { RequestTracer(userId='alice') }
│
├── Coroutine 2 (Request B)
│   └── Coroutine::getContext()  ← private to coroutine 2
│       ['__nexus_scope__'] = { RequestTracer(userId='bob') }
│
└── Coroutine 3 (Background actor)
    └── Coroutine::getContext()  ← private to coroutine 3
        ['__nexus_scope__'] = { (no RequestTracer — actors don't need it) }
```

`nexus-symfony` uses this isolation primitive as the foundation for coroutine-scoped services.

---

## The coroutine isolation model

### SwooleEmbeddedRuntime and actor coroutines

Inside each Swoole worker, the Nexus runtime is a `SwooleEmbeddedRuntime`. Each actor runs in a dedicated Swoole coroutine. Mailbox reads suspend the coroutine on a Swoole channel; when a message arrives the coroutine resumes. All I/O inside actor handlers is automatically cooperative.

HTTP requests follow a different but related path. The `KernelPoolActor` dispatches each incoming HTTP request to a `KernelActor`, which handles it by calling `HttpKernel::handle()` inside a fresh coroutine spawned for that request. The request lifecycle — Symfony's event dispatch, controller execution, view rendering — runs in that coroutine.

Both paths produce coroutines with unique `getCid()` values, and both can use coroutine-local storage.

### CoroutineContext abstraction

`nexus-symfony` abstracts the coroutine context behind an interface so that the framework code does not have a direct compile-time dependency on Swoole:

```php
interface CoroutineContext
{
    public function current(): ArrayObject;
}
```

The Swoole implementation delegates directly:

```php
final class SwooleCoroutineContext implements CoroutineContext
{
    public function current(): ArrayObject
    {
        return Coroutine::getContext();
    }
}
```

In unit tests outside Swoole a different implementation can be substituted that returns a plain `ArrayObject` stored in a regular variable. This seam is the only place that touches the Swoole API.

---

## The #[CoroutineScoped] attribute

### Declaring a scoped service

Mark a service class with `#[CoroutineScoped]` to request a fresh instance per coroutine:

```php
use Monadial\Nexus\Symfony\Attribute\CoroutineScoped;
use Symfony\Component\Uid\Ulid;

#[CoroutineScoped]
final class RequestContext
{
    public readonly string $requestId;
    public readonly float  $startedAt;

    public function __construct()
    {
        $this->requestId = (string) new Ulid();
        $this->startedAt = microtime(true);
    }

    /** @psalm-suppress InvalidOperand */
    public function elapsedMs(): float
    {
        return (microtime(true) - $this->startedAt) * 1000;
    }
}
```

The constructor runs once per coroutine. `requestId` is unique to the request because `new Ulid()` is called at construction time, not at container build time.

### Compile time: CoroutineScopedPass

`CoroutineScopedPass` is a Symfony `CompilerPassInterface` that runs during `ContainerBuilder::compile()`. It:

1. Scans all service definitions for classes carrying `#[CoroutineScoped]`.
2. Calls `$definition->setShared(false)` on each — making it a prototype-scoped service. Every `$container->get(RequestContext::class)` call produces a new instance.
3. Collects the service IDs into a `ServiceLocator` registered as `nexus.coroutine_scoped_locator`.
4. Records the list of IDs in the container parameter `nexus.coroutine_scoped_services`.

Setting the service as non-shared is the critical step. It prevents the container from caching and returning a previously-created instance.

### Request time: CoroutineScopeListener

`CoroutineScopeListener` is registered as a `kernel.event_listener` for `KernelEvents::REQUEST` at priority 1000. This priority places it before Symfony's security and routing listeners, ensuring scoped services are available for all subsequent event handlers.

On each main request (sub-requests are skipped):

1. The listener iterates `nexus.coroutine_scoped_locator`.
2. For each service ID it calls `$locator->get($id)` — producing a fresh instance via the prototype definition.
3. Each instance is stored in `Coroutine::getContext()['__nexus_scope__'][$id]`.

All instances are created eagerly at request start. There is no lazy resolution within a request.

### Accessing scoped services

`CoroutineScope::get(string $id)` reads from the coroutine context:

```php
// Access via injection — the normal pattern.
final class SomeService
{
    public function __construct(private readonly RequestContext $ctx) {}
}
```

When the Symfony container resolves `SomeService`, it sees that `RequestContext` is non-shared and calls `$container->get(RequestContext::class)`. However, the `CoroutineScopeListener` has already stored the instance for this coroutine. `CoroutineScope` intercepts the resolution and returns the stored instance rather than creating a new one.

This means injecting `RequestContext` into multiple services within the same request always yields the same object — the one created at request start.

### Injecting scoped services into controllers

Standard constructor injection and parameter injection both work:

```php
final class OrderController extends AbstractController
{
    #[Route('/orders', methods: ['POST'])]
    public function place(Request $request, RequestContext $ctx): JsonResponse
    {
        $this->bus->dispatch(new PlaceOrder(/* ... */));

        return new JsonResponse([
            'requestId' => $ctx->requestId,
            'status'    => 'queued',
        ], 202);
    }
}
```

`$ctx` is the instance created when the request coroutine started. No other concurrent request can read or write it.

---

## CoroutineLocalConnection: the pool-backed pattern

Not all coroutine-scoped resources fit the `#[CoroutineScoped]` attribute model. Database connections borrowed from a pool require explicit borrow/return semantics that cannot be expressed as constructor arguments. The `CoroutineLocalConnection` pattern handles this.

### The pattern

A single shared service object holds a `private array $localConnections` keyed by coroutine ID. On the first use within a coroutine, a connection is borrowed from the pool and stored in the map. `Coroutine::defer()` registers a callback that returns the connection to the pool when the coroutine exits.

```php
final class CoroutineLocalConnection implements Connection
{
    /** @var array<int, PDOConnection> */
    private array $localConnections = [];

    /** @var array<int, object> */
    private array $localPdos = [];

    public function __construct(private readonly SwoolePDOPool $pool) {}

    private function inner(): PDOConnection
    {
        $cid = \Swoole\Coroutine::getCid();
        $key = $cid === -1 ? 0 : $cid;

        if (!isset($this->localConnections[$key])) {
            $pdo                          = $this->pool->get();
            $this->localPdos[$key]        = $pdo;
            /** @var \PDO $pdoTyped */
            $pdoTyped                     = $pdo;
            $this->localConnections[$key] = new PDOConnection($pdoTyped);

            if ($cid !== -1) {
                \Swoole\Coroutine::defer(function () use ($key): void {
                    if (isset($this->localPdos[$key])) {
                        $this->pool->put($this->localPdos[$key]);
                        unset($this->localPdos[$key], $this->localConnections[$key]);
                    }
                });
            }
        }

        return $this->localConnections[$key];
    }

    public function prepare(string $sql): Statement
    {
        return $this->inner()->prepare($sql);
    }

    public function query(string $sql): Result
    {
        return $this->inner()->query($sql);
    }

    // ... all remaining Connection interface methods delegate to $this->inner()
}
```

Key properties of this pattern:

- The service object itself is a singleton — it is shared. Only the `$localConnections` and `$localPdos` maps are per-coroutine.
- `Coroutine::defer()` is Swoole's analog of a finally block that runs when the coroutine is destroyed, even if an exception was thrown. This guarantees pool return.
- The fallback branch (`$cid === -1`) allows the same class to be used in CLI scripts or unit tests that run outside a coroutine, using key `0` and never registering a defer callback.

---

## WorkerStartBootstrapper: initializing pools at startup

Connection pools must be created before any request is served. They must not be created at container build time (the container may be built outside a Swoole worker). `WorkerStartBootstrapper` solves this.

### The interface

```php
interface WorkerStartBootstrapper
{
    public function onWorkerStart(ContainerInterface $container, int $workerId): void;
}
```

Implement `WorkerStartBootstrapper` and tag the service with `nexus.worker_start` (autoconfiguration applies the tag automatically). `NexusRunner` calls every registered bootstrapper once per worker at startup, inside a Swoole coroutine, before the first request is accepted.

### ConnectionPoolBootstrapper

The symfony-demo example provides a complete implementation:

```php
final class ConnectionPoolBootstrapper implements WorkerStartBootstrapper
{
    public function onWorkerStart(ContainerInterface $container, int $workerId): void
    {
        $this->initRedisPool();
        $this->initPDOPool();
    }

    private function initRedisPool(): void
    {
        $dsn  = (string) ($_ENV['REDIS_DSN'] ?? 'redis://redis:6379');
        $size = (int) ($_ENV['REDIS_POOL_SIZE'] ?? 16);

        $parsed = $this->parseRedisDsn($dsn);

        SwooleRedisPool::setCurrent(new SwooleRedisPool(
            host:    $parsed['host'],
            port:    $parsed['port'],
            auth:    $parsed['auth'],
            dbIndex: $parsed['db'],
            size:    $size,
        ));
    }

    private function initPDOPool(): void
    {
        $url  = (string) ($_ENV['DATABASE_URL'] ?? '');
        $size = (int) ($_ENV['DB_POOL_SIZE'] ?? 8);

        if ($url === '') {
            return;
        }

        $parsed = $this->parseDatabaseUrl($url);

        if ($parsed === null) {
            return;
        }

        SwoolePDOPool::setCurrent(new SwoolePDOPool(
            driver:   $parsed['driver'],
            host:     $parsed['host'],
            port:     $parsed['port'],
            dbname:   $parsed['dbname'],
            username: $parsed['username'],
            password: $parsed['password'],
            charset:  $parsed['charset'],
            size:     $size,
        ));
    }
}
```

`SwooleRedisPool::setCurrent()` and `SwoolePDOPool::setCurrent()` store the pools as static singletons on their respective classes. This avoids routing the pools through Symfony DI — the pools must be available before some services are fully resolved, so treating them as synthetic container services would create circular initialization problems.

### Pool sizing

The pools default to `16` Redis connections and `8` PDO connections per worker. Tune these via environment variables:

```dotenv
REDIS_POOL_SIZE=24
DB_POOL_SIZE=12
```

The rule of thumb: pool size should be at least the maximum number of coroutines that will hold a connection simultaneously. For an HTTP worker with `kernel_pool_size=8`, no more than 8 requests can be in flight at once. Setting `DB_POOL_SIZE=8` matches exactly. Setting it higher provides a buffer for actor coroutines that also access the database.

The ceiling is the database server's `max_connections` divided by the number of workers:

```
pool_size × workers <= database_max_connections
```

If `workers=4` and `max_connections=100`, each worker can use at most 25 connections.

---

## CoroutineLocalRedisCache

`CoroutineLocalRedisCache` demonstrates the coroutine-local pool pattern applied to a Symfony `TagAwareCacheInterface`:

```php
final class CoroutineLocalRedisCache implements TagAwareCacheInterface
{
    /** @var array<int, TagAwareAdapter> */
    private array $localAdapters = [];

    /** @var array<int, \Redis> */
    private array $localRedis = [];

    private readonly TagAwareAdapter $fallback;

    public function __construct(
        private readonly string $namespace = '',
        private readonly int $defaultLifetime = 0,
    ) {
        $this->fallback = new TagAwareAdapter(
            new FilesystemAdapter($namespace, $defaultLifetime),
        );
    }

    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        return $this->adapter()->get($key, $callback, $beta, $metadata);
    }

    public function delete(string $key): bool
    {
        return $this->adapter()->delete($key);
    }

    public function invalidateTags(array $tags): bool
    {
        return $this->adapter()->invalidateTags($tags);
    }

    private function adapter(): TagAwareAdapter
    {
        $pool = SwooleRedisPool::current();

        if ($pool === null) {
            return $this->fallback;
        }

        $cid = \Swoole\Coroutine::getCid();

        if ($cid === -1) {
            return $this->fallback;
        }

        if (!isset($this->localAdapters[$cid])) {
            $redis = $pool->get();
            $this->localRedis[$cid]    = $redis;
            $this->localAdapters[$cid] = new TagAwareAdapter(
                new RedisAdapter($redis, $this->namespace, $this->defaultLifetime),
            );

            \Swoole\Coroutine::defer(function () use ($cid, $pool): void {
                if (isset($this->localRedis[$cid])) {
                    $pool->put($this->localRedis[$cid]);
                    unset($this->localRedis[$cid], $this->localAdapters[$cid]);
                }
            });
        }

        return $this->localAdapters[$cid];
    }
}
```

The `adapter()` method follows the same pattern as `CoroutineLocalConnection::inner()`:

1. Check whether a pool exists. If not, fall back to `FilesystemAdapter`.
2. Check whether a coroutine context exists (`getCid() !== -1`). If not, fall back.
3. Borrow a `\Redis` connection on first use within the coroutine.
4. Build a `TagAwareAdapter` wrapping a `RedisAdapter` backed by that connection.
5. Register `Coroutine::defer()` to return the connection when the coroutine ends.

The fallback to `FilesystemAdapter` is deliberate: it allows `CoroutineLocalRedisCache` to be used without modification in CLI commands (`bin/console cache:clear`) and unit tests that run under FiberRuntime or StepRuntime.

---

## Registering coroutine-scoped services

### services.yaml

Services implementing `WorkerStartBootstrapper` are autoconfigured and tagged automatically. Other services need explicit registration only when they are not auto-discovered or when aliases are required:

```yaml
# config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\:
        resource: '../src/'
        exclude:
            - '../src/Entity/'
            - '../src/Kernel.php'

    # Coroutine-safe Redis cache — borrows from SwooleRedisPool per coroutine,
    # falls back to FilesystemAdapter outside Swoole worker context.
    App\Cache\CoroutineLocalRedisCache: ~

    # Wire CacheInterface to the pooled Redis adapter.
    Symfony\Contracts\Cache\CacheInterface:
        alias: App\Cache\CoroutineLocalRedisCache

    # Register DBAL middleware via doctrine.middleware tag.
    App\Doctrine\SwoolePoolMiddleware:
        tags:
            - { name: doctrine.middleware }
```

Services decorated with `#[CoroutineScoped]` (like `RequestContext`) require no explicit registration. Autodiscovery picks them up; `CoroutineScopedPass` handles the rest during container compilation.

### NexusExtension internal registration

`NexusExtension` registers the coroutine infrastructure services automatically:

```
nexus.coroutine_context      → SwooleCoroutineContext (non-public)
nexus.coroutine_scope        → CoroutineScope (depends on coroutine_context)
nexus.coroutine_scope_listener → CoroutineScopeListener (kernel.event_listener)
nexus.envelope_context       → EnvelopeContext (depends on coroutine_context)
```

Application code never needs to interact with these services directly. They operate as infrastructure wiring below the application layer.

---

## Common pitfalls

### Doctrine's shared identity map

Doctrine's `EntityManager` maintains an identity map: a cache of all entities loaded in the current unit of work. Under FPM this map is cleared at process end. Under Swoole with a shared singleton, the identity map accumulates entities from every request that has ever run in the worker.

A later request asking for `$em->find(Order::class, $id)` may receive a stale cached entity from an earlier request, bypassing the database entirely. Changes made by one request — even committed changes — may be invisible to another request that loaded the same entity before the commit.

The `SwoolePoolMiddleware` + `CoroutineLocalConnection` pattern solves the connection layer. For the identity map, call `$em->clear()` at the end of each request, or use Doctrine's `reset()` method if available. An event listener on `KernelEvents::TERMINATE` is a suitable location.

### Connection state leaking between coroutines

A borrowed PDO connection carries state: transaction mode, session variables, character set. If a coroutine exits abnormally — exception thrown mid-transaction — the PDO connection returned to the pool may still have `AUTOCOMMIT=0` and an open transaction.

`CoroutineLocalConnection` uses `Coroutine::defer()` for return, which runs on all exit paths. However, the defer callback does not issue a `ROLLBACK`. The next coroutine that borrows the same connection will receive it mid-transaction.

Mitigate this by rolling back explicitly in error paths:

```php
try {
    $conn->beginTransaction();
    // ... work ...
    $conn->commit();
} catch (\Throwable $e) {
    $conn->rollBack();
    throw $e;
}
```

Alternatively, reset the connection state in the pool's borrow logic, or use separate connection pool configurations for read-only and read-write workloads.

### Not cleaning up localConnections / localAdapters

`CoroutineLocalConnection` and `CoroutineLocalRedisCache` use arrays keyed by coroutine ID. Swoole reuses coroutine IDs once a coroutine has ended. If the defer callback fails to run (application bug, fatal error), the stale array entry will be returned to a future coroutine with the same ID.

Always ensure `Coroutine::defer()` is registered immediately after borrowing, before any code that could throw.

### Detecting leaks

Run Swoole's built-in coroutine statistics in development:

```php
$stats = Swoole\Coroutine::stats();
// $stats['coroutine_num'] — current live coroutines
// $stats['coroutine_peak_num'] — peak
```

A `coroutine_num` that grows without bound under constant load indicates leaked coroutines, which will also leak their pool connections.

---

## Decision table: when to use coroutine scope

| Service type | Example | Use coroutine scope? | Reason |
|---|---|---|---|
| Stateless calculator | `PriceCalculator`, `TaxService` | No | No mutable state; shared singleton is correct |
| Read-only repository | `ProductRepository` | No | Queries are stateless; EntityManager dependency needs care (see below) |
| Request metadata | `RequestContext`, `RequestTracer` | Yes — `#[CoroutineScoped]` | Holds request-specific data |
| Entity Manager | `EntityManager` | Connection only via pool middleware | Identity map + connection both need isolation |
| HTTP client | `GuzzleHttp\Client` | Yes if it holds a session | Cookie jars and auth tokens are request-specific |
| Redis cache | `CoroutineLocalRedisCache` | Yes — pool pattern | Redis connection state is per-coroutine |
| Rate limiter | `RateLimiter` | No — but needs coroutine-safe storage | The limiter itself can be a singleton if its backend (Redis) is coroutine-safe |
| Logger | `Logger` | No | Stateless; use Monolog processors for context |
| Background actor | `CatalogActor`, `InventoryActor` | No | Actors own their state model; they are not request-scoped |

The general rule: if a service accumulates state during a request and that state must not bleed into other requests, it needs coroutine isolation. The mechanism — `#[CoroutineScoped]` attribute or the pool pattern — depends on whether the isolation unit is the object itself or an underlying resource (connection, socket).

---

## Coroutine context vs Symfony request scope

Symfony's `RequestStack` is not coroutine-safe under Swoole. `RequestStack` is a shared service, and pushing/popping request objects concurrently from multiple coroutines produces race conditions on the internal stack array.

The `#[CoroutineScoped]` attribute is the Nexus replacement for services that would historically have been registered in Symfony's `request` scope (removed in Symfony 3) or accessed via `RequestStack`. For services that need per-request lifecycle management, use `#[CoroutineScoped]` and let `CoroutineScopeListener` handle initialization at the correct time.

For access to the current `Request` object inside a service, inject it as a controller parameter rather than storing it in a service, or create a `#[CoroutineScoped]` wrapper that captures the request at initialization time.
