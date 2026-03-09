# Troubleshooting

Diagnostic reference for common problems encountered when running nexus-symfony in development and production environments.

---

## Startup failures

### `env: php: No such file or directory`

The system cannot find the PHP binary when attempting to start the server.

**Cause:** PHP is not installed on the host or is not in `$PATH`. nexus-symfony requires PHP 8.5+ ZTS. On developer machines using Docker, the binary is inside the container, not the host.

**Resolution:** Always run the server through Docker:

```bash
docker compose exec php php public/index.php
```

Or via the provided `make` target if defined. Never run `php` directly on the host unless PHP 8.5 ZTS is installed locally.

Verify the correct PHP binary is in use:

```bash
docker compose exec php php -v
# Should show: PHP 8.5.x (cli) ... ZTS
```

---

### `Swoole\Exception: failed to create thread` or server exits immediately with no output

The Swoole server cannot create worker threads.

**Cause A — Non-ZTS PHP build.** Swoole's thread mode (`SWOOLE_THREAD`) requires a ZTS (Zend Thread Safety) PHP build. A non-ZTS PHP will load the Swoole extension but `Swoole\Thread` will not be available.

**Diagnosis:**

```bash
php -i | grep 'Thread Safety'
# Must show: Thread Safety => enabled
```

If it shows `disabled`, the PHP installation is NTS. Rebuild PHP with `--enable-zts` or switch to a ZTS image:

```dockerfile
FROM php:8.5-cli-zts
```

**Cause B — Swoole not compiled with `--enable-swoole-thread`.** The Swoole extension must be compiled with thread support enabled.

**Diagnosis:**

```bash
php -r "var_dump(defined('SWOOLE_THREAD'));"
# Must print: bool(true)
```

If it prints `bool(false)`, recompile Swoole with:

```bash
pecl install swoole  # follow prompts, enable thread support
# Or compile from source:
phpize && ./configure --enable-swoole-thread && make && make install
```

---

### `Class "Monadial\Nexus\Symfony\Bundle\NexusBundle" not found`

The bundle cannot be autoloaded.

**Cause A — Bundle not registered in `config/bundles.php`:**

```php
// config/bundles.php
return [
    // ...
    Monadial\Nexus\Symfony\Bundle\NexusBundle::class => ['all' => true],
];
```

**Cause B — Autoloader not regenerated after installation:**

```bash
docker compose exec php composer dump-autoload
```

**Cause C — Package not installed:**

```bash
docker compose exec php composer require monadial/nexus-symfony
```

---

### `Class "NexusRuntime" not found` when starting the server

The `APP_RUNTIME` environment variable references the NexusRuntime class but it cannot be resolved.

**Cause A — `autoload_runtime.php` not used.** The Symfony runtime system requires `vendor/autoload_runtime.php`, not `vendor/autoload.php`, as the entry point:

```php
// public/index.php — correct
require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static fn (array $context): Kernel => new Kernel(
    $context['APP_ENV'],
    (bool) $context['APP_DEBUG'],
);
```

**Cause B — `APP_RUNTIME` not set before `autoload_runtime.php` is required:**

```php
// public/index.php — APP_RUNTIME must be set before the require
$_SERVER['APP_RUNTIME'] = Monadial\Nexus\Symfony\Runtime\NexusRuntime::class;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';
```

---

### `Kernel::boot() failed` or `RuntimeException: ... during boot`

The Symfony kernel throws an exception during the initial boot in a worker's `NexusRunner::workerStart`.

**Diagnosis:** Check the process stderr or the log file. The full exception trace is printed before the worker exits:

```bash
docker compose exec php php public/index.php 2>&1 | head -80
```

**Common causes:**

- `APP_ENV` not set or set to an unknown environment (must be `dev`, `prod`, or `test`).
- Missing required bundle configuration. Check `config/packages/` for incomplete YAML.
- Missing required environment variables referenced in `config/services.yaml` or `config/packages/*.yaml`.
- Compiled DI container is stale (class no longer exists). Run `bin/console cache:clear --env=prod`.

**Resolution:** Reproduce the boot error in isolation:

```bash
docker compose exec php php bin/console cache:clear --env=prod
docker compose exec php php bin/console debug:container --env=prod
```

If the console commands succeed, the kernel boots correctly in isolation. The failure may be specific to a service that is only instantiated in the web context (e.g., a service with a `request` scope). Check for services tagged `nexus.worker_start` that throw during `onWorkerStart`.

---

## Worker crashes

### Worker dies immediately after starting, no requests handled

A worker thread exits shortly after `workerStart` completes.

**Diagnosis:** Check the Swoole worker exit code and stderr:

```bash
docker compose exec php php public/index.php 2>&1
```

Swoole logs the exception that caused the crash. Common causes:

- Exception in `WorkerStartBootstrapper::onWorkerStart()` — a bootstrapper throws before the actor system is fully initialized.
- Exception during `KernelActor::onPreStart()` — the Symfony kernel boot fails for this specific worker's environment.

**Resolution:** Add logging to the bootstrapper to isolate the failure:

```php
final class MyBootstrapper implements WorkerStartBootstrapper
{
    public function onWorkerStart(ContainerInterface $container, int $workerId): void
    {
        try {
            $this->initializeResources($workerId);
        } catch (\Throwable $e) {
            // Log before the process dies
            error_log(sprintf(
                'Worker %d bootstrapper failed: %s at %s:%d',
                $workerId,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));
            throw $e;
        }
    }
}
```

---

### `KernelActor` keeps restarting — logs show repeated boot/crash cycles

The supervision tree restarts `KernelActor` after each crash but the kernel continues to fail during boot.

**Cause:** The Symfony kernel throws during `$kernel->boot()` inside `KernelActor::onPreStart()`. The supervision strategy (default: restart up to N times) keeps spawning replacement actors.

**Diagnosis:** The crash reason appears in the `ChildFailed` signal delivered to `KernelPoolActor`. Enable Nexus actor logging to capture it:

```yaml
# config/packages/monolog.yaml
monolog:
    channels: [nexus]
    handlers:
        nexus:
            type: stream
            path: "%kernel.logs_dir%/nexus.log"
            level: debug
            channels: [nexus]
```

Look for lines containing `KernelActor` and `ChildFailed` in `var/log/nexus.log`.

**Resolution:** Fix the underlying kernel boot error. Common causes: missing compiled container (run `cache:warmup`), invalid environment variable values, Doctrine connection string pointing to an unreachable host.

---

### `ActorNameExistsException` on startup

```
Monadial\Nexus\Core\Exception\ActorNameExistsException:
  Actor with name "my-service" already exists under path "nexus-worker-0/"
```

Two actors with the same name are being spawned in the same worker.

**Cause:** Two `#[Actor]` classes use the same name string, or a `WorkerStartBootstrapper` manually spawns an actor whose name conflicts with an auto-registered isolated actor.

**Resolution:** Ensure all actor names are unique within a worker. Check `config/packages/nexus.yaml` if actors are declared there, and scan for `#[Actor(ActorType::Isolated, 'name')]` attributes across all actor classes:

```bash
docker compose exec php grep -r 'ActorType::Isolated' src/
```

---

## Request handling issues

### HTTP 503 Service Unavailable — unexpected during low load

503 responses are appearing even though the server has capacity.

**Cause A — `kernel_pool_max_pending` is too low.** Even a small traffic burst can fill the pending queue if `max_pending` is set to a very small value.

**Diagnosis:** Check the `APP_RUNTIME_OPTIONS`:

```bash
echo $APP_RUNTIME_OPTIONS
```

If `kernel_pool_max_pending` is set to a small value (e.g., 10), increase it:

```bash
APP_RUNTIME_OPTIONS='{"workers":4,"kernel_pool_size":8,"kernel_pool_max_pending":200}'
```

**Cause B — A slow endpoint is monopolizing kernel slots.** One slow endpoint (e.g., a missing index causing a full table scan) ties up all kernel actors for seconds, causing unrelated fast endpoints to queue and overflow.

**Diagnosis:** Check Nginx or Swoole access logs for unusually slow response times on specific paths. Enable MySQL slow query logging (see the performance guide).

**Resolution:** Fix the slow query, or increase `kernel_pool_size` to provide more capacity for mixed fast/slow workloads.

---

### Requests hanging indefinitely — no response, no error

Client connections time out waiting for a response.

**Cause A — All kernel slots busy in an infinite loop.** A controller has entered an infinite loop or is blocked on a non-cooperative I/O operation (e.g., `curl_exec` without a timeout, `file_get_contents` on a slow URL).

**Diagnosis:** Under Swoole, blocking I/O hooks replace most PHP I/O with coroutine-friendly equivalents. However, some C extensions may not cooperate. Check for native blocking calls in controllers:

```bash
docker compose exec php grep -r 'curl_exec\|file_get_contents\|fgets\|stream_get_contents' src/
```

Replace blocking calls with Swoole-cooperative alternatives or add timeouts.

**Cause B — Actor `ask()` waiting for a reply that never comes.** The `ask()` call in `NexusRunner` awaits a `KernelResponse`. If `KernelActor` crashes without sending the response and the `ChildFailed` signal is not handled correctly, the caller coroutine suspends indefinitely.

**Diagnosis:** Check the Nexus actor logs for `ChildFailed` signals and unhandled exceptions in kernel actors.

---

### `AskTimeoutException` in logs

```
Monadial\Nexus\Core\Exception\AskTimeoutException:
  ask() timed out after 30s waiting for KernelResponse
```

The `KernelPoolActor`'s `ask()` call from `NexusRunner` exceeded the timeout.

**Cause:** A `KernelActor` is taking more than 30 seconds to process a request. This is typically caused by:
- A very slow database query or external API call.
- A deadlock in the database.
- A non-cooperative blocking call inside the controller.

**Resolution:**
1. Increase `shutdown_timeout` if slow requests are expected:
   ```yaml
   nexus:
       shutdown_timeout: 60
   ```
2. Add a timeout to external I/O calls in controllers.
3. Investigate the slow query or external call.

If 503 responses are acceptable for slow requests, reduce the ask timeout to match the desired maximum request duration and return 503 rather than waiting 30 seconds.

---

### Responses contain state from a previous request (data leakage)

A request returns data that belongs to a different user or a previous request.

**Cause:** A service that holds per-request state is not being reset between requests. Common culprits:
- `EntityManager` identity map not cleared — a previously-loaded entity is returned instead of a fresh database read.
- A custom service with a static property or a class property accumulating request-specific state.

**Diagnosis:** Verify that `services_resetter` is being invoked after each request. Add a `kernel.request` event listener that logs the request ID and checks for stale state:

```php
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

#[AsEventListener]
final class RequestSanityListener
{
    public function __invoke(RequestEvent $event): void
    {
        // Example: assert EntityManager has no pending changes at request start
        if ($this->em->getUnitOfWork()->hasPendingInsertions()) {
            throw new \LogicException('EntityManager has pending inserts at request start — state leaked from previous request');
        }
    }
}
```

**Resolution:**
- Ensure the `framework.reset_on_exception` is enabled and the service implements `ResetInterface`:
  ```php
  use Symfony\Contracts\Service\ResetInterface;

  final class MyStatefulService implements ResetInterface
  {
      private array $cache = [];

      public function reset(): void
      {
          $this->cache = [];
      }
  }
  ```
- Tag the service for reset:
  ```yaml
  App\Service\MyStatefulService:
      tags:
          - { name: kernel.reset, method: reset }
  ```
- For `EntityManager` isolation in coroutine context, use `#[CoroutineScoped]` to give each coroutine its own `EntityManager` instance. See [coroutine-scoped-services.md](coroutine-scoped-services.md).

---

## Database / Doctrine issues

### `There is already an active transaction`

```
Doctrine\DBAL\Exception: There is already an active transaction.
```

A previous request left an open transaction in the `EntityManager`. The next request's call to `beginTransaction()` finds one already active.

**Cause:** The `EntityManager` is shared between requests within a kernel instance. If a previous request threw an exception after opening a transaction, the transaction was not rolled back before the next request began.

**Resolution:** Ensure `services_resetter` is configured and the Doctrine `EntityManager` is tagged with `kernel.reset`. In Symfony 6.4+, the `doctrine` bundle registers the EntityManager for reset automatically.

Verify the resetter is active:

```bash
docker compose exec php php bin/console debug:container services_resetter --env=prod
```

If the resetter service is missing, enable it explicitly:

```yaml
# config/packages/framework.yaml
framework:
    reset_on_exception: true
```

For coroutine-level isolation (each coroutine gets its own `EntityManager`), use `#[CoroutineScoped]`:

```php
use Monadial\Nexus\Symfony\Attribute\CoroutineScoped;

#[CoroutineScoped]
final class CoroutineEntityManagerFactory
{
    // ...
}
```

See [coroutine-scoped-services.md](coroutine-scoped-services.md) for implementation details.

---

### `MySQL server has gone away` or `PDO::query(): Broken pipe`

The database connection has been dropped by the server but the application tries to use it.

**Cause A — Connection TTL expired.** MySQL closes idle connections after `wait_timeout` (default: 8 hours). A pooled connection that has been idle will be closed by the server.

**Resolution:** Configure the connection pool to validate connections before use (check with `SELECT 1` on borrow) or set the pool TTL below MySQL's `wait_timeout`:

```php
// In a WorkerStartBootstrapper or connection pool initialization
$pool->setMaxIdleTime(3600); // 1 hour, well below MySQL's 8 hour default
```

**Cause B — Coroutine interrupted mid-query.** If a coroutine is cancelled while a query is in progress, the PDO connection may be left in an undefined state. Ensure connections are returned to the pool in all code paths, including exception paths:

```php
$connection = $pool->borrow();
try {
    $result = $connection->query('SELECT ...');
} finally {
    $pool->return($connection); // always return, even on exception
}
```

**Cause C — MySQL `max_connections` exceeded.** All connections across all processes and workers are exhausted. Check `SHOW STATUS LIKE 'Threads_connected'` on the MySQL server and compare against `max_connections`.

---

### `PDOException: SQLSTATE[HY000]: General error: 2006` intermittently

Same as "MySQL server has gone away" — the connection was recycled by MySQL between requests.

**Resolution:** Configure the PDO connection with:

```php
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Reconnect automatically on next query (not all drivers support this)
// Instead, validate connection on borrow from pool:
try {
    $connection->query('SELECT 1');
} catch (\PDOException $e) {
    // Connection is dead, remove from pool and create a new one
    $pool->discard($connection);
    $connection = $pool->createNew();
}
```

---

## Memory issues

### RSS grows on every request, never decreases

Worker process memory consumption increases continuously.

**Cause A — Doctrine Unit of Work not cleared.** The `EntityManager`'s identity map accumulates loaded entities. Each request adds more entities without clearing the previous set.

**Diagnosis:** Log the `EntityManager` state count after each request:

```php
$unitOfWork = $this->em->getUnitOfWork();
$logger->debug('UoW entities after request', [
    'managed' => count($unitOfWork->getIdentityMap()),
]);
```

If the count grows, `services_resetter` is not clearing the `EntityManager`.

**Resolution:** Ensure the `EntityManager` implements reset. Call `$em->clear()` explicitly in a `kernel.terminate` event listener as a fallback:

```php
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

#[AsEventListener]
final class EntityManagerClearListener
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function __invoke(TerminateEvent $event): void
    {
        $this->em->clear();
    }
}
```

**Cause B — Static caches in actor instances.** An application actor accumulates objects in an instance property without eviction. Since actors are long-lived (lifetime of the worker), unbounded accumulation leads to memory growth.

**Resolution:** Bound actor state with a maximum size or use an LRU eviction policy:

```php
final class CatalogActor implements StatefulActorHandler
{
    private const MAX_CACHE_ENTRIES = 1000;

    public function handle(ActorContext $ctx, object $msg, array $cache): BehaviorWithState
    {
        if ($msg instanceof CacheProduct) {
            if (count($cache) >= self::MAX_CACHE_ENTRIES) {
                // Evict oldest entry (FIFO)
                reset($cache);
                unset($cache[key($cache)]);
            }
            $cache[$msg->id] = $msg->product;
            return BehaviorWithState::next($cache);
        }

        return BehaviorWithState::same();
    }
}
```

**Cause C — Circular references not collected.** PHP's garbage collector runs automatically but may not collect cycles fast enough for long-running processes.

**Resolution:** Call `gc_collect_cycles()` periodically in a long-lived actor using a scheduled message:

```php
public function onPreStart(ActorContext $ctx): void
{
    // Schedule GC every 60 seconds
    $ctx->scheduleRepeatedly(
        Duration::seconds(60),
        Duration::seconds(60),
        new RunGarbageCollection(),
    );
}

public function handle(ActorContext $ctx, object $message): Behavior
{
    if ($message instanceof RunGarbageCollection) {
        gc_collect_cycles();
        return Behavior::same();
    }
    // ... other messages
    return Behavior::same();
}
```

---

### OOM in worker — process killed by OS

The worker process exceeds `memory_limit` or the OS kills it for exceeding container limits.

**Diagnosis:** Calculate the expected memory footprint:

```
workers × (kernel_pool_size + 1) × per_kernel_mb + base_mb
```

If this exceeds available RAM or the container limit, the configuration is over-provisioned.

**Resolution:**
1. Reduce `kernel_pool_size`.
2. Reduce `workers`.
3. Increase the container memory limit.
4. Optimize the application's service graph to reduce per-kernel memory (mark heavyweight services `lazy: true`).

Measure actual per-kernel memory before setting production values:

```bash
# Send one request, inspect RSS
docker stats --no-stream $(docker ps -q --filter name=php)
```

---

### Swoole memory warnings in logs

```
swoole WARNING: detected memory leaks in coroutine context
```

A coroutine completed without releasing all resources it acquired.

**Cause:** A coroutine that opened a connection, acquired a lock, or allocated a resource exited due to an exception before returning the resource to its pool.

**Resolution:** Use `try/finally` in all coroutine-context code that acquires resources:

```php
$connection = $pool->borrow();
try {
    // ... use connection ...
} finally {
    $pool->return($connection);
}
```

For actor `PostStop` signal, release resources held by the actor:

```php
public function onPostStop(ActorContext $ctx): void
{
    if ($this->connection !== null) {
        $this->pool->return($this->connection);
        $this->connection = null;
    }
}
```

---

## Concurrency issues

### Race condition on shared state — inconsistent reads or writes

Two concurrent requests see conflicting state or corrupt data.

**Cause:** Mutable state is being shared between concurrent coroutines through a service instance or a static variable.

**Resolution:** Never share mutable state between coroutines without synchronization. In nexus-symfony, the actor model is the correct synchronization mechanism. Route concurrent writes through an actor:

```php
// Incorrect — concurrent coroutines modify the same array
final class RateLimiter
{
    private array $counts = []; // Shared between all coroutines in the worker!

    public function check(string $key): bool
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        return $this->counts[$key] <= 100;
    }
}
```

```php
// Correct — actor serializes all mutations
#[Actor(ActorType::Isolated, 'rate-limiter')]
final class RateLimiterActor implements StatefulActorHandler
{
    public function initialState(): array
    {
        return [];
    }

    public function handle(ActorContext $ctx, object $msg, array $counts): BehaviorWithState
    {
        if ($msg instanceof CheckRateLimit) {
            $count = ($counts[$msg->key] ?? 0) + 1;
            $counts[$msg->key] = $count;
            $ctx->sender()->map(fn ($ref) => $ref->tell(new RateLimitResult($count <= 100)));
            return BehaviorWithState::next($counts);
        }

        return BehaviorWithState::same();
    }
}
```

---

### Coroutine context lost — service returns wrong data for the current request

A per-request value (e.g., authenticated user ID, request locale) is not available in a deeply-nested service call.

**Cause:** Per-request state stored in a static variable or in a service instance is overwritten by a concurrent coroutine.

**Resolution:** Use Swoole's coroutine context API for per-coroutine storage. nexus-symfony provides `SwooleCoroutineContext` as a typed wrapper:

```php
use Monadial\Nexus\Symfony\Coroutine\SwooleCoroutineContext;

final class CurrentUserProvider
{
    public function __construct(private readonly SwooleCoroutineContext $context) {}

    public function set(User $user): void
    {
        $this->context->set('current_user', $user);
    }

    public function get(): ?User
    {
        return $this->context->get('current_user');
    }
}
```

Each Swoole coroutine has an isolated context map. Values set in one coroutine are invisible to other coroutines.

---

### `Swoole\Coroutine: call coroutine API outside of coroutine context`

A Swoole coroutine API is called from a non-coroutine context (CLI bootstrap, compiler pass, `WorkerStartBootstrapper` before the coroutine runtime starts).

**Cause:** Swoole channel operations, `Coroutine::getCid()`, or other coroutine APIs are called during process startup before the Swoole event loop is running.

**Diagnosis:** The stack trace will point to the calling code. Check if the call originates from a `WorkerStartBootstrapper`, a compiler pass, or a service instantiated during container compilation.

**Resolution:** Defer coroutine operations until inside a coroutine context. Use `Swoole\Coroutine::create()` to wrap coroutine-only code, or restructure the bootstrapper to only perform non-coroutine initialization at startup:

```php
final class RedisBootstrapper implements WorkerStartBootstrapper
{
    public function onWorkerStart(ContainerInterface $container, int $workerId): void
    {
        // This runs during workerStart — NOT inside a coroutine yet.
        // Only perform synchronous initialization here.
        // Do NOT call Swoole channel or coroutine APIs.
        RedisPool::configure(host: '127.0.0.1', size: 16);

        // The pool's actual connections are opened lazily inside coroutines.
    }
}
```

---

## Testing issues

### Test hangs — PHPUnit process does not exit

The test hangs waiting for the actor system to produce a result.

**Cause A — Missing `$runtime->drain()` with `StepRuntime`.** The `StepRuntime` requires explicit calls to process messages. If `drain()` is not called, actors never process their mailboxes and the test waits forever for a result.

```php
// Incorrect — messages enqueued but never processed
$ref->tell(new MyMessage());
$result = $capture->get(); // blocks forever

// Correct
$ref->tell(new MyMessage());
$runtime->drain(); // processes all pending messages
$result = $capture->get();
```

**Cause B — `FiberRuntime` test without a shutdown schedule.** Fiber-based integration tests require a scheduled shutdown; without one, `$system->run()` never returns:

```php
$runtime = new FiberRuntime();
$system = ActorSystem::create('test', $runtime);
$ref = $system->spawn(Props::fromBehavior($behavior), 'actor');
$ref->tell(new MyMessage());

// Schedule shutdown after all messages should have been processed
$runtime->scheduleOnce(
    Duration::millis(500),
    fn() => $system->shutdown(Duration::seconds(1)),
);

$system->run(); // blocks until shutdown is complete
```

---

### `Cannot use TestRuntime in integration test` or unexpected runtime in test environment

The test is using the wrong runtime (e.g., `FiberRuntime` when `StepRuntime` is expected, or vice versa).

**Cause:** The runtime is wired in Symfony's DI container and the test environment has not overridden the production registration.

**Resolution:** Create a test-specific Nexus configuration that registers the desired runtime:

```yaml
# config/packages/test/nexus.yaml
nexus:
    name: test
    shutdown_timeout: 5
    kernel_pool:
        size: 1
        max_pending: 10
```

For unit tests that use actors directly (without the Symfony kernel), construct the runtime explicitly in the test:

```php
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Monadial\Nexus\Core\Actor\ActorSystem;

final class MyActorTest extends TestCase
{
    private StepRuntime $runtime;
    private ActorSystem $system;

    protected function setUp(): void
    {
        $this->runtime = new StepRuntime();
        $this->system = ActorSystem::create('test', $this->runtime);
    }
}
```

---

### Actors not receiving messages in tests — assertions fail

The actor never processes the message sent in the test, causing the assertion to fail.

**Cause:** The `StepRuntime` requires `drain()` or `step()` to be called explicitly. No messages are processed until the test explicitly drives the runtime.

```php
// Incorrect
$ref->tell(new Increment());
self::assertSame(1, $counter->get()); // Fails — actor has not run yet

// Correct
$ref->tell(new Increment());
$runtime->drain(); // Run until no messages remain
self::assertSame(1, $counter->get());
```

For tests where timing matters (actors using `scheduleOnce`), use `TestClock` to advance time explicitly:

```php
$clock = new TestClock();
$runtime = new StepRuntime(clock: $clock);
$system = ActorSystem::create('test', $runtime, $clock);

$ref->tell(new ScheduleTimeout());
$runtime->drain(); // Process the tell message, which schedules a timer

$clock->advance(Duration::seconds(5)); // Advance time past the timer
$runtime->drain(); // Fire the timer callback

self::assertTrue($capture->timedOut());
```

---

## Logging and debugging

### Enabling verbose Nexus actor logging

Nexus logs actor lifecycle events (spawn, stop, crash, message dispatch) through the PSR-3 logger passed to `ActorSystem::create()`. In nexus-symfony, the logger is the Symfony logger.

Enable `debug` level logging for the Nexus channel:

```yaml
# config/packages/dev/monolog.yaml
monolog:
    channels: [nexus]
    handlers:
        nexus_debug:
            type: stream
            path: "%kernel.logs_dir%/nexus.log"
            level: debug
            channels: [nexus]
```

Log entries include:
- `actor.spawned` — actor started
- `actor.stopped` — actor stopped normally
- `actor.failed` — actor threw an exception
- `actor.restarted` — supervision restart
- `actor.message` — message received (verbose, only enable in dev)

### Identifying which worker handled a request

Each worker sets a `worker_id` key on the logger context during `workerStart`. All log entries from request handlers within that worker carry this key:

```php
// NexusRunner adds to all log context for the worker:
['worker_id' => 'nexus-worker-2', 'actor_system' => 'my-app-worker-2']
```

In Monolog, add a processor to include this in every log entry:

```php
use Monadial\Nexus\Symfony\Logging\WorkerContextProcessor;

// Auto-registered by NexusBundle; no manual configuration needed
```

Filter logs for a specific worker:

```bash
grep '"worker_id":"nexus-worker-0"' var/log/nexus.log
```

### Tracing a request through the kernel pool

Each request envelope carries `requestId`, `correlationId`, and `causationId` (ULID strings). The `EnvelopeContext` service exposes these to the current coroutine.

Add the request ID to all log entries from a controller:

```php
use Monadial\Nexus\Symfony\Actor\EnvelopeContext;

final class CatalogController
{
    public function __construct(private readonly EnvelopeContext $envelopeContext) {}

    public function index(): JsonResponse
    {
        $requestId = $this->envelopeContext->requestId();
        $this->logger->info('Catalog request', ['request_id' => $requestId]);

        // ...
    }
}
```

Correlate all log entries for a single request by filtering on `request_id`:

```bash
grep "01HV..." var/log/nexus.log
```

---

## Common configuration mistakes

### Forgetting to register `NexusBundle`

The bundle is installed but not registered in `config/bundles.php`. Symfony will not load any bundle configuration, tagged services, or compiler passes.

```php
// config/bundles.php
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    // Missing:
    Monadial\Nexus\Symfony\Bundle\NexusBundle::class => ['all' => true],
];
```

**Symptom:** `#[Actor]` attributes are ignored; `nexus.actor_system` service not found; no kernel pool is started.

---

### Using `APP_RUNTIME` without `autoload_runtime.php`

```php
// public/index.php — incorrect
require_once dirname(__DIR__) . '/vendor/autoload.php'; // standard autoloader

$_SERVER['APP_RUNTIME'] = NexusRuntime::class;
// NexusRuntime is never invoked — autoload.php does not know about runtimes
```

The Symfony runtime component requires `vendor/autoload_runtime.php` as the entry point. This file reads `APP_RUNTIME` and invokes the runtime class. Using `vendor/autoload.php` bypasses the runtime system entirely.

---

### Setting `workers=0`

```bash
APP_RUNTIME_OPTIONS='{"workers":0,"kernel_pool_size":8}'
```

`workers` must be >= 1. Setting it to 0 causes the Swoole server to create no threads and the process exits immediately.

**Symptom:** Process exits with exit code 0 immediately after starting, with no error message.

---

### Setting `kernel_pool_size=0`

```bash
APP_RUNTIME_OPTIONS='{"workers":4,"kernel_pool_size":0}'
```

`kernel_pool_size` must be >= 1. With 0, `KernelPoolActor` spawns no `KernelActor` children. Every request immediately receives HTTP 503 (no idle kernels, no pending queue capacity for 0-sized pool).

**Symptom:** All requests return 503 immediately. No errors in logs.

---

### Configuring `kernel_pool_size` in `nexus.yaml` instead of `APP_RUNTIME_OPTIONS`

```yaml
# nexus.yaml — this does NOT affect the runtime pool size
nexus:
    kernel_pool:
        size: 16  # stored as nexus.kernel_pool_size DI parameter only
```

The `nexus.yaml` `kernel_pool.size` parameter is a DI container parameter available to services at runtime. It does not automatically propagate to `APP_RUNTIME_OPTIONS`. The `KernelPoolActor` reads its pool size from the `APP_RUNTIME_OPTIONS` JSON object, not from the DI container.

To set the actual pool size, configure `APP_RUNTIME_OPTIONS`:

```bash
APP_RUNTIME_OPTIONS='{"kernel_pool_size":16}'
```

---

## Debugging actor problems

### Adding structured logging to actors

Use `$ctx->log()` inside actor handlers for structured, worker-context-aware logging:

```php
public function handle(ActorContext $ctx, object $message): Behavior
{
    $ctx->log()->info('Processing message', [
        'type' => $message::class,
        'actor' => (string) $ctx->self()->path(),
    ]);

    return Behavior::same();
}
```

The logger returned by `$ctx->log()` carries the Nexus channel context including `worker_id` and `actor_path`.

### Inspecting dead letters

Messages that cannot be delivered are routed to the dead letter mailbox. Enable dead letter logging:

```yaml
# config/packages/nexus.yaml
nexus:
    name: my-app
    # Dead letter logging is enabled by default at DEBUG level
```

Dead letters are logged with the original message type, sender path, and target path. A high volume of dead letters indicates:
- `ActorRef` references being held after the target actor has stopped.
- Replies being sent to `ask()` reply refs that have already timed out.
- Messages sent to `DeadLetterRef` explicitly.

### Detecting message storms

If an actor processes an unexpectedly large volume of messages, enable message count logging in a staging environment:

```php
final class MessageCountingActor extends AbstractActor
{
    private int $messageCount = 0;
    private float $windowStart;

    public function onPreStart(ActorContext $ctx): void
    {
        $this->windowStart = microtime(true);

        // Log message rate every 10 seconds
        $ctx->scheduleRepeatedly(
            Duration::seconds(10),
            Duration::seconds(10),
            new LogMessageRate(),
        );
    }

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if ($message instanceof LogMessageRate) {
            $elapsed = microtime(true) - $this->windowStart;
            $rate = $this->messageCount / $elapsed;
            $ctx->log()->info('Message rate', ['rate_per_sec' => round($rate, 1)]);
            $this->messageCount = 0;
            $this->windowStart = microtime(true);
            return Behavior::same();
        }

        $this->messageCount++;
        // ... handle message ...
        return Behavior::same();
    }
}
```

A message rate significantly higher than expected suggests a feedback loop: an actor responding to its own message or a caller retrying without backoff.
