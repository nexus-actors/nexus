# nexus-http-ws

Runtime-agnostic WebSocket DSL for nexus-http. Defines `Application` / `WsApplication`, the `WebSocketHandler` / `WebSocketChannelActor` base classes, the dispatcher, the router, and PSR-11 + attribute-based handler injection. Used by both Swoole runner packages.

## Install

```bash
composer require nexus-actors/http-ws
```

## Quickstart

```php
use Monadial\Nexus\Http\Ws\WsApplication;
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;

final class EchoHandler extends WebSocketHandler
{
    public function __construct(
        #[FromContext] private readonly WebSocketContext $ctx,
    ) {}

    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('echo:' . $frame->text);
    }
}

$app = WsApplication::create($system);
$app->get('/api/users', UsersController::class);
$app->ws('/ws/echo', EchoHandler::class);

// Pass $app->compile() to a runner — SwooleWorkerServer or SwooleThreadServer.
```

## Handler vs channel mode

Two modes share one DSL:

- `ws(string $path, class-string<WebSocketHandler>)` — one POPO handler instance per connection. Stateless or per-connection state. Resolved via PSR-11; constructor params can use `#[FromContext]` for the connection and `#[FromActor('name')]` for any registered actor.
- `channel(string $path, class-string<WebSocketChannelActor>, string $key)` — one actor per path-param value. All connections to `/ws/room/lobby` share the lobby actor; `/ws/room/room42` gets its own. The base class translates internal channel messages into typed `onOpened`/`onMessage`/`onClosed` hooks and provides a `broadcast()` helper.

## Architecture

- `Application` interface — full HTTP surface (every method `HttpApp` exposes) plus `compile()`.
- `HttpApplication implements Application` — HTTP-only concrete, delegates to a wrapped `HttpApp`. Compiles to `CompiledHttpApplication`.
- `WsApplication implements Application` — decorates any `Application` and adds WebSocket methods. Compiles to `CompiledWsApplication`.
- `CompiledApplication` interface extends PSR-15 `RequestHandlerInterface` plus `hasWebSocketRoutes()`. Two impls (`CompiledHttpApplication`, `CompiledWsApplication`). Runners take the interface.
- `WebSocketDispatcher` is the runtime-side seam: `dispatchOpen`/`dispatchMessage`/`dispatchClose`. Runners call these; everything else (routing, handler resolution, channel actor management, connection-table maintenance) lives here.

## Status

Stable. Channel actors are local to a single runner process/thread; cross-process / cross-thread channel sharing is out of scope for v1.
