---
sidebar_position: 10
title: Servers
related:
  - http/overview
  - http/actors-in-http
  - http/websockets
  - packages/http-server-swoole-threads
---

# Servers

Two Swoole adapters serve the same `CompiledApplication`. The choice between them is about runtime constraints, not features.

|   | Worker mode | Thread mode |
|---|---|---|
| **Package** | `nexus-actors/http-server-swoole` | `nexus-actors/http-server-swoole-threads` |
| **PHP build** | Any | ZTS required |
| **Swoole** | 5+ | 6.0+ with `--enable-swoole-thread` |
| **Concurrency primitive** | Worker processes | Worker threads (one process) |
| **Cross-worker shared state** | No (use Redis / DB) | Yes (`Swoole\Thread\Map`, `Swoole\Thread\Queue`) |
| **Channel-backed WebSocket actors** | No | Yes |
| **Lock-free async logging** | No | Yes via `Thread\Queue` |
| **Deployment complexity** | Lower (any PHP build) | Higher (ZTS Docker image) |

Start with worker mode. Switch to thread mode when you need channel-backed WebSocket routes, in-process shared state, or async logging via `Thread\Queue`.

## Worker mode

The standard Swoole shape: master + reactors + N worker processes.

```php title="server.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Server\{SwooleWorkerConfig, SwooleWorkerServer};
use Monadial\Nexus\Http\Ws\{CompiledApplication, HttpApplication};
use Monadial\Nexus\Runtime\Duration;

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

The factory runs once per worker process, on `WorkerStart`. Each worker gets its own `ActorSystem`, its own DI container, and its own cached compiled application. No shared state.

### When to use worker mode

- You don't have a ZTS PHP build.
- Your handlers don't need cross-worker shared state.
- You scale horizontally (multiple containers, each with N workers) more than vertically.
- You want OS-level isolation between workers.

### Worker-mode limitations

- **No channel-backed WebSocket routes.** Channel actors need shared memory across the pool; workers don't share memory. Use plain `ws()` routes with per-connection handlers.
- **No `Thread\Queue` async logging.** Each worker logs independently. Use `ConsoleHandler` or `FileHandler` with `flock`.

## Thread mode

Swoole 6's `SWOOLE_THREAD` runtime: one process, N worker threads sharing memory via `Swoole\Thread\Map` and `Swoole\Thread\Queue`.

```php title="server.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\{SwooleThreadConfig, SwooleThreadServer};
use Monadial\Nexus\Http\Ws\{CompiledApplication, WsApplication};
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\WorkerNode;

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

The factory receives both an `ActorSystem` and a `WorkerNode`. Use `$node->workerId()` for logging, sharding, and consistent-hash partitioning.

### When to use thread mode

- You need channel-backed WebSocket routes (chat, presence, live updates with broadcast).
- You want lock-free async logging via `Thread\Queue`.
- You have hot in-memory state that must be shared across the pool.
- You are running Swoole 6 anyway.

### Thread-mode prerequisites

```dockerfile
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

## Configuration reference

Both adapters share the same shape; only the concurrency primitive differs.

### Common

| Setter | Default | Purpose |
|---|---|---|
| `bind(host, port)` | — | Bind address |
| `maxRequest(n)` | unlimited | Recycle worker/thread after N requests |
| `enableWebSocket(bool)` | `false` | Switch to `Swoole\WebSocket\Server` |
| `shutdownTimeout(Duration)` | 10s | Graceful drain budget |
| `installSignalHandlers(bool)` | `true` | Handle `SIGTERM` / `SIGINT` |
| `logger(LoggerInterface)` | none | Runner lifecycle PSR-3 logger |

### Worker-mode only

| Setter | Default | Purpose |
|---|---|---|
| `workers(n)` | `1` | Number of worker processes |
| `reactorThreads(n)` | CPU count | Reactor thread pool |
| `maxConn(n)` | Swoole default | Max concurrent connections |
| `dispatchMode(int)` | `2` (fixed by fd) | Swoole dispatch strategy |
| `logFile(path)` | none | Swoole's own server log |

### Thread-mode only

| Setter | Default | Purpose |
|---|---|---|
| `threads(n)` | `1` | Number of worker threads |
| `withLogQueue(Queue)` | none | Shared queue for async logging |

## Graceful shutdown

Both adapters handle `SIGTERM` / `SIGINT` identically:

1. Master stops accepting new connections.
2. Workers/threads drain in-flight requests up to `shutdownTimeout`.
3. Each `ActorSystem::shutdown()` runs with the same budget, delivering `PostStop` to every actor.
4. Process exits cleanly.

Set `installSignalHandlers(false)` if you are running under a supervisor (systemd, s6) that owns signal handling.

### Drain budget for Kubernetes

Match `shutdownTimeout` to your pod's `terminationGracePeriodSeconds` minus a small buffer:

```yaml
# kubernetes/deployment.yaml
spec:
  terminationGracePeriodSeconds: 15
```

```php title="server.php"
SwooleThreadConfig::bind('0.0.0.0', 8080)
    ->shutdownTimeout(Duration::seconds(12));   // 15s pod budget - 3s safety
```

## Deployment patterns

### Behind a load balancer

Run N containers, each with the same worker or thread count. Health-check `GET /health`. The load balancer evicts unhealthy instances; Kubernetes restarts them.

```php title="server.php"
$app->get('/health', static function () use ($system) {
    if (!$system->isHealthy()) {
        return Response::serviceUnavailable();
    }

    return JsonResponse::ok(['status' => 'ok']);
});
```

### Hot reload

`maxRequest(n)` recycles a worker after N requests, re-running the factory. Useful for bounding memory growth and picking up new code combined with OPcache invalidation.

For zero-downtime full code reloads, use a process manager or a blue/green container deployment.

## See also

- [Overview](./overview.md) — the high-level stack picture.
- [WebSockets](./websockets.md) — `channel()` routes and thread-mode requirements.
- [Operations: Observability](../operations/observability.md) — async logging via `Thread\Queue`.
