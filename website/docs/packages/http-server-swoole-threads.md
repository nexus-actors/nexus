---
sidebar_position: 9
title: nexus-http-server-swoole-threads
related:
  - packages/http-server-swoole
  - packages/http-ws
  - packages/logger
  - scaling/overview
---

# nexus-http-server-swoole-threads

Thread-mode HTTP and WebSocket server built on Swoole 6's `SWOOLE_THREAD` runtime, enabling cross-thread actor pools, channel-backed WebSocket broadcasts, and lock-free async logging.

## What's in this package

- `SwooleThreadServer` — starts N worker threads, each with its own `ActorSystem` and `WorkerNode`
- `SwooleThreadConfig` — immutable builder: `bind`, `threads`, `enableWebSocket`, `shutdownTimeout`, `withLogQueue`

## Requirements

- PHP 8.5+ compiled with ZTS (Zend Thread Safety)
- Swoole 6.2.1+ compiled with `--enable-swoole-thread`

## Install

```bash
composer require nexus-actors/http-server-swoole-threads
```

## Quick example

<!-- verify:skip: requires a running Swoole thread server -->
```php title="src/Server/boot.php" verify:skip
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadConfig;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\WsApplication;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\WorkerNode;

SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)
        ->threads(8)
        ->enableWebSocket(true)
        ->shutdownTimeout(Duration::seconds(5)),
    static function (ActorSystem $system, WorkerNode $node): CompiledApplication {
        return WsApplication::create($system)
            ->get('/', static fn() => JsonResponse::ok(['tid' => $node->workerId()]))
            ->compile();
    },
);
```

The factory closure runs once per thread and receives a fresh `ActorSystem` and a `WorkerNode` identifying the thread within the pool.

Thread mode is the only Swoole adapter that supports `$app->channel(...)` routes, because channel actor state must outlive a single Swoole worker process. Use `nexus-http-server-swoole` (worker mode) for simpler deployments that do not need cross-thread shared state.

## See also

- [nexus-http-server-swoole](./http-server-swoole.md) — worker-mode runner
- [nexus-http-ws](./http-ws.md) — WebSocket DSL including `channel()` routes
- [nexus-logger](./logger.md) — async `ThreadQueueHandler` for lock-free logging
