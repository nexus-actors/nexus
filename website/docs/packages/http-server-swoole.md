---
sidebar_position: 8
title: nexus-http-server-swoole
related:
  - packages/http-server-swoole-threads
  - packages/http-ws
  - packages/http
  - runtimes/swoole
---

# nexus-http-server-swoole

Swoole worker-mode adapter that wires a `CompiledApplication` to `Swoole\Http\Server` (or `Swoole\WebSocket\Server`).

## What's in this package

- `SwooleWorkerServer` — starts N worker processes; blocks until `SIGTERM`/`SIGINT`
- `SwooleWorkerConfig` — immutable builder: `bind`, `workers`, `reactorThreads`, `maxRequest`, `enableWebSocket`, `shutdownTimeout`

## Install

```bash
composer require nexus-actors/http-server-swoole
```

## Quick example

<!-- verify:skip: requires a running Swoole server -->
```php title="src/Server/boot.php" verify:skip
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Monadial\Nexus\Runtime\Duration;

SwooleWorkerServer::run(
    SwooleWorkerConfig::bind('0.0.0.0', 8080)
        ->workers(4)
        ->reactorThreads(2)
        ->shutdownTimeout(Duration::seconds(10)),
    static function (ActorSystem $system): CompiledApplication {
        return HttpApplication::create($system)
            ->get('/', static fn() => JsonResponse::ok(['ok' => true]))
            ->compile();
    },
);
```

The factory runs once per worker process on `WorkerStart` and receives a fresh `ActorSystem`. On `SIGTERM`/`SIGINT` the runner drains in-flight requests, shuts down each actor system, and exits.

Channel-backed WebSocket routes (`$app->channel(...)`) are not supported in worker mode because actor state does not survive past a single worker process. Use `nexus-http-server-swoole-threads` for cross-worker shared state.

## See also

- [nexus-http-server-swoole-threads](./http-server-swoole-threads.md) — thread-mode runner with shared state
- [nexus-http-ws](./http-ws.md) — WebSocket DSL
- [nexus-http](./http.md) — HTTP primitives and middleware
