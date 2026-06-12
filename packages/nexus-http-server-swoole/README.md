# nexus-http-server-swoole

Swoole worker-mode HTTP/WebSocket server for `nexus-http`. Implements `HttpServerAdapter` plus a static `SwooleWorkerHttpServer::run()` entry point using `ext-swoole`'s native multi-process worker pool.

> See `docs/superpowers/specs/2026-06-11-nexus-http-server-swoole-design.md` for the design.

## Install

```bash
composer require nexus-actors/http-server-swoole
```

## HTTP quickstart

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerHttpServer;

SwooleWorkerHttpServer::run(
    SwooleWorkerConfig::bind('0.0.0.0', 8080)->workers(4),
    static function (ActorSystem $system): CompiledHttpApp {
        $http = HttpApp::create($system);
        $http->get('/hello', static fn() => Response::ok());
        return $http->compile();
    },
);
```

Each worker process gets its own `ActorSystem` with `SwooleRuntime`. Workers are isolated. `WorkerLocal` and `PerRequest` actor modes work; `PoolSingleton` is **not available** in worker mode (use the threads package for that).

## WebSocket (opt-in)

Disabled by default. Call `enableWebSocket(true)` on the config.

### Handler mode — one handler per connection

```php
use Monadial\Nexus\Http\Server\Swoole\App\SwooleHttpApp;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketHandler;

final class EchoHandler implements WebSocketHandler
{
    public function __construct(private readonly WebSocketContext $ctx) {}
    public function onMessage(WebSocketFrame $frame): void { $this->ctx->send('echo:' . $frame->text); }
    public function onClose(int $code): void {}
}

SwooleWorkerHttpServer::run(
    SwooleWorkerConfig::bind('0.0.0.0', 8080)
        ->workers(4)
        ->enableWebSocket(true),
    static function (ActorSystem $system) {
        $http = HttpApp::create($system);
        return SwooleHttpApp::wrap($http, $system)
            ->webSocket('/ws/echo', static fn(WebSocketContext $ctx) => new EchoHandler($ctx))
            ->compile();
    },
);
```

### Channel-actor mode — one actor per channel key

All connections to `/ws/channel/lobby` share the lobby actor; `/ws/channel/room42` gets its own. Natural pub/sub.

```php
use Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\ChannelConnectionClosed;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\ChannelConnectionOpened;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\ChannelMessageReceived;

SwooleHttpApp::wrap($http, $system)
    ->webSocketChannel(
        path: '/ws/channel/{channelId}',
        props: $channelProps,        // Props for the per-channel actor
        keyFrom: 'channelId',
    )
    ->compile();
```

The channel actor receives `ChannelConnectionOpened`, `ChannelMessageReceived`, `ChannelConnectionClosed`. Broadcast by iterating subscribed `WebSocketContext`s and calling `$ctx->send($text)`.

**Worker-mode limitation:** Channel actors are `WorkerLocal` — each worker has its own. Connections to the same channel landing on different workers see different actors. Use the threads package for cross-worker sharing.

## Configuration

```php
SwooleWorkerConfig::bind('0.0.0.0', 8080)
    ->workers(8)                    // process count (default 1)
    ->reactorThreads(2)
    ->maxRequest(10_000)            // graceful worker recycle
    ->maxConn(100_000)
    ->dispatchMode(2)
    ->shutdownTimeout(Duration::seconds(10))
    ->enableWebSocket(true)         // opt-in (default false)
    ->installSignalHandlers(true)   // SIGTERM/SIGINT graceful shutdown
    ->logger($psrLogger)
    ->logFile('/var/log/app/swoole.log');
```

## Architecture

- One `ActorSystem` + one `CompiledHttpApp` per worker process, built at `WorkerStart`. Hot path reflection-free.
- Bridge classes: `SwooleRequestTranslator`, `SwooleResponseWriter`, `SwooleStreamingDetector`. Streaming bodies (`IteratorStream`) write per chunk.
- Restart-loop protection: 3 factory failures in 5s → master shutdown.
- No `nexus-worker-pool` dependency. For pool-singleton actor support across threads, use `nexus-actors/http-server-swoole-threads`.

## Status

Worker-mode HTTP + WebSocket (handler + channel) — stable.
