# nexus-http-server-swoole-threads

Swoole thread-mode HTTP/WebSocket server. Uses Swoole 6's native `SWOOLE_THREAD` server mode — one shared `Swoole\Http\Server` (or `WebSocket\Server`) in the main thread, N internal worker threads with shared memory. Each thread gets its own `ActorSystem` + `WorkerNode`, enabling true `PoolSingleton` actor routing across all HTTP-serving threads.

> See `docs/superpowers/specs/2026-06-11-nexus-http-server-swoole-design.md` for the design.

## Install

```bash
composer require nexus-actors/http-server-swoole-threads
```

Requires Swoole ≥ 6.0 with `--enable-swoole-thread` (ZTS PHP).

## HTTP quickstart

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadConfig;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadHttpServer;
use Monadial\Nexus\WorkerPool\WorkerNode;

SwooleThreadHttpServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)->threads(8),
    static function (ActorSystem $system, WorkerNode $node): CompiledHttpApp {
        $http = HttpApp::create($system);
        $http->get('/hello', static fn() => Response::ok());
        return $http->compile();
    },
);
```

The factory closure receives a `WorkerNode` — the per-thread coordinator that routes pool-singleton actors via the consistent hash ring. Use it (see below) or ignore it for stateless apps.

## Pool-singleton actors

Across the N HTTP-serving threads, you can declare an actor as `PoolSingleton` and the framework places it on whichever thread the hash ring assigns. All other threads' handlers reach it through a `WorkerActorRef` over `ThreadQueueTransport`.

```php
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Threads\Actor\WorkerNodePoolSingletonSpawner;

SwooleThreadHttpServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)->threads(8),
    static function (ActorSystem $system, WorkerNode $node): CompiledHttpApp {
        $http = HttpApp::create($system);
        $http->withPoolSingletonSpawner(new WorkerNodePoolSingletonSpawner($node));
        $http->actor('store', $storeProps)->poolSingleton();
        $http->get(
            '/store/{id}',
            static fn(ServerRequestInterface $r, #[FromActor('store')] ActorRef $store) =>
                JsonResponse::ok($store->ask(new GetItem($r->getAttribute('id')))->await()),
        );
        return SwooleHttpApp::wrap($http, $system)->compile();
    },
);
```

## WebSocket (opt-in)

Disabled by default. Call `enableWebSocket(true)`.

```php
SwooleThreadConfig::bind('0.0.0.0', 8080)
    ->threads(8)
    ->enableWebSocket(true);
```

Handler mode works the same way as in worker mode — see the worker-mode package's README for the API.

**v1 limitation — channel-actor cross-thread broadcast:** The cross-thread `WebSocketFramePush` plumbing + per-thread router actors are wired (see `ThreadAwareWebSocketContext` and `WebSocketFramePush`), but channel actors themselves stay thread-local in v1. The `ChannelConnectionOpened` envelope's `WebSocketContext` + Swoole `Request` are not serialization-safe across `Thread\Queue`, so a v2 design pass is needed to make a channel actor span threads. Handler-mode WebSocket works fully in thread mode.

## Configuration

```php
SwooleThreadConfig::bind('0.0.0.0', 8080)
    ->threads(8)
    ->maxRequest(10_000)
    ->shutdownTimeout(Duration::seconds(10))
    ->enableWebSocket(true)
    ->installSignalHandlers(true)
    ->logger($psrLogger);
```

## Architecture

- Swoole's `SWOOLE_THREAD` server mode: one `Server` in main thread, N internal threads.
- Per-thread state: `ActorSystem`, `WorkerNode`, optional `ConnectionTable` + per-thread router actor (when WebSocket is enabled).
- Cross-thread sharing: `Thread\Map` (directory) + `Thread\Queue` array (transport) allocated in `init_arguments` callback, retrieved per worker via `Thread::getArguments()`.
- The `WorkerNodePoolSingletonSpawner` adapter bridges nexus-http's `PoolSingletonSpawner` interface to `WorkerNode::spawn`.

## Status

Thread-mode HTTP + handler-mode WebSocket: stable.
Channel-actor cross-thread broadcast: v2 (handler mode is the recommended pattern in thread mode until then).
