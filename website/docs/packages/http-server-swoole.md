---
sidebar_position: 8
title: nexus-http-server-swoole
---

# nexus-http-server-swoole

Swoole worker-mode adapter for nexus-http. Slim glue between Swoole's
`Swoole\Http\Server` (with optional `Swoole\WebSocket\Server` semantics)
and a `CompiledApplication`. The per-worker `ActorSystem` is created by
your factory; the runner only wires it to Swoole's event loop.

**Composer:** `nexus-actors/http-server-swoole`

**Namespace:** `Monadial\Nexus\Http\Server\Swoole\`

## Quick Start

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Server\Swoole\Server\{SwooleWorkerConfig, SwooleWorkerServer};
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

The runner blocks until `SIGTERM` / `SIGINT`, then drains the workers,
shuts down each `ActorSystem`, and exits.

## Configuration

`SwooleWorkerConfig` is an immutable builder. Start with `bind()` and chain:

```php
SwooleWorkerConfig::bind('0.0.0.0', 8080)
    ->workers(8)                                // worker process count (default 1)
    ->reactorThreads(4)                         // reactor thread pool (default = CPU count)
    ->maxRequest(10_000)                        // recycle worker after N requests
    ->maxConn(20_000)                           // max concurrent connections
    ->dispatchMode(2)                           // Swoole dispatch mode (default 2 = fixed)
    ->enableWebSocket(true)                     // upgrade to Swoole WebSocket server
    ->shutdownTimeout(Duration::seconds(10))    // graceful drain budget
    ->installSignalHandlers(true)               // handle SIGTERM/SIGINT
    ->logger($bootstrapLogger)                  // PSR-3 for runner lifecycle logs
    ->logFile('/var/log/swoole.log');           // Swoole's own server log
```

All setters return a new instance (immutable). Reasonable defaults are
applied for anything you don't set.

### Workers, Reactors, Dispatch

Swoole worker mode forks N worker processes. Reactor threads share the
accept loop and dispatch incoming requests to workers.

- `workers(n)` — number of worker processes. Each gets its own
  `ActorSystem`. CPU cores × 1–2 is a common starting point.
- `reactorThreads(n)` — number of reactor threads (per master process).
  Defaults to `swoole_cpu_num()`. Increase if you saturate single-thread
  accept.
- `dispatchMode(mode)` — Swoole's worker-selection strategy. The default
  (`2`, fixed) routes by file descriptor, which keeps connection state on
  one worker.

### WebSocket Mode

```php
SwooleWorkerConfig::bind('0.0.0.0', 8080)->enableWebSocket(true)
```

When `enableWebSocket(true)` is set, the runner instantiates
`Swoole\WebSocket\Server` instead of `Swoole\Http\Server`. The factory must
return a `CompiledWsApplication` (i.e. compiled from a `WsApplication`) for
WebSocket routes to register.

> **Worker-mode limitation:** Channel-backed routes
> (`$app->channel(...)`) are **not supported** in worker mode because
> actors don't survive past a single worker. Use plain `ws()` routes with
> per-connection `WebSocketHandler` for worker mode, or switch to
> [thread mode](./http-server-swoole-threads.md) for channel actors.

## Factory Signature

```php
SwooleWorkerServer::run(
    SwooleWorkerConfig $config,
    Closure(ActorSystem $system): CompiledApplication $factory,
): void
```

The factory is called **once per worker process** on `WorkerStart`. It
receives a fresh `ActorSystem` and must return a `CompiledApplication`
(either `CompiledHttpApplication` or `CompiledWsApplication`).

```php
SwooleWorkerServer::run($config, function (ActorSystem $system) {
    // Heavy bootstrap goes here — only happens once per worker.
    $orderActor = $system->spawn(Props::fromFactory(fn() => new OrderActor()), 'orders');

    return HttpApplication::create($system)
        ->withLogger($logger)
        ->actor('orders', Props::fromBehavior($orderActor))
        ->get('/orders', OrderListHandler::class)
        ->compile();
});
```

## Bootstrap Logger

Runner lifecycle logs (worker start, drain begin, shutdown) fire **before**
your per-worker `ActorSystem` exists, so the runner itself needs a
synchronous PSR-3 logger:

```php
$bootstrap = new MyStdErrLogger();   // anything PSR-3 — Monolog, custom, …

SwooleWorkerConfig::bind('0.0.0.0', 8080)
    ->logger($bootstrap)
    // …
```

If unset, runner logs are silently dropped. See `examples/thread-server.php`
for a minimal `LoggerTrait`-based stderr bootstrap logger.

## Graceful Shutdown

`SIGTERM` and `SIGINT` trigger a coordinated shutdown:

1. Master process stops accepting new connections.
2. Each worker drains in-flight requests up to `shutdownTimeout`.
3. Each per-worker `ActorSystem` runs `shutdown()` with the same budget,
   delivering `PostStop` to every actor.
4. Process exits cleanly.

```php
SwooleWorkerConfig::bind('0.0.0.0', 8080)
    ->shutdownTimeout(Duration::seconds(15))
    ->installSignalHandlers(true);
```

Set `installSignalHandlers(false)` if you're running under a process
supervisor that handles signals itself (s6, systemd, …).

## When to Use Worker Mode vs Thread Mode

| Need | Recommendation |
|---|---|
| Simple PHP-FPM-like deployment, per-worker isolation | **Worker mode** (this package) |
| Cross-worker shared actor state, channel broadcasts | [Thread mode](./http-server-swoole-threads.md) |
| Lowest latency for stateless handlers | Either — pick by deployment model |
| Hot reload (`maxRequest`) | Worker mode (recycle on request count) |

## Composition

```php
HttpApplication / WsApplication
        │  ->compile()
        ▼
CompiledApplication
        │
        ▼  factory returns
SwooleWorkerServer::run(SwooleWorkerConfig, factory)
```

See [nexus-http](./http.md) for the HTTP DSL and [nexus-http-ws](./http-ws.md)
for WebSocket routes.
