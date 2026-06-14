---
sidebar_position: 4
title: Handlers
---

# Handlers

A handler is whatever you point a route at. Nexus accepts closures,
invokable classes, and `[Class, 'method']` pairs. The recommended shape is
**one invokable class per endpoint** with constructor injection driven by
PHP attributes.

## Closure Handlers

The simplest possible handler:

```php
$app->get('/health', static fn() => Response::ok());
```

Closures receive an optional `ServerRequestInterface`:

```php
$app->get('/orders/{id}', static function (ServerRequestInterface $req) {
    return JsonResponse::ok(['id' => $req->getAttribute('id')]);
});
```

Use closures for routes with no dependencies and trivial logic — health
checks, 301 redirects, hardcoded JSON. Anything heavier deserves a class.

## Invokable Class Handlers

One class, one endpoint, one `__invoke()`:

```php
final class ShowOrderHandler
{
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,
        #[FromService(LoggerInterface::class)] private readonly LoggerInterface $log,
    ) {}

    public function __invoke(ServerRequestInterface $req): ResponseInterface
    {
        $id = (string) $req->getAttribute('id');
        $this->log->info('fetching order {id}', ['id' => $id]);
        $order = $this->orders->ask(fn($reply) => new GetOrder($id, $reply), Duration::seconds(2));

        return JsonResponse::ok($order->toArray());
    }
}
```

Register it like any other handler:

```php
$app->get('/orders/{id}', ShowOrderHandler::class);
```

The framework constructs the handler with attribute-resolved dependencies
when the route matches.

## Constructor Injection

Four attributes drive constructor parameters. Each maps a parameter to a
specific source:

### `#[FromActor('name')]`

Resolves to the `ActorRef` registered under that name on the application
builder:

```php
$app->actor('orders', Props::fromFactory(fn() => new OrderActor()));

final class CreateOrderHandler
{
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,
    ) {}
}
```

The actor is spawned once per worker (or thread) at boot. All requests in
that worker share the same `ActorRef`.

### `#[FromService(Id::class)]`

Resolves from the PSR-11 container:

```php
$app->withContainer($psr11);

final class ListOrdersHandler
{
    public function __construct(
        #[FromService(OrderRepository::class)] private readonly OrderRepository $repo,
        #[FromService(LoggerInterface::class)] private readonly LoggerInterface $log,
    ) {}
}
```

If no container is configured, `#[FromService]` parameters cannot be
resolved and the framework throws at compile time.

### `#[FromBody] Dto $dto`

Decodes the request body into a typed DTO. Goes on the `__invoke()`
parameter, not the constructor:

```php
final readonly class CreateOrderDto
{
    public function __construct(
        public string $sku,
        public int $quantity,
    ) {}
}

final class CreateOrderHandler
{
    public function __invoke(
        ServerRequestInterface $req,
        #[FromBody] CreateOrderDto $dto,
    ): ResponseInterface {
        // $dto->sku, $dto->quantity already validated and typed.
    }
}
```

The body is JSON-decoded and mapped via `nexus-actors/serialization`. A
malformed body throws a `400 Bad Request` before `__invoke()` is called.

### `#[FromContext]` (WebSocket)

Only for `WebSocketHandler` subclasses — injects the per-connection
`WebSocketContext`. See [WebSockets](./websockets.md).

## Per-Request Actors

By default `$app->actor('name', $props)` registers a singleton actor: one
instance per worker, shared by every request. For request-scoped state
(query log, transaction, audit buffer), use `perRequestActor()`:

```php
$app->perRequestActor('audit', Props::fromFactory(fn() => new AuditBufferActor()));

final class CreateOrderHandler
{
    public function __construct(
        #[FromActor('audit')] private readonly ActorRef $audit,
    ) {}

    public function __invoke(ServerRequestInterface $req): ResponseInterface
    {
        $this->audit->tell(new RecordAction('order.create'));
        // …
    }
}
```

A fresh `AuditBufferActor` is spawned at the start of each request and
stopped after the response is written, regardless of exceptions.
`PostStop` runs cleanly — drop your in-memory buffer to disk there.

## The Ask Pattern in Handlers

`ask()` blocks the calling fiber/coroutine until the actor replies or the
timeout expires:

```php
public function __invoke(ServerRequestInterface $req): ResponseInterface
{
    try {
        $order = $this->orders->ask(
            static fn(ActorRef $reply) => new GetOrder($req->getAttribute('id'), $reply),
            timeout: Duration::seconds(2),
        );
    } catch (AskTimeoutException) {
        return Response::gatewayTimeout();
    }

    return JsonResponse::ok($order->toArray());
}
```

Inside a Swoole runtime this yields the coroutine — other requests on the
same thread continue running. Use `ask` for read paths; use `tell` (no
reply) for fire-and-forget writes where the actor is the source of truth.

## Handler Resolution

For a route bound to a class-string, the framework:

1. Inspects the constructor signature once at compile time, caching the
   parameter metadata.
2. At request time, resolves each parameter from its attribute source.
3. Constructs the handler.
4. Calls `__invoke()` (or the named method) with the request and any
   `#[FromBody]`-annotated parameters.

Construction overhead is one `array_map` over cached parameter metadata
per request. The reflection cost is paid once, at compile time.

## Returning Responses

Handlers must return a `ResponseInterface`. Use the static factories on
[`Response`](./responses.md#response) and
[`JsonResponse`](./responses.md#jsonresponse), or build one manually with
your PSR-7 implementation.

Returning anything else throws `TypeError`. The framework does not
auto-wrap return values into JSON — be explicit.

## Error Handling Inside Handlers

Let exceptions propagate. The `ExceptionHandlerMiddleware` upstream
catches them and converts them to responses via your registered
`onException()` mappers:

```php
$app->onException(OrderNotFoundException::class, static fn($e) => Response::notFound($e->getMessage()));

final class ShowOrderHandler
{
    public function __invoke(ServerRequestInterface $req): ResponseInterface
    {
        $id = (string) $req->getAttribute('id');
        $order = $this->repo->find($id);

        if ($order === null) {
            throw new OrderNotFoundException($id);   // → 404
        }

        return JsonResponse::ok($order->toArray());
    }
}
```

See [Error Handling](./error-handling.md) for the full mapping story and
`ErrorMode::Production` vs `ErrorMode::Development`.

## Composition

```
Route::handler ──→ class-string|Closure
                          │
                          ▼
                 HandlerResolver
                          │
                  ┌───────┴────────┐
                  ▼                ▼
            PSR-11 container   No container
                  │                │
                  └──────┬─────────┘
                         ▼
                ParamMetadata cache
                         │
                         ▼
                 #[FromActor]   → ActorRegistry
                 #[FromService] → ContainerInterface
                 #[FromBody]    → MessageSerializer
                 #[FromContext] → WebSocketContext
                         │
                         ▼
                     __invoke()
                         │
                         ▼
                ResponseInterface
```

Up next: [Middleware](./middleware.md), [Responses](./responses.md), and
[Actors in HTTP](./actors-in-http.md) for the wider patterns around the
handler.
