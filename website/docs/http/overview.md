---
sidebar_position: 1
title: HTTP Overview
---

# HTTP Overview

Nexus ships a production-grade HTTP and WebSocket stack designed around the
same principles as the rest of the actor system: PSR standards everywhere,
runtime-agnostic application code, location transparency, and an explicit
separation between primitives, builder DSL, and Swoole adapters.

If you've used Slim, Mezzio, or Laminas before, the surface will feel
familiar. What's different: you can inject actors into your handlers,
WebSocket lifecycle hooks compose with the actor model, and the same
compiled application runs on worker-mode or thread-mode Swoole with no code
changes.

## The Stack

Four packages collaborate. You depend on whichever combination matches your
target runtime; nothing else is loaded.

```
┌──────────────────────────────────────────────────────────────┐
│  nexus-http               (primitives)                       │
│    Routing, dispatcher, middleware, responses, attributes,   │
│    error modes, route cache. PSR-7/15. Runtime-agnostic.     │
└──────────────────────────────────────────────────────────────┘
                              ▲
┌──────────────────────────────────────────────────────────────┐
│  nexus-http-ws            (builder DSL + WebSocket)          │
│    HttpApplication, WsApplication, CompiledApplication,      │
│    WebSocketHandler, WebSocketChannelActor,                  │
│    WebSocketContext, WebSocketFrame.                         │
└──────────────────────────────────────────────────────────────┘
                              ▲
┌─────────────────────────────────────┬────────────────────────┐
│  nexus-http-server-swoole           │ nexus-http-server-     │
│    Worker-mode Swoole runner.       │   swoole-threads       │
│    Per-worker ActorSystem.          │    Thread-mode runner. │
│    No ZTS requirement.              │    Channel actors. ZTS │
│                                     │    + Swoole 6 required.│
└─────────────────────────────────────┴────────────────────────┘
```

The split is the same shape as the rest of Nexus: a foundation package
that knows nothing about transports, a runtime-agnostic builder layer, and
thin runtime-specific adapters at the bottom.

## What You Write

A typical application boots like this:

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\{SwooleThreadConfig, SwooleThreadServer};
use Monadial\Nexus\Http\Ws\{CompiledApplication, WsApplication};
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\WorkerNode;

SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)->threads(8),
    static function (ActorSystem $system, WorkerNode $node): CompiledApplication {
        return WsApplication::create($system)
            ->get('/health', static fn() => JsonResponse::ok(['ok' => true]))
            ->get('/orders/{id}', ShowOrderHandler::class)
            ->ws('/ws/echo', EchoHandler::class)
            ->compile();
    },
);
```

Three layers in one expression:

1. **Adapter** (`SwooleThreadServer::run`) — runtime-specific entry point.
2. **Builder DSL** (`WsApplication::create($system)->...->compile()`) —
   runtime-agnostic routes, middleware, handlers.
3. **Compiled application** (`CompiledApplication`) — immutable bundle of
   routes and behaviour. The runner consumes this and never touches the
   builder again.

## Design Principles

### Runtime-agnostic application code

`HttpApplication` and `WsApplication` know nothing about Swoole. They
produce a `CompiledApplication` — an immutable artefact that any adapter
can serve. Swap `SwooleWorkerServer` for `SwooleThreadServer` (or any
future PSR-7 adapter) without touching a single route.

### PSR-everything

- **PSR-7** for HTTP messages.
- **PSR-15** for middleware and the request handler chain.
- **PSR-11** for dependency injection into handler constructors.
- **PSR-14** for system events (route matched, response sent, …).
- **PSR-3** for logging.
- **PSR-16** for the route cache.

Anything you already know about these standards applies.

### Attribute-driven dependency injection

Handlers declare their dependencies via constructor parameters annotated
with `#[FromActor]`, `#[FromService]`, `#[FromBody]`, or `#[FromContext]`.
The framework resolves them at request time from the actor registry, the
PSR-11 container, the request body, or the WebSocket context.

```php
final class CreateOrderHandler
{
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,
        #[FromService(LoggerInterface::class)] private readonly LoggerInterface $log,
    ) {}

    public function __invoke(ServerRequestInterface $req, #[FromBody] CreateOrderDto $dto): ResponseInterface
    {
        // …
    }
}
```

No service locator, no manual wiring, no global state.

### Actors first

Routes can spawn actors, hold actor refs, and treat actor `ask` calls as
ordinary I/O. Per-request actors are spawned on demand and stopped at
response completion. WebSocket channels are themselves actors, so the
actor model extends all the way to the wire.

## Where to Go Next

| You want to… | Read |
|---|---|
| Boot a server in 20 lines | [Getting Started](./getting-started.md) |
| Understand path matching, groups, attributes | [Routing](./routing.md) |
| Inject actors into handlers; per-request scopes | [Handlers](./handlers.md) |
| Compose PSR-15 middleware | [Middleware](./middleware.md) |
| Return JSON, streams, redirects | [Responses](./responses.md) |
| Translate domain exceptions to HTTP | [Error Handling](./error-handling.md) |
| Add WebSocket routes and broadcast actors | [WebSockets](./websockets.md) |
| Bridge HTTP requests to actor systems | [Actors in HTTP](./actors-in-http.md) |
| Pick worker mode vs thread mode | [Servers](./servers.md) |

Or jump straight to the package reference pages:
[nexus-http](../packages/http.md),
[nexus-http-ws](../packages/http-ws.md),
[nexus-http-server-swoole](../packages/http-server-swoole.md),
[nexus-http-server-swoole-threads](../packages/http-server-swoole-threads.md).
