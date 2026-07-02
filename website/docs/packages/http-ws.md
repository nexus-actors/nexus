---
sidebar_position: 7
title: nexus-http-ws
related:
  - packages/http
  - packages/http-server-swoole
  - packages/http-server-swoole-threads
  - http/overview
---

# nexus-http-ws

WebSocket DSL and fluent HTTP application builder for Nexus, adding `ws()` and `channel()` routes on top of the `nexus-http` primitives.

## What's in this package

- `HttpApplication` — fluent builder for HTTP-only applications
- `WsApplication` — decorator that adds `ws()` and `channel()` WebSocket routes
- `CompiledApplication`, `CompiledHttpApplication`, `CompiledWsApplication` — compiled output accepted by server runners
- `WebSocketHandler` — per-connection base class with `onOpen`, `onMessage`, `onClose` hooks
- `WebSocketContext` — per-connection handle: `send`, `sendBinary`, `sendPing`, `close`, `id`, `request`, `isAlive`
- `WebSocketFrame` — immutable frame: `kind` (1 = TEXT, 2 = BINARY), `text`
- `WebSocketChannelActor` — actor-backed fan-out base class with `broadcast()`
- `#[FromContext]` — constructor injection of `WebSocketContext`

## Install

```bash
composer require nexus-actors/http-ws
```

## Quick example

<!-- verify:skip: requires a running Swoole server -->
```php title="src/Http/EchoHandler.php" verify:skip
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
    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('echo:' . $frame->text);
    }
}

$app = WsApplication::create($system)
    ->get('/health', static fn() => JsonResponse::ok(['status' => 'up']))
    ->ws('/ws/echo', EchoHandler::class)
    ->compile();
```

One `EchoHandler` instance is created per WebSocket connection when the upgrade succeeds. For broadcast scenarios, route to a `WebSocketChannelActor` via `->channel('/ws/chat/{room}', ChatRoomActor::class, key: 'room')` — channel routes require thread mode.

## See also

- [nexus-http](./http.md) — primitives this package builds on
- [nexus-http-server-swoole-threads](./http-server-swoole-threads.md) — thread-mode runner (required for channel routes)
- [nexus-http-server-swoole](./http-server-swoole.md) — worker-mode runner
