# Nexus Runtime

## Overview

Symfony's [Runtime component](https://symfony.com/doc/current/components/runtime.html) separates application bootstrapping from execution. The `RuntimeInterface` contract defines how a kernel is resolved and how the application is run. Standard Symfony ships with a FPM-oriented runtime that handles one request per process invocation. `NexusRuntime` replaces that with a long-running Swoole HTTP server operating in thread mode (`SWOOLE_THREAD`).

When `public/index.php` loads via `autoload_runtime.php`, the Runtime component instantiates the class named in `APP_RUNTIME`, calls `getResolver()` to capture the kernel factory closure, then calls `getRunner()` to obtain a `RunnerInterface` whose `run()` method starts the server and blocks until shutdown.

The result is a single PHP process that:

- Listens on a configurable host and port.
- Spawns `workers` Swoole worker threads, each isolated from the others.
- Runs `kernel_pool_size` Symfony kernels inside each worker, each in its own coroutine.
- Dispatches every incoming HTTP request through the actor system's `ask()` / `await()` cycle.

---

## Startup sequence

The following diagram traces the full path from `php public/index.php` to the first served response.

```
php public/index.php
      │
      │  autoload_runtime.php detects APP_RUNTIME
      ▼
NexusRuntime::getResolver()
  ├── captures kernel factory closure (the return value of index.php)
  └── returns ClosureResolver — Symfony calls the closure to get the kernel instance
                                used only for type-checking; the factory is re-invoked
                                per worker below
      │
      ▼
NexusRuntime::getRunner()
  ├── merges DEFAULT_OPTIONS with parsed APP_RUNTIME_OPTIONS
  └── returns NexusRunner($kernelFactory, $options)
      │
      ▼
NexusRunner::run()
  ├── makes SCRIPT_FILENAME absolute
  │     Worker threads re-execute the entry script. A relative path breaks when
  │     the CWD differs between threads, so the path is made absolute here.
  ├── new Swoole\Http\Server($host, $port, SWOOLE_THREAD)
  ├── server->set([worker_num, enable_coroutine, hook_flags => SWOOLE_HOOK_ALL])
  ├── server->on('workerStart', ...)
  ├── server->on('request', ...)
  └── server->start()  ← blocks; the process stays alive here
      │
      ├── workerStart (fires once per thread, in a Swoole coroutine) ─────────────────┐
      │     │                                                                          │
      │     ▼                                                                          │
      │   Coroutine::create()                                                          │
      │     │                                                                          │
      │     ├── ($kernelFactory)($env)  →  management HttpKernelInterface              │
      │     │     This is the "management kernel" — it wires the DI container for      │
      │     │     this worker but is not used to serve HTTP requests directly.         │
      │     │                                                                          │
      │     ├── $kernel->boot()                                                        │
      │     │                                                                          │
      │     ├── $runtime = new SwooleEmbeddedRuntime()                                 │
      │     ├── $system  = ActorSystem::create("nexus-worker-{$workerId}", $runtime)   │
      │     │                                                                          │
      │     ├── callWorkerStartBootstrappers($container, $workerId)                    │
      │     │     Calls onWorkerStart() on every service tagged nexus.worker_start.    │
      │     │     Use this hook to initialize connection pools and other               │
      │     │     worker-local resources.                                              │
      │     │                                                                          │
      │     ├── $container->set('nexus.actor_system', $system)                         │
      │     ├── $container->set('nexus.runtime',      $runtime)                        │
      │     │     The synthetic services are now available for injection.              │
      │     │                                                                          │
      │     ├── bootIsolatedActors($container, $system)                                │
      │     │     Reads the nexus.isolated_actors parameter compiled by               │
      │     │     ActorRegistrationPass. For each #[Actor(Isolated, 'name')] class:    │
      │     │       → ActorPropsFactory::create() → Props::fromContainer(...)         │
      │     │       → $system->spawn($props, $name)                                   │
      │     │       → $container->set("nexus.actor_ref.{$name}", $ref)               │
      │     │                                                                          │
      │     ├── wireShutdown($container, $system)                                      │
      │     │     Registers a SIGTERM handler that calls GracefulShutdownHandler.      │
      │     │                                                                          │
      │     ├── $system->spawn(KernelPoolActor::props(...), 'kernel-pool')             │
      │     │     Spawns the pool coordinator. KernelPoolActor::init() immediately     │
      │     │     spawns kernel_pool_size KernelActor children.                        │
      │     │     Each KernelActor::onPreStart() invokes the kernel factory again,     │
      │     │     boots a fresh Symfony kernel, and wires nexus.actor_system /         │
      │     │     nexus.runtime into that kernel's container.                          │
      │     │                                                                          │
      │     └── $runtime->run()  ← starts the embedded Swoole event loop              │
      │                            coroutine yields here; other coroutines run         │
      │                                                                                │
      └── request (fires per HTTP request, inside a Swoole coroutine) ────────────────┘
            │
            ├── $workerId = $server->getWorkerId()
            ├── $poolRef  = $poolRefs[$workerId]
            │
            ├── if $poolRef === null:  (worker still initializing)
            │     $res->status(503); $res->end('Worker initializing'); return
            │
            ├── $symfonyRequest = SwooleHttpBridge::toSymfonyRequest($req)
            │
            ├── $future = $poolRef->ask(new HandleRequest($symfonyRequest), Duration::seconds(30))
            │     KernelPoolActor receives HandleRequest, dequeues an idle KernelActor,
            │     dispatches KernelDispatch to it, and suspends the ask future.
            │
            ├── $kernelResponse = $future->await()
            │     Coroutine suspends here. While waiting, other request coroutines run.
            │     KernelActor calls $kernel->handle(), terminate(), reset(), then:
            │       → $replyTo->tell(new KernelResponse($response))
            │       → $ctx->parent()->tell(new KernelReady($ctx->self()))
            │     The future resolves; this coroutine resumes.
            │
            └── SwooleHttpBridge::sendSymfonyResponse($kernelResponse->response, $res)
```

---

## APP_RUNTIME_OPTIONS reference

`APP_RUNTIME_OPTIONS` is a JSON object set as an environment variable. All keys are optional; omitted keys fall back to the defaults shown below.

| Option | Type | Default | Description |
|---|---|---|---|
| `host` | string | `0.0.0.0` | Bind address for the Swoole HTTP server. Use `127.0.0.1` to restrict to loopback when a reverse proxy handles public traffic. |
| `port` | int | `8080` | TCP port the server listens on. |
| `workers` | int | `4` | Number of Swoole worker threads. Set to the number of available CPU cores for CPU-bound workloads; increase for I/O-bound workloads only when the thread overhead is acceptable. |
| `kernel_pool_size` | int | `8` | Number of Symfony kernel instances spawned per worker. Each kernel handles exactly one request at a time but many kernels run concurrently within the worker's coroutine scheduler. |
| `kernel_pool_max_pending` | int | `100` | Maximum number of requests that may queue while all kernels are busy. Requests arriving when the queue is full receive an immediate HTTP 503. |

Setting the options:

```bash
APP_RUNTIME_OPTIONS='{"workers":8,"kernel_pool_size":16,"kernel_pool_max_pending":200}'
```

Or in a Docker Compose environment section:

```yaml
environment:
    APP_RUNTIME: Monadial\Nexus\Symfony\Runtime\NexusRuntime
    APP_RUNTIME_OPTIONS: '{"workers":8,"kernel_pool_size":16,"kernel_pool_max_pending":200}'
```

> **Note:** `kernel_pool_size` in `APP_RUNTIME_OPTIONS` controls how `NexusRunner` creates the pool. The `nexus.kernel_pool.size` key in `nexus.yaml` is a separate DI-layer configuration node for future use and does not affect the runtime pool size.

---

## SwooleEmbeddedRuntime

`SwooleEmbeddedRuntime` is a Nexus `Runtime` implementation designed for use inside a Swoole coroutine that is already scheduled by an external event loop — in this case, the `Swoole\Http\Server` started by `NexusRunner`.

The standalone `SwooleRuntime` owns its own event loop and calls `Swoole\Coroutine\run()` to boot it. Using it inside an already-running server would create a nested event loop, which Swoole does not allow. `SwooleEmbeddedRuntime` avoids this by omitting the outer event loop bootstrap and instead registering actor coroutines directly into the ambient Swoole scheduler.

Each worker creates exactly one `SwooleEmbeddedRuntime` instance. That runtime powers the `ActorSystem` for that worker — all actor message loops, timers, and `ask()` futures run as coroutines in the same Swoole thread.

---

## SWOOLE_HOOK_ALL and coroutine I/O safety

`NexusRunner` sets `hook_flags => SWOOLE_HOOK_ALL` on the server configuration. This activates Swoole's runtime hook, which at the C extension level replaces blocking PHP I/O functions with coroutine-aware alternatives.

The hook patches, among others:

- PDO, MySQLi (database connections)
- cURL (HTTP clients, Guzzle, Symfony HttpClient)
- `stream_*`, `fread`, `fwrite`, `file_get_contents` (file and socket I/O)
- `sleep`, `usleep` (blocking delays)
- `proc_open`, `shell_exec`

Code written with standard PHP I/O APIs continues to work without modification. What was a blocking call becomes a coroutine yield — the current coroutine suspends and another runs until the I/O completes. From the perspective of the code inside the kernel, the call looks synchronous.

> **Caution:** The hook patches most but not all blocking APIs. PHP's native `ext-ldap`, some obscure stream wrappers, and CPU-bound loops are not patched. Long-running CPU computation inside a handler will block the worker thread for its duration.

---

## Memory considerations

The runtime boots Symfony DI containers in quantity. The total number of live containers across the entire process is:

```
containers = workers × (kernel_pool_size + 1)
```

The `+ 1` is the management kernel booted in `workerStart` for isolated actor wiring and bootstrapper calls.

For 8 workers and a pool size of 8:

```
8 × (8 + 1) = 72 containers
```

A typical Symfony application container occupies 10–20 MB in memory. At 15 MB average:

```
72 × 15 MB = 1 080 MB
```

The process memory limit should be set generously above this figure to leave headroom for request payloads, actor mailboxes, and operating system overhead:

```
memory_limit >= workers × (kernel_pool_size + 1) × per_kernel_MB × 1.5
```

Start with `-d memory_limit=2G` for an 8 × 8 configuration and profile the actual footprint with `Swoole\Coroutine\System::getMemoryUsage()` or container-level metrics before tuning further.

> **Tip:** If memory is constrained, reduce `kernel_pool_size` first. Four kernels per worker with more workers often yields better throughput per megabyte than eight kernels per worker with fewer workers, because context switching between coroutines is cheaper than booting additional containers.

---

## Graceful shutdown

When the process receives `SIGTERM`, `NexusRunner::wireShutdown()` delivers the signal to `GracefulShutdownHandler::shutdown()`, which calls `ActorSystem::shutdown(timeout)`.

The shutdown sequence:

1. The actor system broadcasts `PoisonPill` to all top-level actors.
2. Each `KernelActor` finishes its current request, calls `$kernel->terminate()`, `$resetter->reset()`, and then `$kernel->shutdown()` in `onPostStop()`.
3. `KernelPoolActor` stops after all children stop.
4. Isolated actors receive `PostStop` and run their teardown handlers.
5. `ActorSystem::shutdown()` returns once all actors have stopped or the timeout elapses.

The shutdown timeout is configured in `nexus.yaml`:

```yaml
nexus:
    shutdown_timeout: 30  # seconds
```

For Kubernetes deployments, align `terminationGracePeriodSeconds` with this value (add a small buffer for process startup overhead):

```yaml
spec:
    terminationGracePeriodSeconds: 35
    containers:
        - name: app
          lifecycle:
              preStop:
                  exec:
                      command: ["/bin/sh", "-c", "sleep 2"]
```

The `preStop` sleep gives the load balancer time to remove the pod from rotation before SIGTERM arrives, avoiding in-flight requests being dropped.

> **Caution:** If actors do not finish within `shutdown_timeout`, the actor system forces a stop. Requests that were in the pending queue at shutdown time receive a 503 rather than waiting indefinitely.
