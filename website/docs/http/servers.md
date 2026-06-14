---
sidebar_position: 10
title: Servers
---

# Servers

Two Swoole adapters serve the same `CompiledApplication`. The choice
between them is about runtime constraints, not features.

|   | Worker mode | Thread mode |
|---|---|---|
| **Package** | `nexus-actors/http-server-swoole` | `nexus-actors/http-server-swoole-threads` |
| **PHP build** | Any | **ZTS required** |
| **Swoole** | 5+ | **6.0+ with `--enable-swoole-thread`** |
| **Concurrency primitive** | Worker processes | Worker threads (one process) |
| **Per-worker isolation** | OS-process isolation | Memory-isolated PHP threads |
| **Cross-worker shared state** | No (use Redis / DB) | Yes (`Swoole\Thread\Map`, `Swoole\Thread\Queue`) |
| **Channel-backed WebSocket actors** | ✗ | ✓ |
| **Lock-free async logging** | ✗ | ✓ via `Thread\Queue` |
| **Hot reload via `maxRequest`** | ✓ | ✓ |
| **Deployment complexity** | Lower (any PHP build) | Higher (ZTS Docker image) |

If you're not sure: **start with worker mode**. Switch to thread mode when
you hit one of: channel-backed WebSocket routes, in-process shared state,
or async logging via `Thread\Queue`.

## Worker Mode

The standard Swoole shape: master + reactors + N worker processes.

```php
use Monadial\Nexus\Http\Server\Swoole\Server\{SwooleWorkerConfig, SwooleWorkerServer};

SwooleWorkerServer::run(
    SwooleWorkerConfig::bind('0.0.0.0', 8080)
        ->workers(8)
        ->reactorThreads(4)
        ->maxRequest(10_000)
        ->shutdownTimeout(Duration::seconds(10)),
    static function (ActorSystem $system): CompiledApplication {
        return HttpApplication::create($system)
            ->get('/health', static fn() => Response::ok())
            ->compile();
    },
);
```

The factory runs once per worker process, on `WorkerStart`. Each worker
gets its own `ActorSystem`, its own DI container, its own cached
compiled application. No shared state.

### When to Use Worker Mode

- You don't have a ZTS PHP build (most stock distros, including PHP-FPM
  images).
- Your handlers don't need cross-worker shared state — every worker is
  identical and idempotent.
- You scale horizontally (multiple containers, each with N workers) more
  than vertically.
- You want OS-level isolation between workers (memory leak in one
  worker can't poison another).

### Worker-Mode Limitations

- **No channel-backed WebSocket routes.** Channel actors need shared
  memory across the pool; workers don't share memory. Use plain `ws()`
  routes with per-connection handlers.
- **No `Thread\Queue` async logging.** Each worker logs independently;
  the file handle is per-process. Use `ConsoleHandler` or
  `FileHandler` (with `flock`) — slower under load.

See the [package reference](../packages/http-server-swoole.md) for the
full config surface.

## Thread Mode

Swoole 6's `SWOOLE_THREAD` runtime: one process, N worker threads sharing
memory via `Swoole\Thread\Map` and `Swoole\Thread\Queue`.

```php
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\{SwooleThreadConfig, SwooleThreadServer};

SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)
        ->threads(8)
        ->enableWebSocket(true)
        ->shutdownTimeout(Duration::seconds(10)),
    static function (ActorSystem $system, WorkerNode $node): CompiledApplication {
        return WsApplication::create($system)
            ->get('/', static fn() => Response::ok())
            ->channel('/ws/chat/{room}', ChatRoomActor::class, key: 'room')
            ->compile();
    },
);
```

The factory receives both an `ActorSystem` and a `WorkerNode`. The node
identifies the thread in the pool — use `$node->workerId()` for logging,
sharding, consistent-hash partitioning.

### When to Use Thread Mode

- You need channel-backed WebSocket routes (chat, presence, live updates
  with broadcast).
- You want lock-free async logging via `Thread\Queue` (see
  [Observability](./observability.md#async-logging)).
- You have hot in-memory state that must be shared across the pool
  (in-process cache, leader election table, …).
- You're running Swoole 6 anyway.

### Thread-Mode Prerequisites

```dockerfile
# Excerpt from docker/Dockerfile
FROM php:8.5-cli AS php-swoole

RUN docker-php-source extract \
    && cd /usr/src/php \
    && ./configure --enable-zts \
    && make && make install

RUN pecl install swoole --enable-swoole-thread
```

Verify ZTS and thread support:

```bash
php -r 'echo PHP_ZTS ? "ZTS\n" : "NTS — thread mode NOT supported\n";'
php --ri swoole | grep -i thread
```

The repo's `docker/Dockerfile` `php-swoole` target ships both.

## Configuration Reference

Both adapters share the same shape; only the concurrency primitive
differs.

### Common

| Setter | Default | Purpose |
|---|---|---|
| `bind(host, port)` | — | Bind address |
| `maxRequest(n)` | unlimited | Recycle worker/thread after N requests |
| `enableWebSocket(bool)` | `false` | Switch to `Swoole\WebSocket\Server` |
| `shutdownTimeout(Duration)` | 10s | Graceful drain budget |
| `installSignalHandlers(bool)` | `true` | Handle `SIGTERM` / `SIGINT` |
| `logger(LoggerInterface)` | none | Runner lifecycle PSR-3 logger |

### Worker-Mode Only

| Setter | Default | Purpose |
|---|---|---|
| `workers(n)` | `1` | Number of worker processes |
| `reactorThreads(n)` | CPU count | Reactor thread pool |
| `maxConn(n)` | Swoole default | Max concurrent connections |
| `dispatchMode(int)` | `2` (fixed by fd) | Swoole dispatch strategy |
| `logFile(path)` | none | Swoole's own server log |

### Thread-Mode Only

| Setter | Default | Purpose |
|---|---|---|
| `threads(n)` | `1` | Number of worker threads |
| `withLogQueue(Queue)` | none | Shared queue for async logging |

## Bootstrap Logger

Runner lifecycle logs fire **before** your per-worker `ActorSystem`
exists, so the runner itself needs a synchronous PSR-3 logger:

```php
$bootstrap = new MyStdErrLogger();   // anything PSR-3 — Monolog, custom, …

SwooleThreadConfig::bind('0.0.0.0', 8080)
    ->logger($bootstrap)
    // …
```

For the application's request logger, build a `NexusLogger` inside the
factory — see [Observability](./observability.md).

## Graceful Shutdown

Both adapters handle `SIGTERM` / `SIGINT` identically:

1. Master stops accepting new connections.
2. Workers/threads drain in-flight requests up to `shutdownTimeout`.
3. Each `ActorSystem::shutdown()` runs with the same budget, delivering
   `PostStop` to every actor.
4. Process exits cleanly.

Set `installSignalHandlers(false)` if you're running under a supervisor
(systemd, s6, …) that owns signal handling.

### Drain Budget

`shutdownTimeout(Duration::seconds(10))` is generous. For Kubernetes
deployments, match it to your pod's `terminationGracePeriodSeconds` minus
a small buffer for the OS-level kill:

```yaml
# kubernetes/deployment.yaml
spec:
  terminationGracePeriodSeconds: 15
```

```php
// server.php
SwooleThreadConfig::bind('0.0.0.0', 8080)
    ->shutdownTimeout(Duration::seconds(12));   // 15s pod budget - 3s safety
```

If draining genuinely takes longer than the budget (long-poll, big file
upload), set the budget to match. There's no penalty for being patient;
the OS will SIGKILL after the pod grace period regardless.

## Deployment Patterns

### Behind a Load Balancer

Run N containers, each with the same worker/thread count. Health-check
`GET /health`. The load balancer evicts unhealthy instances; Kubernetes
restarts them.

```php
$app->get('/health', static function () use ($system) {
    if (!$system->isHealthy()) {
        return Response::serviceUnavailable();
    }

    return JsonResponse::ok(['status' => 'ok']);
});
```

### Hot Reload

`maxRequest(n)` recycles a worker (process or thread) after N requests,
re-running the factory. Useful for:

- Picking up new code without a process restart (combined with OPcache
  invalidation).
- Bounding memory growth — workers that leak get cycled out.

For zero-downtime full code reloads, use a process manager (e.g.
`SwooleReloader`) or run a blue/green container deployment.

### Sticky Sessions

WebSocket connections are inherently sticky — once the upgrade succeeds,
the connection stays on whichever worker/thread accepted it. For HTTP,
worker-mode Swoole defaults to round-robin dispatch (`dispatchMode(2)`,
fixed by fd) — same client tends to hit the same worker for the
connection's lifetime, but not across reconnects.

If your handlers depend on cross-request stickiness for the same client
(typically a smell), put a session store in front of the actor layer
rather than relying on worker affinity.

## Composition

```
                ┌─ CompiledApplication
                │  (immutable, runtime-agnostic)
                │
                ├──→ SwooleWorkerServer::run
                │      master + N worker processes
                │      each runs an ActorSystem
                │
                └──→ SwooleThreadServer::run
                       master + N worker threads (one process)
                       shared Swoole\Thread\Map / Thread\Queue
                       each thread runs an ActorSystem + WorkerNode
```

Next: [Observability](./observability.md) to wire logging across both
modes, or back to [Overview](./overview.md) for the high-level picture.
