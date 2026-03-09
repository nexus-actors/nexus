# nexus-symfony

## What is nexus-symfony?

`monadial/nexus-symfony` replaces PHP-FPM and Nginx with a Swoole-based HTTP server that runs a full Nexus actor system inside every worker thread. Each worker boots a `KernelPoolActor` — a pool of independent Symfony kernel instances — so incoming requests are distributed across idle kernels without forking a new process or reloading the container between requests. Long-lived actors (catalog services, inventory agents, background processors) are spawned once per worker at startup and remain in memory for the lifetime of the server, sharing state within a worker through the actor model rather than through shared mutable globals.

---

## Architecture overview

```
                ┌──────────────────────────────────────────────────────────────┐
                │  OS Process  (php public/index.php)                          │
                │                                                              │
                │  Swoole HTTP Server — SWOOLE_THREAD mode (NexusRunner)       │
                │                                                              │
                │  ┌──────────────────────┐   ┌──────────────────────┐        │
  HTTP ────────►│  │  Worker 0            │   │  Worker 1            │  …     │
                │  │                      │   │                      │        │
                │  │  ActorSystem         │   │  ActorSystem         │        │
                │  │  nexus-worker-0      │   │  nexus-worker-1      │        │
                │  │                      │   │                      │        │
                │  │  ┌────────────────┐  │   │  ┌────────────────┐  │        │
                │  │  │ KernelPoolActor│  │   │  │ KernelPoolActor│  │        │
                │  │  │                │  │   │  │                │  │        │
                │  │  │  ┌──────────┐  │  │   │  │  ┌──────────┐  │  │        │
                │  │  │  │ kernel-0 │  │  │   │  │  │ kernel-0 │  │  │        │
                │  │  │  ├──────────┤  │  │   │  │  ├──────────┤  │  │        │
                │  │  │  │ kernel-1 │  │  │   │  │  │ kernel-1 │  │  │        │
                │  │  │  ├──────────┤  │  │   │  │  ├──────────┤  │  │        │
                │  │  │  │ kernel-2 │  │  │   │  │  │ kernel-2 │  │  │        │
                │  │  │  ├──────────┤  │  │   │  │  ├──────────┤  │  │        │
                │  │  │  │    …     │  │  │   │  │  │    …     │  │  │        │
                │  │  │  └──────────┘  │  │   │  │  └──────────┘  │  │        │
                │  │  └────────────────┘  │   │  └────────────────┘  │        │
                │  │                      │   │                      │        │
                │  │  CatalogActor        │   │  CatalogActor        │        │
                │  │  InventoryActor      │   │  InventoryActor      │        │
                │  │  OrderPipelineActor  │   │  OrderPipelineActor  │        │
                │  └──────────────────────┘   └──────────────────────┘        │
                └──────────────────────────────────────────────────────────────┘
```

Each Swoole worker thread is a self-contained unit: one `ActorSystem`, one `KernelPoolActor`, N `KernelActor` children, and any number of isolated application actors. Workers do not share memory. The Swoole server dispatches incoming HTTP connections to workers; within a worker, the `KernelPoolActor` dispatches requests to idle kernels via the actor mailbox system.

---

## Key concepts

### Kernel pool

The kernel pool is the concurrency mechanism for Symfony request handling inside a single-threaded Swoole worker. `Symfony\Component\HttpKernel\HttpKernel::handle()` is not safe for concurrent invocation within one process. The pool solves this by maintaining N independent kernel instances: each kernel handles at most one request at a time, so concurrency equals the pool size. While kernel-0 awaits a database query, kernel-1 handles a different incoming request.

See [kernel-pool.md](kernel-pool.md) for the full protocol, state diagrams, backpressure model, and tuning guidance.

### Isolated actors

Application actors tagged with `#[Actor]` are spawned once per worker at startup and remain alive across all requests. They are not reset between requests and accumulate state for the lifetime of the worker. This makes them suitable for in-memory caches, background job processors, event projectors, and rate limiters.

See [actors-in-symfony.md](actors-in-symfony.md) for declaration, injection, and usage patterns.

### Coroutine-local services

Swoole replaces PHP's synchronous I/O hooks with non-blocking coroutine equivalents. Services that hold per-request state (Doctrine `EntityManager`, HTTP client sessions) must be isolated per coroutine. The `#[CoroutineScoped]` mechanism creates a fresh service instance for each coroutine rather than sharing one across concurrent requests.

See [coroutine-scoped-services.md](coroutine-scoped-services.md) for implementation details.

### SwooleEmbeddedRuntime

Inside each worker, the actor scheduler is a `SwooleEmbeddedRuntime` — the Nexus runtime implementation that uses Swoole coroutines and channels as its concurrency primitive. Each actor runs in a dedicated coroutine; mailbox suspension and resumption are backed by Swoole channels rather than PHP Fibers. This makes all blocking I/O inside actor handlers automatically cooperative: a `sleep()` or `PDO::query()` suspends the coroutine without blocking the worker thread.

---

## Requirements

| Dependency                    | Version        | Notes                                         |
|-------------------------------|----------------|-----------------------------------------------|
| PHP                           | 8.5+           | ZTS (Zend Thread Safety) build required       |
| Swoole                        | 6.0+           | Must be compiled with `--enable-swoole-thread`|
| Symfony                       | 7.x            |                                               |
| `monadial/nexus-core`         | `^0.x`         |                                               |
| `monadial/nexus-runtime-swoole` | `^0.x`       |                                               |

> **Caution:** A non-ZTS PHP build will boot but Swoole thread mode will not be available. The server will fail to start. Verify ZTS with `php -i | grep 'Thread Safety'`.

---

## Installation

```bash
composer require monadial/nexus-symfony
```

Register `NexusRuntime` as the application runtime in `public/index.php`:

```php
<?php

use App\Kernel;
use Monadial\Nexus\Symfony\Runtime\NexusRuntime;

$_SERVER['APP_RUNTIME'] = NexusRuntime::class;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static fn (array $context): Kernel => new Kernel(
    $context['APP_ENV'],
    (bool) $context['APP_DEBUG'],
);
```

Pass runtime options via the `APP_RUNTIME_OPTIONS` environment variable (JSON):

```dotenv
APP_RUNTIME_OPTIONS={"host":"0.0.0.0","port":8080,"workers":4,"kernel_pool_size":8,"kernel_pool_max_pending":100}
```

Start the server:

```bash
php public/index.php
```

See [getting-started.md](getting-started.md) for bundle registration, full configuration reference, and Docker setup.

---

## Documentation map

| Document                                                   | Description                                                                 |
|------------------------------------------------------------|-----------------------------------------------------------------------------|
| [getting-started.md](getting-started.md)                   | Installation, bundle registration, runtime configuration, first run         |
| [kernel-pool.md](kernel-pool.md)                           | `KernelPoolActor` and `KernelActor` internals, backpressure, crash recovery |
| [actors-in-symfony.md](actors-in-symfony.md)               | Declaring actors with `#[Actor]`, injecting `ActorRef`, `ask()` vs `tell()` |
| [coroutine-scoped-services.md](coroutine-scoped-services.md) | Per-request isolation under Swoole using `#[CoroutineScoped]`             |
| [observability.md](observability.md)                       | Request ID propagation, Monolog processor, tracing integration              |
| [performance.md](performance.md)                           | Benchmark results, tuning workers and kernel pool size, MySQL tips          |

---

## Performance snapshot

Benchmarks run on a 4-core machine with `workers=4` and `kernel_pool_size=8`. Numbers reflect sustained throughput, not burst peaks.

| Workload                      | Req/s  | p50    |
|-------------------------------|--------|--------|
| Pure PHP handler (no I/O)     | ~88k   | 1.3 ms |
| MySQL SELECT (single row)     | ~54k   | 4 ms   |
| MySQL INSERT + commit         | ~49k   | 3.6 ms |

> **Note:** These numbers represent a single application server process. Horizontal scaling (multiple `php public/index.php` processes behind a load balancer) is additive.

See [performance.md](performance.md) for methodology, raw data, and comparison against PHP-FPM.
