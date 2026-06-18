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
        $order = $this->orders->ask(new GetOrder($id), Duration::seconds(2))->await();

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

`ask()` returns a `Future` immediately. `->await()` blocks the calling
fiber/coroutine until the actor replies or the timeout expires:

```php
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

The reply is routed via `$ctx->sender()` on the actor side — see
[Ask pattern](../core-concepts/ask-pattern.md) for the message shapes
the framework accepts. Inside a Swoole runtime `await()` yields the
coroutine, so other requests on the same thread continue running.
Use `ask` for read paths; use `tell` (no reply) for fire-and-forget
writes where the actor is the source of truth.

## Custom parameter injection

The four attributes above (`#[FromActor]`, `#[FromService]`, `#[FromBody]`,
`#[FromContext]`) cover most needs. When they don't — typically when a
cross-cutting value lives on the request and you'd rather not thread the
PSR-7 `ServerRequestInterface` through every handler — write a custom
*param resolver*.

The classic use case: a multi-tenant SaaS where every request resolves
to a tenant ID (from a subdomain, a header, a JWT claim). Adding a
`#[FromTenant]` parameter attribute is a small, isolated piece of glue
that keeps handler signatures honest about what they depend on, without
forcing every handler to know how the tenant is computed.

Four steps.

### Step 1: Define the attribute

A parameter-level attribute with no constructor arguments. The class
exists purely as a marker; the resolver below does the actual work.

```php
namespace App\Http\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromTenant
{
}
```

If you needed configurable behaviour (e.g. `#[FromTenant(default: 'public')]`),
you'd add constructor parameters here and read them inside the resolver
via `$attrs[0]->newInstance()`.

### Step 2: Implement the resolver

The `ParamResolver` interface has two methods:

- `compile()` runs once at handler-resolve time. Decide whether this
  resolver claims the parameter; if yes return a `ParamMetadata`; if no
  return `null` so the next resolver gets a turn.
- `resolve()` runs per request, producing the actual value.

```php
namespace App\Http\Resolver;

use App\Http\Attribute\FromTenant;
use LogicException;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\RequestBoundContext;
use Override;
use ReflectionParameter;

final readonly class FromTenantResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        // Not our attribute? Defer.
        if ($param->getAttributes(FromTenant::class) === []) {
            return null;
        }

        // The tenant lives on the request, which only exists at request
        // time. Refuse to resolve in handler constructors.
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
                'Handler requested #[FromTenant] but no tenant attribute on the request — '
                . 'register TenantResolutionMiddleware globally.',
            );
        }

        return $tenant;
    }
}
```

A few things worth pointing out:

- Compile-time errors (used in the wrong scope) crash boot, not requests.
  That's the right tradeoff: every handler is exercised on the first
  cold boot, so misuses surface immediately.
- The `assert($ctx instanceof RequestBoundContext)` is safe — the
  framework guarantees `resolve()` is only ever called with metadata
  this resolver produced, and our `compile()` already rejected
  non-request-bound scopes.
- The middleware that *stamps* the tenant attribute is your code; the
  resolver only reads it.

### Step 3: Register the resolver

One line in the application bootstrap:

```php
$app = HttpApplication::create($system)
    ->middleware(new TenantResolutionMiddleware($tenantLookup))
    ->paramResolver(new FromTenantResolver());
```

Resolvers are stored in an ordered registry. By default new resolvers
are *appended* — built-ins try first, then your resolver. If you ever
need to override a built-in attribute's behaviour, pass
`override: true` to prepend.

### Step 4: Use it

```php
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

The handler signature now states its real dependency: "I need a tenant
ID and a repository." How those arrive is the framework's problem.

The same pattern works for `WebSocketHandler` constructors — they run
in `Scope::WsConnection`, which is also request-bound, so
`isRequestBound()` returns true and the resolver fires identically.

For the full list of built-in resolvers and the `ParamResolver` /
`CompileContext` / `InvocationContext` reference, see
[the package page](../packages/http.md#custom-param-resolvers).

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
