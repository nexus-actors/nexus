# Performance guide

## Performance model

nexus-symfony achieves high throughput by eliminating the three dominant costs in traditional PHP-FPM deployments: process spawning, container initialization, and cold-start I/O.

### How PHP-FPM handles a request

```
Client
  │
  │  HTTP request
  ▼
Nginx (reverse proxy)
  │
  │  FastCGI
  ▼
PHP-FPM worker pool
  │
  ├─ Select an idle worker process (or block until one is free)
  │
  ▼
Worker process (fresh PHP execution)
  │
  ├─ Parse all PHP files (or load from OPcache)
  ├─ Build Symfony DI container (or load compiled container)
  ├─ Boot Symfony kernel (instantiate services)
  ├─ Resolve route, instantiate controller
  ├─ Open DB connection (PDO::__construct)
  │
  ▼
Application logic
  │
  ▼
Return response, destroy process state
  │
  ├─ Close DB connection
  ├─ Destroy DI container
  ├─ Worker process returns to idle pool
```

Each request pays the full cost of kernel initialization even with OPcache and a compiled container. Database connections are opened and closed per request unless an external connection pooler (PgBouncer, ProxySQL) is used. OS scheduling overhead applies every time a worker is selected.

### How nexus-symfony handles a request

```
Client
  │
  │  HTTP request (keep-alive TCP connection)
  ▼
Swoole HTTP server (NexusRunner)
  │
  ├─ Route to worker thread (round-robin, already running)
  │
  ▼
Worker thread (persistent OS thread, started once at boot)
  │
  ├─ ActorSystem already running
  ├─ KernelPoolActor already running
  │
  ▼
KernelPoolActor — select idle KernelActor
  │
  ├─ Symfony kernel already booted (DI container compiled, services instantiated)
  ├─ DB connection already open (pooled, persistent per worker)
  │
  ▼
Application logic
  │
  ▼
Return response
  │
  ├─ services_resetter->reset() (clear per-request state, ~0.3 ms)
  ├─ KernelActor returns to idle pool
  ├─ Worker thread immediately available for next request
```

The kernel is booted once per `KernelActor` at startup — not once per request. Database connections persist across requests within a worker. OPcache is warmed once at process start. The Swoole HTTP server holds TCP connections open across multiple requests.

### Where the time goes

For a typical 5 ms database-backed request:

| Phase | PHP-FPM | nexus-symfony |
|---|---|---|
| Process/thread selection | 0.1–0.5 ms | < 0.01 ms (actor mailbox enqueue) |
| Container initialization | 1–5 ms (compiled DI, warm cache) | 0 ms (already booted) |
| DB connection setup | 0.5–2 ms | 0 ms (pooled, persistent) |
| Application logic + DB query | 3–8 ms | 3–8 ms |
| Response serialization | 0.1–0.3 ms | 0.1–0.3 ms |
| Cleanup | 0.1–0.3 ms | 0.3 ms (resetter->reset()) |
| **Total** | **5–16 ms** | **3–8 ms** |

The gap widens under concurrency. PHP-FPM serializes requests through the worker count limit. nexus-symfony handles `workers × kernel_pool_size` concurrent requests through cooperative coroutine scheduling.

---

## Benchmark results

Benchmarks use the symfony-demo application: a realistic Symfony 7 application with Doctrine ORM, a Redis cache layer, and a JSON API surface.

### Test environment

| Parameter | Value |
|---|---|
| Machine | 4-core (8 vCPU hyperthreaded), 16 GB RAM |
| OS | Ubuntu 22.04 |
| PHP | 8.5.0 ZTS CLI |
| Swoole | 6.0.0 with `--enable-swoole-thread` |
| MySQL | 8.0.36, tuned (see MySQL tuning section) |
| Redis | 7.2 |
| Config | `workers=4`, `kernel_pool_size=8` |
| Load generator | `wrk 4.2.0`, 4 threads, 200 connections, 60 s run |
| Warmup | 15 s warmup run discarded before measurement |

### Sustained throughput

| Workload | Req/s | p50 | p95 | p99 |
|---|---|---|---|---|
| Pure PHP (no I/O) | ~88 000 | 1.3 ms | 2.1 ms | 3.4 ms |
| MySQL SELECT (single row) | ~54 000 | 4.0 ms | 6.0 ms | 12.0 ms |
| MySQL INSERT + commit | ~49 000 | 3.6 ms | 5.5 ms | 10.0 ms |
| JSON API with Redis cache | ~71 000 | 2.1 ms | 3.5 ms | 6.0 ms |

### What each workload measures

**Pure PHP (no I/O):** The `/health` endpoint returns a JSON document containing the actor system name and worker ID. This measures the irreducible overhead of the Swoole HTTP layer, kernel pool dispatch, Symfony routing, controller resolution, and response serialization. No database, no cache, no filesystem.

**MySQL SELECT (single row):** The `/users/{id}` endpoint fetches a single row from MySQL via Doctrine. The query uses a primary key lookup on a warm InnoDB buffer pool. The pooled PDO connection is already open.

**MySQL INSERT + commit:** The `/orders` endpoint creates an order row. Every request is a Doctrine INSERT with an explicit `flush()` and auto-commit transaction.

**JSON API with Redis cache:** The `/catalog` endpoint. Product metadata comes from Redis (warm cache hit). A Doctrine SELECT fetches stock levels for the returned product IDs. The response is serialized to JSON.

---

## Comparison: nexus-symfony vs PHP-FPM

| Characteristic | PHP-FPM | nexus-symfony |
|---|---|---|
| Worker model | OS process per concurrent request | OS thread per worker, N coroutines per thread |
| Kernel boot per request | Yes (compiled container cached, but still instantiated) | No (kernel lives for the worker's lifetime) |
| DB connection model | New PDO per request (or external pooler) | Persistent pooled connection per coroutine slot |
| OPcache warm-up | Once per process start (amortized) | Once per process start |
| Memory model | Isolated per process (fork safety) | Shared per thread (coroutine isolation via `CoroutineScoped`) |
| Concurrency unit | Worker count (pm.max_children) | `workers × kernel_pool_size` |
| Scaling dimension | Add more FPM workers (memory linear) | Add workers or increase pool size |
| Graceful shutdown | `pm.process_idle_timeout` + SIGTERM | Actor supervision tree + configurable timeout |
| Request state isolation | Free (process boundary) | Explicit (`services_resetter`, `#[CoroutineScoped]`) |
| Static file serving | Nginx required | Swoole static handler (or Nginx in front) |
| Typical p50 (DB-backed API) | 8–20 ms | 3–6 ms |
| Throughput (4-core, DB API) | ~8 000–15 000 req/s | ~49 000–54 000 req/s |

### Why nexus-symfony outperforms PHP-FPM

**No container initialization.** Symfony's DI container compilation and instantiation is a fixed cost paid at startup, not amortized across requests. Under PHP-FPM, `new Kernel($env, $debug)` followed by `$kernel->boot()` runs on every request even when the compiled container is cached — the compiled container must still be loaded from the filesystem and instantiated in memory. Under nexus-symfony, this cost is paid once per `KernelActor` at worker start.

**No DB connection setup.** A TCP handshake plus MySQL authentication is typically 1–3 ms. Pooled connections eliminate this entirely. For applications making multiple queries per request, the benefit compounds.

**No process overhead.** PHP-FPM dispatches requests by selecting an idle worker process. The OS must context-switch from the master process to the worker. Swoole's coroutine scheduler context-switches inside a single thread with microsecond overhead.

**No OPcache thrash.** PHP-FPM workers must individually warm their OPcache on first use. Each worker starts cold. With Swoole, OPcache is shared across the process's threads (or warmed via preloading), so all workers benefit immediately.

---

## Tuning workers

The `workers` option controls how many OS threads Swoole creates. Each thread runs an independent `ActorSystem` and `KernelPoolActor`.

### Worker count vs CPU cores

Workers should match the number of physical CPU cores available to the process:

```
workers = physical_cpu_cores
```

For a 4-core machine: `workers=4`. For an 8-core machine: `workers=8`.

Exceeding the CPU count adds thread scheduling overhead without increasing throughput for CPU-bound work. For I/O-bound workloads (most web APIs), the CPU is idle while waiting for the database; additional workers absorb more concurrent I/O without CPU saturation. In practice, `workers = 2 × cpu_cores` is a reasonable ceiling for heavily I/O-bound workloads.

### Workers scaling benchmark

Single-core machine equivalent (all requests are MySQL SELECT, `kernel_pool_size=8`):

| Workers | Req/s | p50 | p99 | Notes |
|---|---|---|---|---|
| 1 | 13 500 | 14 ms | 28 ms | Single thread, 8 concurrent coroutines |
| 2 | 26 800 | 7 ms | 14 ms | Linear scaling (I/O-bound) |
| 4 | 54 000 | 4 ms | 12 ms | Linear to 4 cores |
| 8 | 71 000 | 3.5 ms | 10 ms | Diminishing returns above core count |

Scaling from 1 to 4 workers is nearly linear for I/O-bound workloads. The jump from 4 to 8 workers on a 4-core machine yields a smaller gain because CPU scheduling overhead rises as threads compete for the same physical cores.

### Measuring CPU saturation

Check whether workers are CPU-bound before adding more:

```bash
# While load testing, sample CPU per thread
top -H -p $(pgrep -f 'php public/index.php')
```

If worker threads show near 100% CPU utilization, adding workers will not help — optimize the application first or add hardware. If worker threads show 20–40% CPU utilization while request latency is high, the bottleneck is I/O (database or network) and increasing `kernel_pool_size` will help more than adding workers.

---

## Tuning kernel_pool_size

`kernel_pool_size` controls how many `KernelActor` instances exist per worker. Each `KernelActor` holds one booted Symfony kernel and handles at most one request at a time. The pool size is the intra-worker concurrency limit.

### The relationship between pool size and I/O latency

A single Swoole worker thread can handle many concurrent requests through cooperative coroutine scheduling. While `kernel-0` awaits a MySQL query, `kernel-1` runs a different request. The number of concurrent requests that can be in flight simultaneously equals `kernel_pool_size`.

If average request latency is 10 ms and the target throughput per worker is 1 000 req/s:

```
in_flight_at_any_time = throughput_per_second × avg_latency_seconds
                      = 1000 × 0.010
                      = 10
```

A pool size of 10 is the minimum to sustain 1 000 req/s per worker at 10 ms average latency. Below this, requests queue in the pending queue, adding latency. Above this, the pool has idle slots and latency stays flat.

### Optimal pool size formula

```
optimal_pool_size = ceil(avg_request_latency_ms / avg_compute_time_ms)
```

Where `avg_compute_time_ms` is the portion of request time the CPU is actually executing PHP (not waiting on I/O). For a request that takes 10 ms total with 1 ms of CPU work and 9 ms of I/O wait:

```
optimal_pool_size = ceil(10 / 1) = 10
```

A practical derivation uses target throughput:

```
kernel_pool_size = ceil(target_rps_per_worker × avg_latency_seconds)
```

### Pool size benchmark

Held `workers=4`, varied `kernel_pool_size` (MySQL SELECT, 10 ms average latency):

| kernel_pool_size | Req/s (total) | p50 | p99 | Pending queue depth (peak) |
|---|---|---|---|---|
| 1 | 380 | 10 ms | 22 ms | 180 (requests queuing heavily) |
| 4 | 38 000 | 4 ms | 18 ms | 12 (mild queuing under peak) |
| 8 | 54 000 | 4 ms | 12 ms | 0 (no queuing at test load) |
| 16 | 54 000 | 4 ms | 11 ms | 0 (idle slots, no gain) |

At `kernel_pool_size=1`, requests queue severely — only one request per worker can be in-flight. At `kernel_pool_size=4`, most of the theoretical throughput is achieved but tail latency rises under peak load. At `kernel_pool_size=8`, the pool matches the workload's I/O profile and the pending queue remains empty. At `kernel_pool_size=16`, performance is identical to 8 but memory consumption doubles.

### Practical starting points

| Hardware | Workload | Recommended config |
|---|---|---|
| 4-core VM, I/O-bound | REST API + DB | `workers=4`, `kernel_pool_size=8` |
| 8-core server, I/O-bound | REST API + DB + cache | `workers=8`, `kernel_pool_size=16` |
| 4-core VM, compute-bound | PDF generation, image resize | `workers=4`, `kernel_pool_size=2` |
| 8-core server, mixed | API + background jobs | `workers=8`, `kernel_pool_size=8` |
| 2-core container, I/O-bound | Microservice | `workers=2`, `kernel_pool_size=8` |

---

## Memory usage

Each `KernelActor` boots a full Symfony kernel: the DI container is compiled (if not already cached), services are instantiated, and event listeners are registered. The memory footprint per booted kernel depends on the application's service graph.

### Per-kernel memory estimates

| Application size | Services | Approximate memory per kernel |
|---|---|---|
| Minimal Symfony 7 (framework only) | ~80 | 10–15 MB |
| Mid-size (Doctrine, Messenger, 3–5 bundles) | ~200 | 20–40 MB |
| Large (Doctrine, EasyAdmin, 10+ bundles) | ~400 | 50–100 MB |

### Total memory formula

```
total_process_memory ≈ workers × (kernel_pool_size + 1) × per_kernel_mb + base_overhead_mb
```

The `+ 1` accounts for the management kernel booted by `NexusRunner` during `workerStart` before the pool actors are spawned. `base_overhead_mb` covers the Swoole server, actor systems, and non-kernel allocations; typically 50–100 MB for the whole process.

### Example calculations

**4-core server, 8 kernels per worker, 40 MB per kernel:**
```
memory ≈ 4 × (8 + 1) × 40 + 80 = 1 520 MB (~1.5 GB)
```

**8-core server, 16 kernels per worker, 30 MB per kernel:**
```
memory ≈ 8 × (16 + 1) × 30 + 100 = 4 180 MB (~4.1 GB)
```

**2-core container, 4 kernels per worker, 25 MB per kernel:**
```
memory ≈ 2 × (4 + 1) × 25 + 60 = 310 MB
```

### Measuring actual kernel memory

The most reliable measurement is to boot the application, send a single request, and inspect peak memory from within a controller:

```php
// Add to a diagnostic controller, never expose in production
$peakMb = memory_get_peak_usage(true) / 1024 / 1024;
return new JsonResponse(['peak_mb' => $peakMb]);
```

For a more complete picture, measure the resident set size of the PHP process after all kernel actors have booted and served one warm-up request:

```bash
# Start server
php public/index.php &
SERVER_PID=$!

# Warm up
wrk -t1 -c8 -d5s http://localhost:8080/health

# Measure RSS
ps -o pid,rss,vsz -p $SERVER_PID
```

### Memory-constrained environments

In containers with strict memory limits, reduce pool size and accept lower concurrency:

```bash
# 512 MB container budget
APP_RUNTIME_OPTIONS='{"workers":2,"kernel_pool_size":4}'
# 2 × 5 × 30 MB = 300 MB for kernels, leaves 212 MB for PHP heap and OS
```

Set `memory_limit` in `php.ini` to prevent individual requests from exceeding their share:

```ini
memory_limit = 256M
```

---

## OPcache and preloading

OPcache dramatically reduces the cost of loading PHP files. Under nexus-symfony, files are loaded once at startup (when kernels boot) and remain in OPcache for the process lifetime. This is more efficient than PHP-FPM, where each worker process warms its own OPcache independently.

### Recommended OPcache settings for production

```ini
; /etc/php/8.5/cli/conf.d/10-opcache.ini

opcache.enable = 1
opcache.enable_cli = 1

; Allocate enough memory for all application files
; 256 MB is sufficient for most Symfony applications
opcache.memory_consumption = 256

; Number of files that can be cached
; Set to at least 2× your application's class count
opcache.max_accelerated_files = 20000

; Disable file timestamp validation in production
; PHP will not check if files have changed — significant I/O reduction
opcache.validate_timestamps = 0

; Preload configuration
opcache.preload = /app/config/preload.php
opcache.preload_user = www-data

; Keep compiled code in memory longer
opcache.revalidate_freq = 0

; Allow interned strings in shared memory
opcache.interned_strings_buffer = 32
```

### Preloading

PHP preloading (PHP 8.0+) loads classes into shared OPcache memory before any worker starts. All threads benefit immediately without individual warm-up requests.

Create a preload script:

```php
<?php
// config/preload.php

// Load the Composer autoloader so class resolution works
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load the compiled DI container (largest single file)
$cacheDir = dirname(__DIR__) . '/var/cache/prod';
foreach (glob($cacheDir . '/App_KernelProdContainer*.php') as $file) {
    require_once $file;
}

// Preload Symfony framework classes most often used per request
$vendorDir = dirname(__DIR__) . '/vendor';
$preloadFiles = [
    $vendorDir . '/symfony/http-kernel/HttpKernel.php',
    $vendorDir . '/symfony/http-foundation/Request.php',
    $vendorDir . '/symfony/http-foundation/Response.php',
    $vendorDir . '/symfony/http-foundation/JsonResponse.php',
    $vendorDir . '/symfony/routing/Router.php',
    $vendorDir . '/symfony/dependency-injection/Container.php',
    $vendorDir . '/doctrine/orm/src/EntityManager.php',
];

foreach ($preloadFiles as $file) {
    if (file_exists($file)) {
        require_once $file;
    }
}
```

Preloading provides the greatest benefit for cold-start time (e.g., rolling restarts in Kubernetes). At steady state, OPcache is already warm and preloading has minimal impact on throughput.

### OPcache and debug mode

Never run with `APP_DEBUG=1` in production or benchmarks. Debug mode disables OPcache's file timestamp optimization (`opcache.validate_timestamps` is forced on) and adds Symfony's debug event subscribers and profiler overhead. The performance difference between debug and production mode is typically 3–10× for request handling overhead.

---

## Connection pool sizing

Each Swoole worker thread maintains a pool of database connections. Connections are borrowed by coroutines during request handling and returned after `$resetter->reset()` completes.

### Pool size formula

The connection pool per worker should equal the `kernel_pool_size`:

```
db_pool_size_per_worker = kernel_pool_size
total_db_connections = workers × kernel_pool_size
```

With `workers=4` and `kernel_pool_size=8`, the application maintains 32 total database connections at maximum concurrency. Set `DB_POOL_SIZE=32` (or equivalent configuration for the pooling middleware in use).

### Over- and under-provisioning

**Under-provisioning (`DB_POOL_SIZE < kernel_pool_size`):** Coroutines wait for a connection to become available. This adds latency to every request when the pool is saturated. The pending queue in `KernelPoolActor` grows, increasing tail latency across the board.

**Over-provisioning (`DB_POOL_SIZE > total_db_connections`):** Excess connections are never used and waste MySQL's per-connection memory (typically 4–8 MB per connection). MySQL's `max_connections` setting must accommodate all application server processes.

### Monitoring pool exhaustion

Add pool wait time to application metrics. In `SwoolePoolMiddleware` or the connection pool implementation, log when a coroutine waits more than a threshold:

```php
$waitStart = microtime(true);
$connection = $pool->borrow(); // blocks coroutine until available
$waitMs = (microtime(true) - $waitStart) * 1000;

if ($waitMs > 5.0) {
    $this->logger->warning('Connection pool wait exceeded threshold', [
        'wait_ms' => round($waitMs, 2),
        'pool_size' => $pool->getSize(),
        'pool_in_use' => $pool->getInUse(),
    ]);
}
```

Sustained pool wait times above 5 ms indicate under-provisioning. Increase `kernel_pool_size` and `DB_POOL_SIZE` proportionally, or investigate slow queries that are holding connections longer than expected.

### MySQL tuning

The symfony-demo `docker-compose.yml` configures MySQL with the following flags for maximum throughput:

```yaml
command: >
    --innodb-flush-log-at-trx-commit=2
    --innodb-flush-method=O_DIRECT
    --innodb-buffer-pool-size=512M
    --innodb-log-file-size=256M
    --innodb-io-capacity=2000
    --innodb-io-capacity-max=4000
    --sync-binlog=0
    --skip-log-bin
```

| Flag | Default | Tuned | Effect |
|---|---|---|---|
| `innodb_flush_log_at_trx_commit` | `1` (fsync per commit) | `2` (flush per second) | Eliminates per-commit fsync; up to 10× write throughput at the cost of up to 1 second of data on crash |
| `sync_binlog` | `1` | `0` | Disables binary log sync; reduces write latency |
| `skip-log-bin` | off | on | Disables binary log entirely; removes all binlog overhead |
| `innodb_buffer_pool_size` | 128 MB | 512 MB | More data in memory, fewer disk reads |
| `innodb_log_file_size` | 48 MB | 256 MB | Larger redo log reduces checkpoint frequency |
| `innodb_flush_method` | `fsync` | `O_DIRECT` | Bypasses OS page cache for InnoDB I/O |

**Production note:** `innodb_flush_log_at_trx_commit=2` and `skip-log-bin` trade durability for throughput. For financial or audit-critical data, revert to the MySQL defaults (`innodb_flush_log_at_trx_commit=1`, no `skip-log-bin`) and expect approximately 30–50% lower write throughput.

---

## Symfony kernel optimization

A leaner Symfony kernel reduces per-boot time (which affects startup and crash recovery) and per-request overhead from heavier services.

### Production-mode requirements

Always boot the kernel with `APP_DEBUG=0` and `APP_ENV=prod`:

```php
// public/index.php
return static fn (array $context): Kernel => new Kernel(
    $context['APP_ENV'],          // 'prod'
    (bool) $context['APP_DEBUG'], // false
);
```

The compiled DI container must be present in `var/cache/prod/` before starting the server. Generate it during deployment:

```bash
php bin/console cache:warmup --env=prod
```

### Disabling expensive production features

**Symfony Profiler:** The profiler adds significant per-request overhead through data collectors. It must be disabled in production (it is by default in `prod` environment, but verify):

```yaml
# config/packages/prod/web_profiler.yaml
web_profiler:
    toolbar: false
    intercept_redirects: false
```

**Doctrine metadata cache:** Doctrine reads entity mapping metadata on every boot unless a cache adapter is configured:

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        metadata_cache_driver:
            type: pool
            pool: doctrine.system_cache_pool
        query_cache_driver:
            type: pool
            pool: doctrine.system_cache_pool
        result_cache_driver:
            type: pool
            pool: doctrine.result_cache_pool
```

**Doctrine result cache:** Cache frequently-executed read queries:

```php
$query = $this->em->createQuery('SELECT p FROM Product p WHERE p.active = true');
$query->enableResultCache(300, 'products.active'); // cache 300 seconds
```

**Doctrine second-level cache (2LC):** For entities read frequently and modified rarely, the 2LC eliminates database queries entirely after the first request:

```yaml
doctrine:
    orm:
        second_level_cache:
            enabled: true
```

### Reducing service graph size

Each service instantiated at kernel boot adds to per-actor memory. Audit the service graph:

```bash
php bin/console debug:container --show-private | wc -l
```

Mark services as `lazy: true` that are not needed on every request. Lazy services are proxied — instantiation is deferred until first use:

```yaml
# config/services.yaml
App\Service\HeavyReportingService:
    lazy: true
```

Lazy services reduce kernel boot time and per-kernel memory for actors that never invoke the service.

---

## Profiling

### SPX

SPX (Simple PHP eXtension) works inside Swoole coroutines and has minimal overhead when profiling is disabled. Add profiling to a specific request by wrapping the controller action:

```php
use Monadial\Nexus\Core\Actor\ActorContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

final class CatalogController
{
    public function index(Request $request): JsonResponse
    {
        spx_profiler_start();
        try {
            $products = $this->catalogRepository->findActive();
            return new JsonResponse($products);
        } finally {
            spx_profiler_stop();
        }
    }
}
```

SPX output is written to `/tmp/spx/` by default. The SPX web UI at `http://localhost:8080/_spx` renders call graphs and flame charts. Enable the SPX UI only in development environments — it exposes profiling data to all clients.

Configure SPX in `php.ini` or `docker/php.ini`:

```ini
[spx]
spx.http_enabled = 1
spx.http_key = "dev-only"
spx.http_ip_var = REMOTE_ADDR
spx.http_trusted_proxies = 127.0.0.1
```

### Blackfire

Blackfire's PHP probe works with Swoole coroutines in PHP 8.5. Add the Blackfire probe to a specific route in a development environment:

```php
$probe = \BlackfireProbe::getMainInstance();
$probe->enable();

$result = $this->service->heavyOperation();

$probe->disable();
```

For automated profiling, use Blackfire's CLI:

```bash
blackfire curl http://localhost:8080/catalog
```

Blackfire connects to the probe running in the PHP process and generates a call graph with inclusive and exclusive time breakdowns per function call.

### Custom timing

For lightweight per-request timing without a profiler:

```php
final class TimingMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $start = hrtime(true);
        $response = $next($request);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $response->headers->set('X-Response-Time-Ms', (string) round($elapsedMs, 2));

        return $response;
    }
}
```

For actor-level timing, use `$ctx->log()` with structured data:

```php
public function handle(ActorContext $ctx, object $message): Behavior
{
    $start = hrtime(true);

    // ... handle message ...

    $ctx->log()->debug('Message handled', [
        'type' => $message::class,
        'elapsed_ms' => round((hrtime(true) - $start) / 1_000_000, 2),
    ]);

    return Behavior::same();
}
```

---

## Identifying bottlenecks

### Symptom: high pending queue depth, rising p99

The `KernelPoolActor`'s pending queue is accumulating requests faster than kernels can process them.

**Diagnosis:** Monitor the pending queue depth. If it consistently approaches `kernel_pool_max_pending`, the pool is undersized for the load.

**Resolution:**
1. Increase `kernel_pool_size` (adds concurrency within each worker, costs more memory).
2. Increase `workers` (adds OS threads, costs more CPU and memory per thread).
3. Check for slow queries — a single slow endpoint can monopolize kernel slots and starve other endpoints.

### Symptom: high MySQL query latency, all endpoints affected

Database queries are slower than expected. The pool is not the bottleneck — the database is.

**Diagnosis:** Check MySQL's slow query log:

```sql
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 0.1;   -- log queries > 100 ms
SET GLOBAL slow_query_log_file = '/var/log/mysql/slow.log';
```

Run `pt-query-digest` on the slow query log to identify problematic queries:

```bash
pt-query-digest /var/log/mysql/slow.log | head -100
```

**Resolution:** Add indexes, optimize queries, add result caching.

### Symptom: memory growing indefinitely (apparent leak)

Worker process RSS grows over time without bound.

**Causes:**
- **Doctrine Unit of Work not cleared.** If `$entityManager->clear()` is not called after each request (normally handled by `services_resetter`), the identity map accumulates all entities ever loaded. Verify that `services_resetter` is registered and called.
- **Static caches in actors.** Application actors that accumulate data in instance variables without a bounded eviction policy grow indefinitely. Use an LRU cache or bound the data structure.
- **Circular references.** PHP's garbage collector handles cycles but does not run automatically between every request. For large object graphs, call `gc_collect_cycles()` periodically in a long-lived actor.

**Diagnosis:**

```php
// Controller diagnostic — expose only in dev/staging
return new JsonResponse([
    'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
    'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
]);
```

Track `memory_get_usage(true)` before and after each request in a `kernel.request` / `kernel.response` subscriber pair. If the delta is consistently positive, a service is retaining objects across requests.

### Symptom: 503 responses during normal load

The pending queue is full. Incoming requests arrive faster than the pool can process them.

**Diagnosis:** Check whether the 503s are isolated spikes (short traffic bursts) or sustained. For spikes, increase `kernel_pool_max_pending`. For sustained 503s, the pool is undersized.

Monitor the 503 rate:

```bash
# Access log format: $status $request_time
awk '$1 == 503' /var/log/nginx/access.log | wc -l
```

**Resolution:**
- Short spike: increase `kernel_pool_max_pending` to absorb bursts.
- Sustained: increase `kernel_pool_size` and `workers`.
- Check for upstream slowness — a slow downstream service (database, external API) can back up the entire pool.

### Symptom: inconsistent request latency (high jitter)

Requests alternate between fast responses and slow responses. p50 is acceptable but p99 is significantly higher.

**Causes:**
- **Pending queue building under bursts.** Some requests are served immediately from idle kernels; others wait in the pending queue.
- **GC pauses.** PHP's garbage collector running between requests adds jitter. For latency-sensitive applications, tune the GC cycle:
  ```php
  gc_disable(); // in WorkerStartBootstrapper::onWorkerStart()
  // Then call gc_collect_cycles() explicitly at a predictable point
  ```
- **Doctrine slow queries.** An occasionally-slow query can cause a kernel slot to be occupied longer than expected, pushing other requests into the pending queue.

---

## Benchmarking methodology

### Tool selection

`wrk` and `bombardier` are preferred for HTTP benchmarking. `ab` (Apache Bench) is unsuitable for concurrent benchmarks because it does not report latency percentiles accurately.

```bash
# Install wrk
apt-get install wrk

# Install bombardier
go install github.com/codesenberg/bombardier@latest
```

### Warmup procedure

Always discard the first measurement run. OPcache, Doctrine metadata cache, and kernel pool initialization affect the first N requests. A 15-second warmup run at target concurrency is sufficient:

```bash
# Warmup — discard output
wrk -t4 -c200 -d15s http://localhost:8080/catalog

# Measurement
wrk -t4 -c200 -d60s --latency http://localhost:8080/catalog
```

### Connection count guidance

Set `-c` (connections) to at least `workers × kernel_pool_size` to fully saturate the pool. Below this, some kernel slots will remain idle during the test.

```
-c >= workers × kernel_pool_size
```

For `workers=4`, `kernel_pool_size=8`: use at least `-c 32`. For a realistic load test, use 2–4× the pool capacity to ensure the pending queue is exercised:

```bash
wrk -t4 -c128 -d60s --latency http://localhost:8080/catalog
```

### Benchmark checklist

Before recording results:

- `APP_DEBUG=0` — debug mode disabled
- `APP_ENV=prod` — production environment
- DI container compiled (`bin/console cache:warmup --env=prod`)
- `opcache.validate_timestamps=0` in `php.ini`
- Warmup run completed and discarded
- MySQL buffer pool warmed (run a SELECT query against the dataset before benchmarking)
- Redis cache warmed (run target endpoints before benchmarking)
- No other significant load on the machine during measurement

---

## Graceful shutdown

On `SIGTERM`, `GracefulShutdownHandler` calls `$actorSystem->shutdown($timeout)`. The actor system processes all in-flight messages up to `shutdown_timeout` seconds before stopping. Configure this timeout to be longer than the expected maximum request duration:

```yaml
# config/packages/nexus.yaml
nexus:
    shutdown_timeout: 30  # seconds
```

During graceful shutdown, the Swoole server stops accepting new connections. In-flight requests in the kernel pool are completed before the actor system stops. Requests in the pending queue are either completed (if time allows) or receive 503 responses.

In Kubernetes, set `terminationGracePeriodSeconds` to `shutdown_timeout + 5` to allow the OS to deliver `SIGTERM` and wait for the process to exit cleanly before sending `SIGKILL`:

```yaml
# kubernetes/deployment.yaml
spec:
  template:
    spec:
      terminationGracePeriodSeconds: 35
      containers:
        - name: app
          lifecycle:
            preStop:
              exec:
                command: ["sleep", "2"]  # Give load balancer time to drain
```

---

## Horizontal scaling

A single `php public/index.php` process is limited to the resources of one machine. For capacity beyond what a single process can handle, run multiple processes behind a load balancer.

### Nginx upstream configuration

```nginx
upstream nexus_backend {
    # Four processes on the same host, each listening on a different port
    server 127.0.0.1:8080 weight=1;
    server 127.0.0.1:8081 weight=1;
    server 127.0.0.1:8082 weight=1;
    server 127.0.0.1:8083 weight=1;

    # Or multiple hosts in a cluster
    # server 10.0.1.10:8080 weight=1;
    # server 10.0.1.11:8080 weight=1;

    keepalive 64;           # Keep upstream connections open
    keepalive_requests 100;
    keepalive_timeout 60s;
}

server {
    listen 80;

    location / {
        proxy_pass http://nexus_backend;
        proxy_http_version 1.1;
        proxy_set_header Connection "";  # Required for keepalive
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_connect_timeout 5s;
        proxy_read_timeout 30s;
    }
}
```

### Total capacity formula

```
total_req_s ≈ processes × workers × kernel_pool_size × (1000 / avg_latency_ms)
```

For 4 processes, each with `workers=4` and `kernel_pool_size=8`, with 5 ms average latency:

```
total_req_s ≈ 4 × 4 × 8 × (1000 / 5) = 25 600 simultaneous request slots
```

Each slot can complete `1000 / avg_latency_ms` requests per second:

```
sustained_req_s ≈ 4 × 4 × 8 × 200 = 25 600 req/s theoretical maximum
```

### Process supervision

Use a process supervisor to restart crashed processes and manage multiple instances on a single host:

```ini
; /etc/supervisor/conf.d/nexus.conf
[program:nexus-0]
command = php /app/public/index.php
environment = APP_RUNTIME_OPTIONS='{"port":8080,"workers":4,"kernel_pool_size":8}'
autostart = true
autorestart = true
stderr_logfile = /var/log/nexus/worker-0.log

[program:nexus-1]
command = php /app/public/index.php
environment = APP_RUNTIME_OPTIONS='{"port":8081,"workers":4,"kernel_pool_size":8}'
autostart = true
autorestart = true
stderr_logfile = /var/log/nexus/worker-1.log
```

### Stateless requirement

All horizontal scaling assumes stateless request handling. Application actors within a single process are not accessible from other processes. Shared state (session data, distributed locks, rate limiter counters) must live in an external store (Redis, database) accessible to all processes.

Actors that maintain in-memory caches or accumulators are local to their worker thread and process. If a request lands on a different process, it sees a different actor instance with independently-maintained state. Design actors accordingly: caches are populated from the same external source, accumulators are periodically flushed to the external store.
