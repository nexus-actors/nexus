---
sidebar_position: 2
title: Getting Started
related:
  - http/overview
  - http/routing
  - http/handlers
  - http/servers
---

# Getting Started

This tutorial walks through booting a minimal Nexus HTTP server. By the end you will have a running Swoole server with three routes, actor injection wired up, and a PSR-3 logger attached.

## Step 1: Install

Add the three packages you need — primitives, the builder DSL, and a server adapter. Pick `http-server-swoole-threads` for thread mode (Swoole 6 + ZTS) or `http-server-swoole` for the more portable worker mode. The builder DSL is identical for both.

```bash
composer require nexus-actors/http \
                 nexus-actors/http-ws \
                 nexus-actors/http-server-swoole-threads
```

## Step 2: Write the server entry point

The server file is the only PHP that knows about Swoole. Your handlers and actors are runtime-agnostic.

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

## Step 3: Run it

```bash
docker compose exec php-swoole php server.php
```

Probe the routes:

```bash
curl http://127.0.0.1:8080/                 # {"name":"hello","thread":0}
curl http://127.0.0.1:8080/health           # (empty 200)
curl http://127.0.0.1:8080/hello/tomas      # {"greeting":"Hello, tomas!"}
```

## Step 4: Add a class handler

Closures work for tiny routes. For handlers that need dependencies, use an invokable class.

```php title="src/Http/Handler/ShowOrderHandler.php"
<?php

declare(strict_types=1);

namespace App\Http\Handler;

use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

final class ShowOrderHandler
{
    public function __invoke(ServerRequestInterface $req): ResponseInterface
    {
        $id = (string) $req->getAttribute('id');
        return JsonResponse::ok(['id' => $id, 'status' => 'open']);
    }
}
```

Register it on a route:

```php title="server.php"
$app->get('/orders/{id}', ShowOrderHandler::class);
```

Class handlers are resolved through your PSR-11 container (if configured) or constructed with no arguments. See [Handlers](./handlers.md) for constructor injection with `#[FromActor]` and `#[FromService]`.

## Step 5: Attach a logger

The actor-backed PSR-3 logger lives in `nexus-actors/logger`:

```bash
composer require nexus-actors/logger
```

Wire it inside the factory:

```php title="server.php"
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

## What we built

- A two-thread Swoole server bound to `0.0.0.0:8080` with graceful shutdown.
- Three routes: a JSON root, an empty health check, and a path-parameter greeting.
- An invokable class handler with no dependencies.
- A per-thread PSR-3 logger backed by an actor.

## Next steps

- [Routing](./routing.md) — verbs, path parameters, groups, attribute-discovered routes.
- [Handlers](./handlers.md) — closure vs class, actor injection, per-request scopes.
- [Servers](./servers.md) — when to pick worker mode vs thread mode.
