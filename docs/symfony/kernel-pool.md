# Kernel pool architecture

## Overview

A single Swoole worker is a single OS thread. Without concurrency within that thread, one slow request blocks all subsequent ones. The kernel pool solves this by running multiple Symfony kernels inside the same worker, each in its own Swoole coroutine. While one kernel awaits a database query (which Swoole hooks into a non-blocking I/O call), another kernel handles a different request.

The pool is composed of two actor types:

- **`KernelPoolActor`** — the pool coordinator. Owns the idle queue, the pending backlog, and the in-flight registry. One instance per worker.
- **`KernelActor`** — owns a single `Symfony\Component\HttpKernel\KernelInterface` for its lifetime. One instance per slot in the pool.

## Message protocol

```
NexusRunner (request handler)
    │
    │ ask(HandleRequest, timeout=30s)
    ▼
KernelPoolActor
    │
    ├─ if idle kernel available ──► KernelActor  (tell KernelDispatch)
    │                                    │
    │                                    │ tell(KernelResponse)  → replyTo caller
    │                                    │ tell(KernelReady)     → parent (KernelPoolActor)
    │                                    ▼
    │                               KernelPoolActor receives KernelReady
    │                                    │
    │                                    └─ dispatch next pending, or return kernel to idle
    │
    ├─ if no idle and pending < maxPending ──► queue request
    │
    └─ if no idle and pending >= maxPending ──► tell(KernelResponse(503))
```

## State machine

```
                   workerStart
                       │
                       ▼
              ┌─────────────────┐
              │   KernelActor   │
              │   onPreStart()  │  ← boots Symfony kernel, wires services_resetter
              └────────┬────────┘
                       │
                       ▼
          ┌────────────────────────┐
          │         idle           │◄──────────────────────────────────┐
          │  (in KernelPoolActor   │                                   │
          │    idle queue)         │                                   │
          └────────────┬───────────┘                                   │
                       │                                               │
                       │ KernelDispatch arrives                        │
                       ▼                                               │
          ┌────────────────────────┐                                   │
          │       in-flight        │                                   │
          │  (in KernelPoolActor   │                                   │
          │   inFlight map)        │                                   │
          └────────────┬───────────┘                                   │
                       │                                               │
          ┌────────────┴─────────────────────────────┐                │
          │                                          │                │
          ▼                                          ▼                │
   kernel.handle() succeeds                  exception thrown         │
          │                                          │                │
          │ reply KernelResponse              ChildFailed signal       │
          │ tell KernelReady                  sent to KernelPoolActor  │
          └──────────────────────────────────────────┘                │
                       │                             │                │
                       │                             │ reply 503      │
                       │                             │ to caller      │
                       │                             │                │
                       │                             │ respawn new    │
                       │                             │ KernelActor    │
                       │                             └────────────────┤
                       │                                              │
                       └──────────────────────────────────────────────┘
                                   KernelReady → back to idle
```

## KernelActor lifecycle

### Startup (`onPreStart`)

`KernelActor::onPreStart()` invokes the kernel factory, boots the Symfony kernel, and stores a reference to the `services_resetter` service (the Symfony service resetter, which resets stateful services between requests). The `nexus.actor_system` and `nexus.runtime` services are set on the container so injected services can reach the actor system.

### Request handling

On each `KernelDispatch`:

1. `$kernel->handle($request)` runs the full Symfony request/response cycle inside the coroutine.
2. `$kernel->terminate($request, $response)` is called if the kernel implements `TerminableInterface`.
3. `$resetter->reset()` resets all services tagged `kernel.reset` — the same mechanism used by `php-pm` and `frankenphp`.
4. The response is sent to the original caller via `$replyTo->tell(new KernelResponse($response))`.
5. `KernelReady` is told to the parent pool actor so it can dispatch the next pending request or return the kernel to the idle queue.

### Shutdown (`onPostStop`)

`KernelActor::onPostStop()` calls `$kernel->shutdown()` to cleanly tear down the kernel when the actor system stops.

## Backpressure

When all kernels are busy and a new `HandleRequest` arrives:

- If `count($pending) < maxPending`: the request (and its `replyTo` ref) are enqueued in the FIFO pending queue.
- If `count($pending) >= maxPending`: a `KernelResponse(503 Too Many Requests)` is immediately sent back to the Swoole request handler. No waiting, no blocking.

The caller in `NexusRunner` (the Swoole `request` callback) calls `$future->await()`, which suspends the coroutine. When the response arrives (whether real or 503), the coroutine resumes and the Swoole response is flushed.

## Crash recovery

If a `KernelActor` throws an uncaught exception during `handle()`, the Nexus supervisor delivers a `ChildFailed` signal to `KernelPoolActor`. The signal handler:

1. Looks up the failed child's path in `$inFlight` to find the waiting caller.
2. Sends a `KernelResponse(503 Service Unavailable)` to the caller so the HTTP response is flushed immediately.
3. Spawns a new `KernelActor` (with an incremented counter-based name to avoid name collisions).
4. Places the new kernel into the idle queue so the pool is immediately available at its previous capacity.

The failed kernel is never returned to the idle queue. The pool size is maintained automatically.

## Configuration

Configure via `APP_RUNTIME_OPTIONS` (JSON) or Symfony bundle config:

```yaml
# config/packages/nexus.yaml  (bundle-level config, unused by the runtime layer)
nexus:
    name: my-app
    shutdown_timeout: 30
```

```dotenv
# .env.local  (runtime-level config, passed to NexusRuntime constructor)
APP_RUNTIME_OPTIONS={"workers":4,"kernel_pool_size":8,"kernel_pool_max_pending":100}
```

Note: `kernel_pool_size` and `kernel_pool_max_pending` in `APP_RUNTIME_OPTIONS` control how `NexusRunner` constructs the pool. The bundle's `nexus.yaml` keys (`kernel_pool.size`, `kernel_pool.max_pending`) are separate configuration nodes for future use within Symfony's DI layer.

## Tuning guide

### Workers × kernel_pool_size

Total maximum concurrency = `workers × kernel_pool_size`.

| Scenario | Recommendation |
|---|---|
| CPU-bound workloads (heavy computation) | workers = CPU count, kernel_pool_size = 1–2 |
| I/O-bound workloads (DB, Redis, HTTP calls) | workers = CPU count, kernel_pool_size = 8–32 |
| Mixed workloads | workers = CPU count, kernel_pool_size = 4–8 |
| High memory pressure | Reduce kernel_pool_size (each kernel = one full Symfony container in RAM) |

**Rule of thumb for I/O-bound apps:** `kernel_pool_size ≈ average_latency_ms / target_latency_ms`. For example, if a typical request spends 4 ms waiting on I/O and the target is 0.5 ms throughput overhead, 8 kernels allow the worker to stay busy throughout.

### kernel_pool_max_pending

`maxPending` is the depth of the waiting queue. It acts as a safety valve:

- Too low: clients receive 503 spikes during traffic bursts.
- Too high: memory grows unbounded; tail latency increases as requests wait in the queue.

A reasonable starting value is `2 × kernel_pool_size`. For bursty traffic patterns, increase to `5 × kernel_pool_size` and monitor p99 latency — if it climbs above acceptable bounds, the queue is too deep and more workers or kernels are needed.

### Memory

Each kernel holds a full Symfony container in memory. With `kernel_pool_size = 20` and `workers = 4`, the process hosts 80 Symfony containers concurrently. On a typical Symfony app:

- `kernel_pool_size = 8` ≈ 800 MB per worker (rough estimate; profile with your app)
- `kernel_pool_size = 20` ≈ 2 GB per worker

Set `memory_limit` accordingly (e.g. `-d memory_limit=2G`) and use container memory limits in production.

### Latency vs throughput tradeoff

- Fewer kernels → lower memory, lower p50 (less scheduling jitter), higher p99 under load.
- More kernels → higher throughput, higher p50 (more coroutine switching), lower p99 under sustained load.

Monitor both p50 and p99. For latency-sensitive APIs, prefer fewer kernels with more workers. For batch/pipeline APIs, prefer more kernels.
