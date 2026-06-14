---
sidebar_position: 9
title: nexus-http-server-swoole-threads
---

# nexus-http-server-swoole-threads

Thread-mode HTTP + WebSocket server built on Swoole 6's native
`SWOOLE_THREAD` runtime. Each worker is a thread in one process, sharing
memory via `Swoole\Thread\Map` and `Swoole\Thread\Queue`. Enables
cross-thread actor pools, channel-backed WebSocket broadcasts, and
lock-free async logging — none of which work in worker mode.

**Composer:** `nexus-actors/http-server-swoole-threads`

**Namespace:** `Monadial\Nexus\Http\Server\Swoole\Threads\`

## Prerequisites

- **PHP 8.5+ with ZTS** (Zend Thread Safety) build.
- **Swoole 6.0+ compiled with `--enable-swoole-thread`**.

Verify with:

```bash
docker compose exec php-swoole php -r 'echo PHP_ZTS ? "ZTS\n" : "NTS\n";'
docker compose exec php-swoole php --ri swoole | grep thread
```

The repo's `docker/Dockerfile` `php-swoole` target ships both.

## Quick Start

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\{SwooleThreadConfig, SwooleThreadServer};
use Monadial\Nexus\Http\Ws\{CompiledApplication, WsApplication};
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\WorkerNode;

SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)
        ->threads(8)
        ->enableWebSocket(true)
        ->shutdownTimeout(Duration::seconds(5)),
    static function (ActorSystem $system, WorkerNode $node): CompiledApplication {
        return WsApplication::create($system)
            ->get('/', static fn() => JsonResponse::ok([
                'tid' => $node->workerId(),
            ]))
            ->compile();
    },
);
```

The runner spawns `threads(n)` worker threads, each receiving its own
`ActorSystem` and a `WorkerNode` identifying the thread within the pool.

## Configuration

`SwooleThreadConfig` is an immutable builder. Start with `bind()` and chain:

```php
SwooleThreadConfig::bind('0.0.0.0', 8080)
    ->threads(8)                                // worker thread count (default 1)
    ->maxRequest(10_000)                        // recycle thread after N requests
    ->enableWebSocket(true)                     // Swoole\WebSocket\Server semantics
    ->shutdownTimeout(Duration::seconds(10))    // graceful drain budget
    ->installSignalHandlers(true)               // handle SIGTERM/SIGINT
    ->logger($bootstrapLogger)                  // PSR-3 for runner lifecycle
    ->withLogQueue($threadQueue);               // async logger sink (see below)
```

## Factory Signature

```php
SwooleThreadServer::run(
    SwooleThreadConfig $config,
    Closure(ActorSystem $system, WorkerNode $node): CompiledApplication $factory,
): void
```

The factory runs **once per thread** on thread start. It receives:

- `$system: ActorSystem` — per-thread actor system.
- `$node: WorkerNode` — identifies this thread in the pool. Use
  `$node->workerId()` for thread-local IDs (logging, sharding,
  consistent-hash partitioning).

```php
SwooleThreadServer::run($config, function (ActorSystem $system, WorkerNode $node) {
    return WsApplication::create($system)
        ->get('/health', fn() => Response::ok())
        ->ws('/ws/echo', EchoHandler::class)
        ->channel('/ws/chat/{room}', ChatRoomActor::class, key: 'room')
        ->compile();
});
```

## Channel-Backed WebSocket Routes

Thread mode is the **only** Swoole adapter that supports channel actors
(`$app->channel(...)`), because the channel registry is backed by a shared
`Swoole\Thread\Map`. A connection landing on thread 3 can broadcast through
an actor whose state lives on thread 7.

See [nexus-http-ws](./http-ws.md#channel-backed-routes-actor-mode) for the
WebSocketChannelActor API.

## Async Logging via `withLogQueue()`

Thread mode unlocks a lock-free async logging pattern: every worker thread
pushes formatted log lines onto a shared `Swoole\Thread\Queue`, and a
dedicated writer thread drains it to a single file. No `flock`, no per-write
open/close, no contention across worker threads.

```php
use Monadial\Nexus\Logger\Swoole\ThreadQueueHandler;
use Swoole\Thread;
use Swoole\Thread\Atomic;
use Swoole\Thread\Queue;

$logQueue = new Queue();
$shutdown = new Atomic(0);
$logFile = '/var/log/app.log';

// Writer thread: drains $logQueue → $logFile until $shutdown->set(1).
$writer = new Thread(__DIR__ . '/logger-writer.php', $logQueue, $logFile, $shutdown);

SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)
        ->threads(8)
        ->withLogQueue($logQueue),
    static function (ActorSystem $system, WorkerNode $node) use ($logQueue) {
        $logger = NexusLogger::create($system, "thread-{$node->workerId()}")
            ->handler(new ThreadQueueHandler($logQueue, new LineFormatter()))
            ->build();

        return WsApplication::create($system)
            ->withLogger($logger)
            // …routes…
            ->compile();
    },
);

$shutdown->set(1);
$writer->join();
```

The writer-thread script is bundled at `examples/logger-writer.php`. See
[nexus-logger#threadqueuehandler-swoole](./logger.md#threadqueuehandler-swoole)
for the full picture and benchmark numbers.

## WorkerNode

Per-thread identity object. The runner passes it to your factory so you
can:

- Stamp `threadId` into MDC for log correlation.
- Pick a thread-local channel/queue.
- Implement consistent-hash partitioning across the pool.

```php
Mdc::putStatic('threadId', $node->workerId());

$logger = NexusLogger::create($system, "thread-{$node->workerId()}")
    ->handler(new ConsoleHandler(STDOUT, new LineFormatter()))
    ->build();
```

`WorkerNode::workerId(): int` returns a stable thread ID in `[0, threads-1]`.

## Composition

```
HttpApplication or WsApplication
        │  ->compile()
        ▼
CompiledApplication
        │
        ▼  factory returns
SwooleThreadServer::run(SwooleThreadConfig, factory)
        │
        ├─→ shared Swoole\Thread\Map (channel actor registry)
        ├─→ shared Swoole\Thread\Queue (log sink — optional)
        └─→ N worker threads, each with ActorSystem + WorkerNode
```

## Worker Mode vs Thread Mode

| Capability | Worker mode | Thread mode |
|---|---|---|
| Per-worker ActorSystem isolation | ✓ | ✓ |
| Cross-worker shared state | — | ✓ (`Thread\Map` / `Thread\Queue`) |
| Channel-backed WebSocket routes | — | ✓ |
| Lock-free async logging via `Thread\Queue` | — | ✓ |
| Available without ZTS PHP build | ✓ | — |
| Easy hot reload via `maxRequest` | ✓ | ✓ |

Pick worker mode for simpler deployments and broader PHP compatibility;
pick thread mode when you need shared state across the pool.

## Full Example

See `examples/thread-server.php` for a complete annotated example: 8
threads, bootstrap logger, MDC, per-thread async logger, HTTP routes, and
a `/ws/echo` WebSocket handler.
