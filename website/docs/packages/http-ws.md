---
sidebar_position: 7
title: nexus-http-ws
---

# nexus-http-ws

Runtime-agnostic WebSocket DSL for nexus-http. Adds `ws()` and `channel()`
routes to the HTTP application, typed `WebSocketHandler` lifecycle, and an
actor-backed `WebSocketChannelActor` base class for fan-out scenarios.

**Composer:** `nexus-actors/http-ws`

**Namespace:** `Monadial\Nexus\Http\Ws\`

## Architecture

Two `Application` implementations share the same builder shape:

- **`HttpApplication`** — HTTP-only. Delegates straight to `HttpApp`.
- **`WsApplication`** — HTTP + WebSocket. Decorates `HttpApplication` and
  adds `ws()` / `channel()` routes plus the connection table, dispatcher,
  and handler instantiator.

Both compile to a `CompiledApplication` (interface), specialised as
`CompiledHttpApplication` or `CompiledWsApplication`. Server runners accept
the interface, so the same runner can drive either flavour.

```
┌─────────────────────────────┐
│ Application                 │  ← interface (HTTP DSL)
├─────────────────────────────┤
│ HttpApplication             │  ← HTTP-only
│ WsApplication               │  ← HTTP + WS (decorator)
└─────────────────────────────┘
              │ ->compile()
              ▼
┌─────────────────────────────┐
│ CompiledApplication         │  ← interface
├─────────────────────────────┤
│ CompiledHttpApplication     │
│ CompiledWsApplication       │  ← adds WS dispatcher + connection table
└─────────────────────────────┘
```

## Quick Start

```php
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;
use Monadial\Nexus\Http\Ws\WsApplication;

final class EchoHandler extends WebSocketHandler
{
    public function __construct(
        #[FromContext] private readonly WebSocketContext $ctx,
    ) {}

    #[\Override]
    public function onOpen(): void
    {
        $this->ctx->send('welcome');
    }

    #[\Override]
    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('echo:' . $frame->text);
    }

    #[\Override]
    public function onClose(int $code): void
    {
        // cleanup
    }
}

$app = WsApplication::create($system)
    ->get('/health', static fn() => JsonResponse::ok(['status' => 'up']))
    ->ws('/ws/echo', EchoHandler::class)
    ->compile();
```

The full HTTP DSL (`get`/`post`/`group`/`middleware`/`actor`/etc.) is
identical to [nexus-http](./http.md); only `ws()` and `channel()` are
new.

## WebSocketHandler

Extend `WebSocketHandler` and override three lifecycle hooks. One handler
instance is created **per connection** when the WebSocket upgrade
succeeds.

```php
abstract class WebSocketHandler
{
    public function onOpen(): void {}
    public function onMessage(WebSocketFrame $frame): void {}
    public function onClose(int $code): void {}
}
```

Constructor injection uses two attributes (see [Dependency Injection](#dependency-injection)).

### WebSocketFrame

Immutable frame carrying the message payload:

```php
final readonly class WebSocketFrame
{
    public function __construct(
        public int $kind,    // 1 = TEXT, 2 = BINARY
        public string $text, // raw payload (text or binary bytes)
    ) {}
}
```

Use `$frame->kind === 2` to branch on binary; the bytes live in `$frame->text`
either way.

### WebSocketContext

Per-connection handle. Injected via `#[FromContext]`.

```php
$ctx->id();           // int — connection fd
$ctx->request();      // ServerRequestInterface — original upgrade request
$ctx->send($text);    // send a TEXT frame
$ctx->sendBinary($data);  // send a BINARY frame
$ctx->sendPing();     // send a control PING (keep-alive)
$ctx->close($code, $reason);  // close with WebSocket close code
$ctx->isAlive();      // bool — still connected
```

`$ctx->request()->getAttribute('name')` exposes path parameters from the
WebSocket route (`/ws/chat/{room}` → `$ctx->request()->getAttribute('room')`).

## Dependency Injection

WebSocket handlers use the same attribute-driven DI as HTTP handlers, with
one extra attribute:

```php
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Handler\Attribute\FromService;
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;

final class ChatHandler extends WebSocketHandler
{
    public function __construct(
        #[FromContext] private readonly WebSocketContext $ctx,
        #[FromActor('chat-room')] private readonly ActorRef $room,
        #[FromService(LoggerInterface::class)] private readonly LoggerInterface $log,
    ) {}
}
```

- `#[FromContext]` — the per-connection `WebSocketContext`.
- `#[FromActor('name')]` — actor registered with `$app->actor(...)`.
- `#[FromService(Id::class)]` — anything resolvable from the PSR-11
  container passed via `$app->withContainer(...)`.

## Channel-Backed Routes (Actor Mode)

For broadcast / fan-out scenarios where many connections share state,
route to a `WebSocketChannelActor` instead of a per-connection handler:

```php
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketChannelActor;

final class ChatRoomActor extends WebSocketChannelActor
{
    public function onOpen(WebSocketContext $ctx): void
    {
        $this->broadcast('user joined: ' . $ctx->id());
    }

    public function onMessage(WebSocketContext $ctx, WebSocketFrame $frame): void
    {
        $this->broadcast($ctx->id() . ': ' . $frame->text);
    }
}

$app->channel('/ws/chat/{room}', ChatRoomActor::class, key: 'room');
```

The `key` parameter selects which path attribute partitions the actor —
each `{room}` value gets its own actor instance with its own connection
set. Calling `$this->broadcast()` inside the actor sends to every open
connection in that partition.

> **Note:** Channel routes require thread mode (`SwooleThreadServer`)
> because actor state must outlive a single Swoole worker. They throw at
> compile time if used with worker-mode runners.

## Application Configuration

Both `HttpApplication` and `WsApplication` share the common HTTP DSL
(`get`/`post`/`group`/`middleware`/`actor`/`discover`/`errorMode`/
`withRouteCache`). `WsApplication` adds three extras:

- `->ws($path, HandlerClass)` — per-connection WebSocket route.
- `->channel($path, ActorClass, key: 'param')` — actor-backed broadcast
  route (thread mode only).
- `->withLogger($logger)` / `->withContainer($psr11)` — runtime debug
  logger and DI source for `#[FromService]` resolution.

```php
$app = WsApplication::create($system)
    ->withLogger($logger)
    ->withContainer($psr11Container)
    ->middleware(RequestIdMiddleware::class)
    ->actor('orders', $ordersProps)
    ->get('/', $handler)
    ->ws('/ws/echo', EchoHandler::class)
    ->channel('/ws/chat/{room}', ChatRoomActor::class, key: 'room')
    ->compile();
```

For HTTP-only applications, `HttpApplication::create($system)` returns
the same builder shape minus the WS methods.

### Adding WebSocket to an existing app

If you already have an `HttpApplication` and want to layer WS routes
on top, use the decorator factory:

```php
$app = WsApplication::decorate($existingHttpApplication, $system);
$app->ws('/ws/echo', EchoHandler::class);
```

`WsApplication::decorate()` reuses the inner application's routes,
middleware, and actor registrations — no rewriting needed.

## Composition

```
HttpApplication or WsApplication
        │
        ▼
CompiledApplication
        │
        ▼  passed to a runner
SwooleWorkerServer  ← worker mode (single-process actors)
SwooleThreadServer  ← thread mode (cross-thread pool-singleton actors)
```

See [nexus-http-server-swoole](./http-server-swoole.md) and
[nexus-http-server-swoole-threads](./http-server-swoole-threads.md) for
the runners.

## End-to-End Example

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\{SwooleThreadConfig, SwooleThreadServer};
use Monadial\Nexus\Http\Ws\{CompiledApplication, WsApplication};
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\{WebSocketContext, WebSocketFrame, WebSocketHandler};
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\WorkerNode;

final class EchoHandler extends WebSocketHandler
{
    public function __construct(
        #[FromContext] private readonly WebSocketContext $ctx,
    ) {}

    #[\Override]
    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('echo:' . $frame->text);
    }
}

SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)
        ->threads(4)
        ->enableWebSocket(true)
        ->shutdownTimeout(Duration::seconds(5)),
    static function (ActorSystem $system, WorkerNode $node): CompiledApplication {
        return WsApplication::create($system)
            ->get('/', static fn() => JsonResponse::ok([
                'tid' => $node->workerId(),
                'links' => [['rel' => 'echo-ws', 'href' => '/ws/echo']],
            ]))
            ->ws('/ws/echo', EchoHandler::class)
            ->compile();
    },
);
```

A full version with logging and route attributes lives at
`examples/thread-server.php`.
