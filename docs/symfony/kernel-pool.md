# Kernel pool

## Overview

`Symfony\Component\HttpKernel\HttpKernel::handle()` is a stateful operation. It modifies the kernel's internal service container state, populates request-scoped services, and expects each call to complete before the next begins. In a PHP-FPM environment this constraint is satisfied automatically: each worker process handles one request at a time. Under Swoole, a single worker thread can interleave multiple coroutines — without isolation, two concurrent requests would corrupt each other's service state.

The kernel pool solves this with the simplest possible mechanism: one kernel instance per concurrent request slot. Each slot owns one `KernelActor` child, which holds one booted `HttpKernelInterface` instance for its entire lifetime. Because a kernel actor handles at most one `KernelDispatch` message at a time, the Symfony kernel is never accessed concurrently. Concurrency within a worker equals the number of pool slots.

While kernel-0 suspends its coroutine waiting on a MySQL query, kernel-1 processes a different request. Swoole's coroutine scheduler resumes kernel-0 when the query returns — all within the same OS thread, with no locking.

---

## Components

### KernelActor

`KernelActor` (`packages/nexus-symfony/src/KernelPool/KernelActor.php`) owns the full lifecycle of a single Symfony kernel instance. It extends `AbstractActor` to participate in the three lifecycle hooks.

**Startup — `onPreStart(ActorContext $ctx)`**

The factory closure is invoked with the current `$_SERVER + $_ENV` environment array. If the result implements `KernelInterface`, the kernel is booted and the service container is interrogated for two additional services:

- `nexus.actor_system` — set to the worker's `ActorSystem` instance so injected services can send actor messages.
- `nexus.runtime` — set to the worker's `SwooleEmbeddedRuntime` instance.
- `services_resetter` — if present, stored as `$this->resetter` for post-request cleanup.

Booting is synchronous: `$kernel->boot()` blocks the coroutine until the DI container is compiled and all boot-time service instantiation completes. This happens once per kernel actor, not once per request.

**Request handling — `handle(ActorContext $ctx, object $message)`**

The handler accepts only `KernelDispatch` messages. On receipt:

1. `$kernel->handle($request)` executes the full Symfony dispatch cycle — routing, controller resolution, response building — inside the current Swoole coroutine. Any I/O performed by the controller (Doctrine queries, HTTP client calls) yields the coroutine cooperatively.
2. `$kernel->terminate($request, $response)` is called if the kernel implements `TerminableInterface`. This runs `kernel.terminate` event listeners (profiler data collection, deferred log flushes).
3. `$this->resetter->reset()` resets all services tagged `kernel.reset` in the DI container. This is the same mechanism used by Runtime FrankenPHP and php-pm to prevent request state from leaking into the next request.
4. `$message->replyTo->tell(new KernelResponse($response))` delivers the HTTP response to the `ask()` future slot, resuming the Swoole request handler coroutine.
5. `$ctx->parent()?->tell(new KernelReady($ctx->self()))` signals the pool actor that this kernel is idle and ready for the next dispatch.

Steps 4 and 5 always occur after a successful request, in that order. The actor returns `Behavior::same()` — no state transition occurs.

**Shutdown — `onPostStop(ActorContext $ctx)`**

`$kernel->shutdown()` tears down the Symfony kernel: it dispatches the `kernel.terminate` event (if not already dispatched) and runs `ContainerBuilder::reset()`. This is called by the Nexus supervision tree when the actor system shuts down gracefully.

### KernelPoolActor

`KernelPoolActor` (`packages/nexus-symfony/src/KernelPool/KernelPoolActor.php`) is the pool coordinator. It is not a class-based actor in the traditional sense: its state is held in a private object instance created inside a `Behavior::setup()` closure, and its behavior is built from two closures (message handler and signal handler).

**State**

```
$idle      SplQueue<ActorRef>                  Kernel refs ready for immediate dispatch
$pending   SplQueue<{replyTo, request}>        Requests waiting for an idle kernel
$inFlight  array<string, ActorRef>             kernel-path → replyTo, for crash recovery
```

`$inFlight` is keyed by actor path string (e.g. `nexus-worker-0/kernel-pool/kernel-3`). This allows the `ChildFailed` signal handler to look up the waiting caller for a crashed kernel without an O(n) scan of the queue.

**Initialization**

`KernelPoolActor::props()` returns a `Props` wrapping a `Behavior::setup()` closure. When the actor starts, the closure constructs a `KernelPoolActor` instance and calls `init()`, which spawns N `KernelActor` children (`kernel-0` through `kernel-(N-1)`) and enqueues their refs into `$idle`. The behavior returned from `init()` handles messages for the lifetime of the pool.

**Message handling**

The pool actor handles three event types:

- `HandleRequest` — inbound from `NexusRunner` via `ask()`
- `KernelReady` — inbound from a `KernelActor` after completing a request
- `ChildFailed` (signal) — delivered by the Nexus supervisor when a child actor throws

---

## Request flow

```
HTTP request arrives at Swoole
         │
         ▼
NexusRunner::request callback (Swoole coroutine)
         │
         │  $poolRef->ask(new HandleRequest($request), Duration::seconds(30))
         │  (coroutine suspends, awaiting KernelResponse)
         ▼
KernelPoolActor — onHandleRequest()
         │
         ├─── idle queue non-empty?
         │         │
         │        YES
         │         │  dequeue kernel ref
         │         │  record inFlight[kernel-path] = replyTo
         │         │  kernel->tell(new KernelDispatch($request, $replyTo))
         │         ▼
         │    KernelActor — handle()
         │         │  $kernel->handle($request)        ← coroutine may yield here
         │         │  $kernel->terminate(...)
         │         │  $resetter->reset()
         │         │  $replyTo->tell(new KernelResponse($response))
         │         │  $ctx->parent()->tell(new KernelReady($ctx->self()))
         │         ▼
         │    NexusRunner coroutine resumes
         │         │  bridge->sendSymfonyResponse($response, $swooleResponse)
         │         ▼
         │    HTTP response flushed to client
         │
         │    KernelPoolActor — onKernelReady()
         │         │  unset inFlight[kernel-path]
         │         │
         │         ├─── pending non-empty?
         │         │         │
         │         │        YES → dequeue, record inFlight, tell KernelDispatch
         │         │        NO  → enqueue kernel ref back to idle
         │         └──────────────────────────────────────────────────────────
         │
         ├─── idle empty AND count(pending) < maxPending?
         │         │
         │        YES → enqueue {replyTo, request} to pending queue
         │              (NexusRunner coroutine remains suspended)
         │
         └─── idle empty AND count(pending) >= maxPending?
                   │
                  YES → replyTo->tell(new KernelResponse(503 Too Many Requests))
                         NexusRunner coroutine resumes, 503 flushed to client
```

---

## KernelActor state diagram

```
  spawn()
     │
     ▼
  ┌─────────────────────────────────────────┐
  │  [Starting]                             │
  │  onPreStart():                          │
  │    invoke kernelFactory                 │
  │    $kernel->boot()                      │
  │    wire services_resetter               │
  │    set nexus.actor_system on container  │
  └───────────────────┬─────────────────────┘
                      │
                      ▼
  ┌─────────────────────────────────────────┐◄──────────────────────────────────┐
  │  [Idle]                                 │                                   │
  │  (ref held in KernelPoolActor.$idle)    │                                   │
  └───────────────────┬─────────────────────┘                                   │
                      │                                                          │
                      │  KernelDispatch received                                 │
                      ▼                                                          │
  ┌─────────────────────────────────────────┐                                   │
  │  [InFlight]                             │                                   │
  │  $kernel->handle()      ← may yield     │                                   │
  │  $kernel->terminate()                   │                                   │
  │  $resetter->reset()                     │                                   │
  │  tell KernelResponse to replyTo         │                                   │
  │  tell KernelReady to parent             │                                   │
  └──────┬──────────────────────────────────┘                                   │
         │                                                                       │
         │  success                               exception thrown               │
         └──────────────────────────────────────►[Failed / stopped by supervisor]│
                          │                                                      │
                          │  ChildFailed signal → KernelPoolActor                │
                          │    tell 503 to waiting caller                        │
                          │    spawn replacement KernelActor (kernel-N+counter)  │
                          └─────────────────────────────────────────────────────┘
                                     replacement enters [Idle]

  [Idle] ──── actor system shutdown ────► [Stopping]
                                               │
                                               ▼
                                          onPostStop():
                                            $kernel->shutdown()
                                               │
                                               ▼
                                          [Stopped]
```

---

## Backpressure and overflow

The pool implements three-level backpressure:

**Level 1 — Immediate dispatch.** An idle kernel is available. The request is dispatched synchronously within the actor message handler. Zero queuing latency.

**Level 2 — Pending queue.** All kernels are busy but the pending queue has capacity (`count($pending) < maxPending`). The request and its reply ref are enqueued. The Swoole request handler coroutine remains suspended. When a kernel finishes, `onKernelReady()` immediately dequeues the next pending request and dispatches it — the kernel never enters the idle queue.

**Level 3 — Overflow rejection.** All kernels are busy and the pending queue is full. A `KernelResponse` with HTTP 503 is sent immediately. The Swoole request handler coroutine resumes and flushes the 503 to the client. No waiting, no timeout expiry.

Immediate rejection at level 3 is the correct behavior. The alternatives — blocking until a slot is free, or growing the queue without bound — both lead to cascading timeout failures. A 503 with `Retry-After` is actionable; a 30-second timeout is not.

**maxPending sizing formula**

A reasonable starting value for `maxPending` can be derived from target traffic:

```
maxPending = workers × kernel_pool_size × (expected_request_latency_ms / 1000) × target_rps
```

For example: 4 workers, pool size 8, 10 ms average latency, 2000 req/s target:

```
maxPending = 4 × 8 × 0.010 × 2000 = 640
```

This represents the queue depth needed to absorb a 1-second burst at target throughput without dropping requests.

> **Tip:** Start with `maxPending = 2 × kernel_pool_size` during development. Increase toward the formula value in production after profiling request latency distribution.

---

## Crash recovery

When a `KernelActor` throws an uncaught exception during `handle()`, the Nexus supervision tree delivers a `ChildFailed(ActorRef $child, Throwable $cause)` signal to `KernelPoolActor`.

The signal handler performs the following steps atomically within a single actor message turn:

1. **Identify the waiting caller.** The crashed kernel's path string is looked up in `$inFlight`. If found, the associated `replyTo` ref holds the `ask()` future slot for the in-flight request.
2. **Reply 503 to the caller.** `$replyTo->tell(new KernelResponse(new Response('Service unavailable', 503)))` resumes the Swoole request handler coroutine immediately. The HTTP client receives a 503 response.
3. **Remove from inFlight.** `unset($this->inFlight[$path])` clears the stale entry.
4. **Spawn a replacement kernel.** A new `KernelActor` is spawned using a counter-based name (`kernel-{$this->kernelCounter}`) to avoid collisions with the failed actor's name (actor names must be unique among siblings; the failed actor's name is still registered until its supervisor processes the termination). `$this->kernelCounter` increments on each replacement.
5. **Place the replacement in idle.** The replacement ref is enqueued into `$idle`. The pool is immediately available for the next dispatch.

The pool size is maintained automatically: each crash produces exactly one replacement. The failed kernel is never returned to circulation.

> **Note:** If the crashed kernel was not in `$inFlight` when `ChildFailed` arrives (e.g. it crashed during `onPreStart` before receiving any request), the signal handler still spawns a replacement without sending a 503 — there is no waiting caller to notify.

---

## Services resetter

If `services_resetter` is present in the Symfony service container, `KernelActor` stores a reference to it during `onPreStart()` and calls `$this->resetter->reset()` after every request.

The `services_resetter` service is the standard Symfony service resetter, registered automatically when `framework.reset_on_exception` is enabled or when services are tagged with `kernel.reset`. It resets:

- Doctrine `EntityManager` instances (clears the identity map, closes the connection if needed)
- HTTP client handles
- Logger processors with per-request state
- Any service implementing `ResetInterface` and tagged `kernel.reset`

**Trade-off:** Resetting the `EntityManager` after every request adds a small latency cost (typically < 0.5 ms) but prevents entity manager corruption where a previous request left a pending transaction or a detached entity in the identity map. For most applications, the safety benefit outweighs the cost.

To disable the resetter for a specific service, remove the `kernel.reset` tag from its definition:

```yaml
# config/services.yaml
services:
    App\Service\MyHeavyStatefulService:
        tags:
            - { name: kernel.reset, method: reset }
```

Remove that tag block to exclude the service from the reset cycle. Ensure the service is stateless or manages its own state isolation before doing so.

---

## Configuration

Configure via the `APP_RUNTIME_OPTIONS` environment variable (JSON string passed to `NexusRuntime`):

```dotenv
APP_RUNTIME_OPTIONS={"host":"0.0.0.0","port":8080,"workers":4,"kernel_pool_size":8,"kernel_pool_max_pending":100}
```

All options have defaults in `NexusRuntime::DEFAULT_OPTIONS`.

| Option                    | Default     | Description                                                         |
|---------------------------|-------------|---------------------------------------------------------------------|
| `host`                    | `0.0.0.0`   | IP address the Swoole server binds to                               |
| `port`                    | `8080`      | TCP port                                                            |
| `workers`                 | `4`         | Number of Swoole worker threads                                     |
| `kernel_pool_size`        | `8`         | Number of kernel actors per worker (maximum in-worker concurrency)  |
| `kernel_pool_max_pending` | `100`       | Maximum pending queue depth per worker before returning 503         |

> **Caution:** `kernel_pool_size` controls per-worker concurrency, not total server concurrency. Total maximum concurrent requests = `workers × kernel_pool_size`.

---

## Tuning guide

### Choosing workers and kernel_pool_size

Total concurrency = `workers × kernel_pool_size`. The split between these two dimensions has different implications:

- **More workers, smaller pool:** More OS threads, more CPU parallelism for CPU-bound work. Higher thread scheduling overhead. Better for workloads with significant computation per request.
- **Fewer workers, larger pool:** Fewer OS threads, lower thread overhead. Higher intra-worker coroutine concurrency. Better for I/O-bound workloads where requests spend most of their time waiting.

| Scenario                              | `workers`   | `kernel_pool_size` | `max_pending` | Notes                                           |
|---------------------------------------|-------------|---------------------|---------------|-------------------------------------------------|
| API with fast handlers (< 5 ms)       | CPU count   | 4–8                 | 100           | Low pool sufficient; few kernels needed         |
| API with DB queries (5–50 ms)         | CPU count   | 8–16                | 200           | More kernels absorb I/O wait latency            |
| Mixed heavy/light requests            | CPU count   | 16–32               | 500           | Large pool flattens tail latency under load     |
| Memory constrained environment        | 2–4         | 4                   | 50            | Approximately 15 MB per booted kernel instance  |

**Rule of thumb for I/O-bound applications:** `kernel_pool_size ≈ average_request_latency_ms / target_scheduling_overhead_ms`. If a typical request spends 10 ms on I/O and the acceptable scheduling overhead is 1 ms, a pool of 10 keeps the worker busy throughout.

### Memory

Each `KernelActor` boots a full Symfony container. Memory consumption per booted kernel depends on the application's service graph but is typically 10–20 MB for a mid-sized Symfony application.

```
total_memory ≈ workers × (kernel_pool_size + 1) × kernel_memory_mb
```

The `+ 1` accounts for the initial kernel booted by `NexusRunner` in `workerStart` before the pool actors are spawned (this kernel is used to obtain the container reference and is then discarded once pool setup is complete).

Example: `workers=4`, `kernel_pool_size=8`, `kernel_memory_mb=15`:

```
total_memory ≈ 4 × 9 × 15 = 540 MB
```

Set `memory_limit` in `php.ini` accordingly and use container memory limits in production. Profile actual consumption with `memory_get_peak_usage(true)` from a controller before finalizing production values.

### Latency vs. throughput

- **Fewer kernels** — Lower memory, lower p50 latency (less coroutine switching overhead), higher p99 under sustained load (queue depth increases).
- **More kernels** — Higher throughput, slightly higher p50 (more active coroutines), lower p99 under sustained load (shorter queue).

For latency-sensitive APIs (trading, payments), prefer fewer kernels with more workers. For throughput-optimized pipelines (data ingestion, batch processing), prefer more kernels.

Monitor both p50 and p99. If p99 diverges significantly from p50 under load, the pending queue is building up — add more kernels or workers.

### kernel_pool_max_pending

`maxPending` acts as a safety valve against unbounded queue growth during traffic spikes.

- Too low: clients receive 503 responses during brief traffic bursts that the pool could absorb with a slightly deeper queue.
- Too high: memory grows linearly with queue depth; p99 latency increases as requests age in the queue; clients see timeouts rather than fast 503s.

A reasonable starting value is `2 × kernel_pool_size`. For applications with bursty traffic patterns (cron-driven, event-driven fan-out), increase toward the formula value from the [Backpressure section](#backpressure-and-overflow) and monitor p99 latency. If p99 climbs above the `ask()` timeout (30 seconds by default), the queue is too deep — add capacity rather than increasing `maxPending` further.
