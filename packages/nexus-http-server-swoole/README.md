# nexus-http-server-swoole

Worker-mode (Swoole process workers) HTTP+WebSocket runner. Implements nexus-http's `HttpServerAdapter` and ships `SwooleWorkerServer::run()` as the static entrypoint. The HTTP/WebSocket DSL lives in [nexus-http-ws](../nexus-http-ws); this package is just the Swoole glue.

## Install

```bash
composer require nexus-actors/http-server-swoole
```

## Quickstart

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\WsApplication;

SwooleWorkerServer::run(
    SwooleWorkerConfig::bind('0.0.0.0', 8080)
        ->workers(4)
        ->enableWebSocket(true),
    static function (ActorSystem $system): CompiledApplication {
        $app = WsApplication::create($system);
        $app->get('/health', static fn() => Response::ok('ok'));
        $app->ws('/ws/echo', EchoHandler::class);
        return $app->compile();
    },
);
```

Each worker process gets its own `ActorSystem` + `CompiledApplication`. WebSocket events (`Open/Message/Close`) are only wired when the compiled app reports `hasWebSocketRoutes() === true` AND `enableWebSocket(true)` is set on the config.

Channel actors are worker-local — each worker has its own. Connections to the same channel landing on different workers see different actors.

## Configuration

```php
SwooleWorkerConfig::bind('0.0.0.0', 8080)
    ->workers(8)
    ->reactorThreads(2)
    ->maxRequest(10_000)
    ->maxConn(100_000)
    ->dispatchMode(2)
    ->shutdownTimeout(Duration::seconds(10))
    ->enableWebSocket(true)
    ->installSignalHandlers(true)
    ->logger($psrLogger);
```

## Architecture

- One `CompiledApplication` per worker, built at `WorkerStart` via the user factory.
- Restart-loop protection: 3 factory failures in 5s → `Server::shutdown()`.
- See `nexus-http-ws` for the DSL, handler/channel actor bases, and WS routing.

## Status

Stable.
