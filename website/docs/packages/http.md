---
sidebar_position: 6
title: nexus-http
related:
  - packages/http-ws
  - packages/http-auth
  - http/overview
  - http/handlers
---

# nexus-http

Core HTTP framework primitives: PSR-7/PSR-15 dispatcher, routing, middleware pipeline, response factories, attribute-driven handler DI, and the `ParamResolver` extension point.

## What's in this package

- `Response`, `JsonResponse`, `StreamingResponse` — static response factories
- `#[FromActor]`, `#[FromService]`, `#[FromBody]` — constructor/parameter DI attributes
- `ParamResolver` interface — extension point for custom parameter injection
- `#[Route]` — handler class attribute for auto-discovery via `$app->discover()`
- `ErrorMode` — `Production` (sanitized) / `Development` (full trace in JSON)
- `RouterMiddleware`, `ExceptionHandlerMiddleware`, `MiddlewarePipeline` — internal pipeline primitives

## Install

```bash
composer require nexus-actors/http
```

## Quick example

```php title="src/Http/Handler/CreateOrderHandler.php"
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Handler\Attribute\FromBody;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Core\Actor\ActorRef;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

final class CreateOrderHandler
{
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,
    ) {}

    public function __invoke(
        ServerRequestInterface $req,
        #[FromBody] CreateOrderDto $dto,
    ): ResponseInterface {
        $this->orders->tell(new PlaceOrder($dto));

        return Response::created('/orders');
    }
}
```

This package provides the primitives. The fluent application builder (`HttpApplication`, `WsApplication`, `.get()`, `.post()`, `.middleware()`, `.actor()`) lives in `nexus-http-ws`.

## See also

- [nexus-http-ws](./http-ws.md) — application builder DSL and WebSocket support
- [nexus-http-auth](./http-auth.md) — authentication and authorization
- [HTTP / handlers](../http/handlers.md) — handler DI reference
