---
sidebar_position: 4
title: Handlers
related:
  - http/routing
  - http/middleware
  - http/actors-in-http
  - packages/http
---

# Handlers

A handler is whatever you point a route at — a closure, an invokable class, or a `[Class, 'method']` pair. The recommended shape is one invokable class per endpoint with constructor injection driven by PHP attributes.

## Choosing a parameter resolver

The framework ships five parameter attributes. Pick the one that matches where the value comes from.

- **Use `#[FromBody]` when** you need to decode the request body into a typed DTO — JSON-decoded and mapped at request time, before `__invoke()` fires.
- **Use `#[FromActor('name')]` when** you need an `ActorRef` registered on the application builder — resolves to the singleton or per-request actor at injection time.
- **Use `#[FromService(Id::class)]` when** you need a service from the PSR-11 container — requires `$app->withContainer($psr11)` at boot.
- **Use `#[FromContext]` when** you are inside a `WebSocketHandler` and need the per-connection `WebSocketContext` — WebSocket routes only.
- **Use `#[FromPrincipal]` when** you need the authenticated `Principal` from `nexus-http-auth` — populated by `AuthenticationMiddleware`, per-request.

## Closure handlers

The simplest possible handler:

```php title="server.php"
$app->get('/health', static fn() => Response::ok());
```

Closures receive an optional `ServerRequestInterface`:

```php title="server.php"
$app->get('/orders/{id}', static function (ServerRequestInterface $req) {
    return JsonResponse::ok(['id' => $req->getAttribute('id')]);
});
```

Use closures for routes with no dependencies and trivial logic — health checks, 301 redirects, hardcoded JSON. Anything heavier deserves a class.

## Invokable class handlers

One class, one endpoint, one `__invoke()`:

```php title="src/Http/Handler/ShowOrderHandler.php"
<?php

declare(strict_types=1);

namespace App\Http\Handler;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Runtime\Duration;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Log\LoggerInterface;

final class ShowOrderHandler
{
    /** @param ActorRef<GetOrder> $orders */
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,
        #[FromService(LoggerInterface::class)] private readonly LoggerInterface $log,
    ) {}

    public function __invoke(ServerRequestInterface $req): ResponseInterface
    {
        $id = (string) $req->getAttribute('id');
        $this->log->info('fetching order {id}', ['id' => $id]);
        $order = $this->orders->ask(new GetOrder($id), Duration::seconds(2))->await();

        return JsonResponse::ok($order->toArray());
    }
}
```

Register it like any other handler:

```php title="server.php"
$app->get('/orders/{id}', ShowOrderHandler::class);
```

The framework constructs the handler with attribute-resolved dependencies when the route matches.

## Constructor injection attributes

### `#[FromActor('name')]`

Resolves to the `ActorRef` registered under that name on the application builder:

```php title="server.php"
$app->actor('orders', Props::fromFactory(fn() => new OrderActor()));
```

```php title="src/Http/Handler/CreateOrderHandler.php"
final class CreateOrderHandler
{
    /** @param ActorRef<CreateOrder> $orders */
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,
    ) {}
}
```

The actor is spawned once per worker (or thread) at boot. All requests in that worker share the same `ActorRef`.

The parameter type is validated at compile time: it must be able to hold an `ActorRef` — `ActorRef` itself (optionally nullable or in a union), `object`, `mixed`, or untyped. A `#[FromActor]` parameter typed as a scalar or an incompatible class is rejected during `compile()` with `InvalidFromActorParameterException`, instead of compiling and failing with a `TypeError` on the first request.

### `#[FromService(Id::class)]`

Resolves from the PSR-11 container:

```php title="src/Http/Handler/ListOrdersHandler.php"
final class ListOrdersHandler
{
    public function __construct(
        #[FromService(OrderRepository::class)] private readonly OrderRepository $repo,
        #[FromService(LoggerInterface::class)] private readonly LoggerInterface $log,
    ) {}
}
```

If no container is configured, `#[FromService]` parameters cannot be resolved and the framework throws at compile time.

### `#[FromBody] Dto $dto`

Decodes the request body into a typed DTO. Goes on the `__invoke()` parameter, not the constructor — the body exists only per request:

```php title="src/Http/Handler/CreateOrderHandler.php"
final class CreateOrderHandler
{
    public function __invoke(
        ServerRequestInterface $req,
        #[FromBody] CreateOrderDto $dto,
    ): ResponseInterface {
        // $dto->sku, $dto->quantity already decoded and typed
    }
}
```

The body is JSON-decoded and mapped via `nexus-serialization`. A malformed body throws a `400 Bad Request` before `__invoke()` is called.

### `#[FromContext]` (WebSocket only)

Injects the per-connection `WebSocketContext` into `WebSocketHandler` subclasses. See [WebSockets](./websockets.md).

## Per-request actors

By default `$app->actor('name', $props)` registers a singleton actor — one instance per worker, shared by every request. For request-scoped state (audit buffer, unit of work, transaction), use `perRequestActor()`:

```php title="server.php"
$app->perRequestActor('audit', Props::fromFactory(fn() => new AuditBufferActor()));
```

```php title="src/Http/Handler/CreateOrderHandler.php"
final class CreateOrderHandler
{
    /** @param ActorRef<RecordAction> $audit */
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

A fresh `AuditBufferActor` is spawned at the start of each request and stopped after the response is written, regardless of exceptions. `PostStop` runs cleanly.

## The ask pattern in handlers

`ask()` returns a `Future` immediately. `->await()` suspends the calling fiber or coroutine until the actor replies or the timeout expires:

```php title="src/Http/Handler/ShowOrderHandler.php"
public function __invoke(ServerRequestInterface $req): ResponseInterface
{
    try {
        $order = $this->orders
            ->ask(new GetOrder($req->getAttribute('id')), Duration::seconds(2))
            ->await();
    } catch (AskTimeoutException) {
        return Response::gatewayTimeout();
    }

    return JsonResponse::ok($order->toArray());
}
```

Inside a Swoole runtime `await()` yields the coroutine, so other requests on the same thread keep running. Use `ask` for read paths; use `tell` (no reply) for fire-and-forget writes where the actor is the source of truth.

## Custom parameter resolvers

The built-in attributes cover most needs. When they don't — typically when a cross-cutting value lives on the request — write a custom param resolver.

A common use case: a multi-tenant SaaS where every request resolves to a tenant ID from a subdomain, header, or JWT claim. A `#[FromTenant]` attribute keeps handler signatures explicit about what they depend on.

### Step 1: Define the attribute

```php title="src/Http/Attribute/FromTenant.php"
<?php

declare(strict_types=1);

namespace App\Http\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromTenant {}
```

### Step 2: Implement the resolver

```php title="src/Http/Resolver/FromTenantResolver.php"
<?php

declare(strict_types=1);

namespace App\Http\Resolver;

use App\Http\Attribute\FromTenant;
use LogicException;
use Monadial\Nexus\Http\Handler\Resolver\{CompileContext, InvocationContext, ParamMetadata, ParamResolver, RequestBoundContext};
use Override;
use ReflectionParameter;

final readonly class FromTenantResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        if ($param->getAttributes(FromTenant::class) === []) {
            return null;
        }

        if (!$ctx->isRequestBound()) {
            throw new LogicException(
                "#[FromTenant] cannot be used in {$ctx->owner}::__construct() — "
                . 'tenant is per-request; declare it on __invoke() instead.',
            );
        }

        return new ParamMetadata(resolver: $this, name: $param->getName(), type: 'string');
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        assert($ctx instanceof RequestBoundContext);

        $tenant = $ctx->request->getAttribute('tenant');

        if (!is_string($tenant) || $tenant === '') {
            throw new LogicException(
                'Handler requested #[FromTenant] but no tenant on the request — '
                . 'register TenantResolutionMiddleware globally.',
            );
        }

        return $tenant;
    }
}
```

### Step 3: Register the resolver

```php title="server.php"
$app = HttpApplication::create($system)
    ->middleware(new TenantResolutionMiddleware($tenantLookup))
    ->paramResolver(new FromTenantResolver());
```

### Step 4: Use it

```php title="src/Http/Handler/ShowDashboardHandler.php"
final class ShowDashboardHandler
{
    public function __invoke(
        #[FromTenant] string $tenantId,
        #[FromService(DashboardRepository::class)] DashboardRepository $repo,
    ): ResponseInterface {
        return JsonResponse::ok($repo->forTenant($tenantId)->toArray());
    }
}
```

The same pattern works for `WebSocketHandler` constructors — they run in `Scope::WsConnection`, which is request-bound, so the resolver fires identically.

## Handler resolution

For a route bound to a class-string, the framework:

1. Inspects the constructor signature once at compile time, caching the parameter metadata.
2. At request time, resolves each parameter from its attribute source.
3. Constructs the handler.
4. Calls `__invoke()` (or the named method) with the request and any `#[FromBody]`-annotated parameters.

Construction overhead is one array traversal over cached parameter metadata per request. Reflection is paid once, at compile time.

## Returning responses

Handlers must return a `ResponseInterface`. Use the static factories on [`Response`](./responses.md#response) and [`JsonResponse`](./responses.md#jsonresponse), or build one with your PSR-7 implementation.

Returning anything else throws `TypeError`. The framework does not auto-wrap return values into JSON.

## Error handling inside handlers

Let exceptions propagate. The `ExceptionHandlerMiddleware` upstream catches them and converts them to responses via your registered `onException()` mappers:

```php title="server.php"
$app->onException(
    OrderNotFoundException::class,
    static fn($e) => Response::notFound($e->getMessage()),
);
```

See [Error Handling](./error-handling.md) for the full mapping story.

## See also

- [Actors in HTTP](./actors-in-http.md) — singleton vs per-request actors in depth.
- [Middleware](./middleware.md) — the pipeline that wraps every handler.
- [Responses](./responses.md) — `Response`, `JsonResponse`, `StreamingResponse`.
