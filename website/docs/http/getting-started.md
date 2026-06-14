---
sidebar_position: 2
title: Getting Started
---

# Getting Started

A minimal Nexus HTTP server. Boots Swoole on `0.0.0.0:8080`, serves three
routes, shuts down cleanly on `Ctrl+C`.

## Install

```bash
composer require nexus-actors/http \
                 nexus-actors/http-ws \
                 nexus-actors/http-server-swoole-threads
```

You need only one server adapter — pick `http-server-swoole-threads` for
thread mode (Swoole 6 + ZTS), or `http-server-swoole` for the more
portable worker mode. The DSL is identical.

## Hello World

```php title="server.php"
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\{JsonResponse, Response};
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\{SwooleThreadConfig, SwooleThreadServer};
use Monadial\Nexus\Http\Ws\{CompiledApplication, HttpApplication};
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Psr\Http\Message\ServerRequestInterface;

SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)
        ->threads(2)
        ->shutdownTimeout(Duration::seconds(5)),
    static function (ActorSystem $system, WorkerNode $node): CompiledApplication {
        return HttpApplication::create($system)
            ->get('/', static fn() => JsonResponse::ok([
                'name'   => 'hello',
                'thread' => $node->workerId(),
            ]))
            ->get('/health', static fn() => Response::ok())
            ->get('/hello/{name}', static function (ServerRequestInterface $req) {
                $name = (string) $req->getAttribute('name');
                return JsonResponse::ok(['greeting' => "Hello, {$name}!"]);
            })
            ->compile();
    },
);
```

Run it:

```bash
docker compose exec php-swoole php server.php
```

Probe it:

```bash
curl http://127.0.0.1:8080/                 # {"name":"hello","thread":0}
curl http://127.0.0.1:8080/health           # (empty 200)
curl http://127.0.0.1:8080/hello/tomas      # {"greeting":"Hello, tomas!"}
```

That's a complete production-shaped server: 8-thread shared-nothing
ActorSystem-per-thread, graceful shutdown on `SIGTERM`/`SIGINT`, PSR-15
pipeline, attribute-driven routing.

## What Just Happened

```
SwooleThreadServer::run(config, factory)
  │
  ├─ Master thread binds 0.0.0.0:8080 and accepts connections.
  │
  ├─ For each of N worker threads:
  │     1. Boot an ActorSystem.
  │     2. Call your factory(system, node) → CompiledApplication.
  │     3. Cache the compiled app for the thread's lifetime.
  │
  ├─ For each incoming request:
  │     - Swoole hands the request to a worker thread (dispatch by fd).
  │     - The thread runs the cached CompiledApplication's PSR-15 pipeline.
  │     - The response is written back on the same connection.
  │
  └─ On SIGTERM/SIGINT:
        - Stop accepting new connections.
        - Drain in-flight requests up to shutdownTimeout.
        - Shut down each thread's ActorSystem.
        - Exit cleanly.
```

Three things to notice:

1. **The factory runs once per thread, not per request.** Heavy bootstrap
   (DI container assembly, route discovery, actor spawning) is paid
   exactly once, at thread start.
2. **Each thread owns its `ActorSystem`.** No shared state in your route
   handlers means no locks, no race conditions on application state.
3. **The compiled application is immutable.** After `->compile()`, the
   route table is frozen. This is what makes route caching safe and
   request dispatch fast.

## Adding a Class Handler

Closures are the quickest path for tiny apps, but most production handlers
are classes:

```php
final class ShowOrderHandler
{
    public function __invoke(ServerRequestInterface $req): ResponseInterface
    {
        $id = (string) $req->getAttribute('id');
        return JsonResponse::ok(['id' => $id, 'status' => 'open']);
    }
}

$app->get('/orders/{id}', ShowOrderHandler::class);
```

Class handlers are resolved through your PSR-11 container (if set) or
instantiated with no-args by default. See [Handlers](./handlers.md) for
constructor injection.

## Adding Logging

The actor-backed PSR-3 logger lives in `nexus-actors/logger`:

```bash
composer require nexus-actors/logger
```

Wire it inside the factory:

```php
use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\{Level, NexusLogger};

static function (ActorSystem $system, WorkerNode $node): CompiledApplication {
    $logger = NexusLogger::create($system, "thread-{$node->workerId()}")
        ->minLevel(Level::Info)
        ->handler(new ConsoleHandler(STDOUT, new LineFormatter()))
        ->build();

    $logger->info('thread up');

    return HttpApplication::create($system)
        ->get('/', static function () use ($logger) {
            $logger->info('handling root');
            return JsonResponse::ok(['ok' => true]);
        })
        ->compile();
}
```

See [Observability](./observability.md) for MDC, async logging via
`Swoole\Thread\Queue`, and call-site capture.

## Next Steps

You now have a server. The rest of this section covers what to put in it:

- [Routing](./routing.md) — verbs, path parameters, groups, attribute-discovered routes.
- [Handlers](./handlers.md) — closure vs class, PSR-11 injection, request-scoped actors.
- [WebSockets](./websockets.md) — `ws()` routes, broadcast actors.
- [Servers](./servers.md) — when to pick worker mode vs thread mode.
