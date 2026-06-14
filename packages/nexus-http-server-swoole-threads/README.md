# nexus-http-server-swoole-threads

Thread-mode (Swoole 6 SWOOLE_THREAD) HTTP+WebSocket runner. Same DSL as the worker package — see [nexus-http-ws](../nexus-http-ws). Uses Swoole's native thread mode for shared-memory pool-singleton actors.

Requires Swoole ≥ 6.0 with `--enable-swoole-thread` (ZTS PHP 8.5+).

## Install

```bash
composer require nexus-actors/http-server-swoole-threads
```

## HTTP quickstart

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadConfig;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\WsApplication;
use Monadial\Nexus\WorkerPool\WorkerNode;

SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)->threads(8),
    static function (ActorSystem $system, WorkerNode $node): CompiledApplication {
        $app = WsApplication::create($system);
        $app->get('/api/users', UsersController::class);
        return $app->compile();
    },
);
```

## Pool-singleton actors

Across N HTTP-serving threads, declare an actor as `PoolSingleton` and the framework places it on whichever thread the hash ring assigns. All other threads' handlers reach it through a `WorkerActorRef`.

```php
use Monadial\Nexus\Http\Server\Swoole\Threads\Actor\WorkerNodePoolSingletonSpawner;

$app->withPoolSingletonSpawner(new WorkerNodePoolSingletonSpawner($node));
$app->actor('store', $storeProps)->poolSingleton();
```

## WebSocket — handler mode only in v1

Enable via `->enableWebSocket(true)` on the config. **Channel-mode routes (`channel(...)`) are rejected at boot**: the channel-actor message payload is not serialization-safe across `Thread\Queue`, so accepting them under thread-distributed load would violate the per-channel-key actor guarantee. Use handler-mode WebSocket here, or switch to `nexus-actors/http-server-swoole` (worker mode) for channel actors.

## Configuration

```php
SwooleThreadConfig::bind('0.0.0.0', 8080)
    ->threads(8)
    ->maxRequest(10_000)
    ->shutdownTimeout(Duration::seconds(10))
    ->enableWebSocket(true)
    ->logger($psrLogger);
```

## Status

Thread-mode HTTP + handler-mode WebSocket — stable. Channel-mode WebSocket — rejected at boot in v1 (see above).
