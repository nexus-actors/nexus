---
sidebar_position: 6
title: nexus-http
---

# nexus-http

Foundational HTTP framework primitives: PSR-7 / PSR-15 dispatcher, the
routing collection, middleware pipeline, response factories, attribute-based
handler/route metadata, and the PSR-16 route cache. Consumed by
[nexus-http-ws](./http-ws.md), which adds the fluent builder DSL
(`HttpApplication`, `WsApplication`) on top.

This page covers what handler authors actually use day-to-day:
**responses, attributes, error modes, and middleware**. For wiring an app,
see [nexus-http-ws](./http-ws.md).

**Composer:** `nexus-actors/http`

**Namespace:** `Monadial\Nexus\Http\`

## Responses

Two static factories cover the common cases.

### `Response`

```php
use Monadial\Nexus\Http\Response\Response;

Response::ok();                                       // 200, empty body
Response::noContent();                                // 204
Response::created('/orders/42');                      // 201 with Location header
Response::notFound('Order not found');                // 404
Response::badRequest('Invalid payload');              // 400
Response::gatewayTimeout();                           // 504
Response::serviceUnavailable(Duration::seconds(60));  // 503 with Retry-After
Response::internalServerError();                      // 500
```

### `JsonResponse`

```php
use Monadial\Nexus\Http\Response\JsonResponse;

JsonResponse::ok(['items' => $orders]);
JsonResponse::created(['id' => 42], '/orders/42');
```

JSON is encoded with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` by
default. Override flags as the second argument.

### `StreamingResponse`

For chunked output (long downloads, server-sent events). Wraps any
iterable into a PSR-7 stream.

```php
use Monadial\Nexus\Http\Response\StreamingResponse;

return new StreamingResponse(
    static function () {
        foreach (largeDataset() as $row) {
            yield json_encode($row) . "\n";
        }
    },
    headers: ['Content-Type' => 'application/x-ndjson'],
);
```

## Handler Attributes

Three constructor-parameter attributes drive dependency injection into
handler classes:

```php
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Handler\Attribute\FromBody;
use Monadial\Nexus\Http\Handler\Attribute\FromService;
use Monadial\Nexus\Core\Actor\ActorRef;
use Psr\Log\LoggerInterface;

final class CreateOrderHandler
{
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,
        #[FromService(LoggerInterface::class)] private readonly LoggerInterface $log,
    ) {}

    public function __invoke(
        ServerRequestInterface $req,
        #[FromBody] CreateOrderDto $dto,
    ): ResponseInterface {
        $this->log->info('placing order', ['sku' => $dto->sku]);
        $this->orders->tell(new PlaceOrder($dto));

        return Response::created();
    }
}
```

- **`#[FromActor('name')]`** — resolves to an `ActorRef` from the
  application's actor registry. Register the actor with
  `$app->actor('name', $props)` at build time.
- **`#[FromService(Id::class)]`** — resolves from the PSR-11 container
  passed to `$app->withContainer(...)`.
- **`#[FromBody] Dto $dto`** — request body decoded into the typed DTO via
  the configured deserializer.

## Custom Param Resolvers

The four built-in attributes above (`#[FromActor]`, `#[FromService]`,
`#[FromBody]`, plus per-request `#[FromContext]` for WebSockets) are not
hard-coded into the resolver. They're implementations of one interface —
`ParamResolver` — and you can register your own to inject anything else
into handler parameters: a tenant ID, a feature flag, a request trace,
a parsed query DSL.

`nexus-http-auth` ships its `#[FromPrincipal]` attribute exactly this
way. The core stays dependency-free; the auth package extends it.

### The contract

```php
namespace Monadial\Nexus\Http\Handler\Resolver;

interface ParamResolver
{
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata;

    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed;
}
```

Two phases, each called by a different layer of the framework:

- **`compile()`** runs once per handler class, at *handler-resolve time*.
  Inspect the parameter, decide whether your resolver handles it (return
  `null` to defer to the next resolver in the chain), and if so produce
  a `ParamMetadata` carrying everything the per-request `resolve()` will
  need. Compile-time validation errors (unknown actor name, missing type
  hint, missing dependency) belong here — they crash boot, not requests.

- **`resolve()`** runs *per request* (or per connection, for WebSockets).
  The framework only ever calls `resolve()` with metadata produced by the
  same resolver, so the payload shape is yours to trust.

`ParamMetadata` carries a back-ref to its producing resolver, the
parameter name, an optional type, and an opaque `payload` array. The
framework never inspects the payload.

### Scopes

A `CompileContext` carries a `Scope` enum that tells your resolver where
in the handler lifecycle the parameter sits:

| Scope | Where | Available data |
|---|---|---|
| `Scope::HttpBoot` | HTTP handler `__construct()` | services only (no request) |
| `Scope::HttpRequest` | HTTP handler `__invoke()` | services + request + per-request scope |
| `Scope::WsConnection` | `WebSocketHandler::__construct()` | services + request + connection |

Resolvers that need a request body, principal, or path parameter gate
themselves with `if (!$ctx->isRequestBound()) return null;` — that
single line short-circuits the HttpBoot scope cleanly.

### Registration

```php
$app->paramResolver(new MyResolver());                  // appended (built-ins still run first)
$app->paramResolver(new MyResolver(), override: true);  // prepended (wins over built-ins)
```

The registry is consulted in order; first non-null `compile()` wins.
`override: true` is the escape hatch for replacing a built-in attribute
with your own implementation.

### A worked example: `#[FromHeader]`

Inject a single HTTP header value into a handler `__invoke()` parameter.

```php
// 1. The attribute.
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromHeader
{
    public function __construct(public string $name) {}
}

// 2. The resolver.
final readonly class FromHeaderResolver implements ParamResolver
{
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        $attrs = $param->getAttributes(FromHeader::class);

        if ($attrs === [] || !$ctx->isRequestBound()) {
            return null;
        }

        return new ParamMetadata(
            resolver: $this,
            name: $param->getName(),
            type: 'string',
            payload: ['header' => $attrs[0]->newInstance()->name],
        );
    }

    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        assert($ctx instanceof RequestBoundContext);

        return $ctx->request->getHeaderLine($metadata->payload['header']);
    }
}

// 3. The handler.
final class TraceHandler
{
    public function __invoke(
        ServerRequestInterface $req,
        #[FromHeader('X-Trace-Id')] string $traceId,
    ): ResponseInterface {
        return JsonResponse::ok(['trace' => $traceId]);
    }
}

// 4. The registration.
$app = HttpApplication::create($system)
    ->paramResolver(new FromHeaderResolver());
```

The same resolver works for WebSocket handler constructors too — the
WsConnection scope is also request-bound.

### Built-in resolvers

The seven resolvers `nexus-http` ships, in registration order:

| Resolver | Recognises | Available in |
|---|---|---|
| `FromActorResolver` | `#[FromActor('name')]` | HttpBoot, HttpRequest, WsConnection |
| `FromServiceResolver` | `#[FromService(Id::class)]` | HttpBoot, HttpRequest, WsConnection |
| `FromBodyResolver` | `#[FromBody]` | HttpRequest, WsConnection |
| `PathParamResolver` | parameter name matches `{placeholder}` | HttpRequest |
| `ServerRequestResolver` | `ServerRequestInterface` type-hint | HttpRequest, WsConnection |
| `PerRequestScopeResolver` | `PerRequestActorScope` type-hint | HttpRequest |
| `ContainerFallbackResolver` | any other class type-hint, last resort | HttpBoot, HttpRequest, WsConnection |

Third-party resolvers (like `FromPrincipalResolver` from
`nexus-http-auth`) are appended after these — except when registered
with `override: true`, which prepends.

## Route Attribute

Mark a handler class with `#[Route]` and let the auto-discoverer find it:

```php
use Monadial\Nexus\Http\Routing\Attribute\Route;

#[Route('GET', '/orders/{id}', name: 'orders.show')]
final class ShowOrderHandler
{
    public function __invoke(ServerRequestInterface $req): ResponseInterface
    {
        $id = (string) $req->getAttribute('id');
        // …
    }
}
```

Then in the application builder (see [nexus-http-ws](./http-ws.md#auto-discovery-from-attributes)):

```php
$app->discover(__DIR__ . '/Http/Handlers');
```

The discoverer scans the directory for classes carrying `#[Route]` and
registers them at compile time. Per-route middleware:

```php
#[Route('POST', '/orders', middleware: [AuthMiddleware::class, RateLimitMiddleware::class])]
```

## ErrorMode

Controls how unhandled exceptions are serialized:

```php
use Monadial\Nexus\Http\App\ErrorMode;

$app->errorMode(ErrorMode::Production)   // sanitized message, no trace
    ->errorMode(ErrorMode::Development); // full trace in JSON for debugging
```

Combine with exception mappers for domain → HTTP translation:

```php
$app->onException(OrderNotFoundException::class, static fn($e) => Response::notFound($e->getMessage()))
    ->onException(ValidationException::class, static fn($e) => JsonResponse::ok([
        'errors' => $e->errors(),
    ]));
```

`onException()` mappers run **before** `ErrorMode` falls back, so domain
exceptions stay clean even in production.

## Middleware

Standard PSR-15. Register globally or per-route:

```php
// Global (runs for every request, in registration order)
$app->middleware(RequestIdMiddleware::class)
    ->middleware(new RateLimitMiddleware($limiter));

// Per-route (runs after the global pipeline)
$app->get('/admin', AdminHandler::class)
    ->middleware(AuthMiddleware::class);
```

Pass either a class-string (resolved from the PSR-11 container at compile
time) or an already-instantiated `MiddlewareInterface`.

### Built-in middleware

`Monadial\Nexus\Http\Middleware\` ships a few primitives the framework
itself uses:

- `RouterMiddleware` — performs route matching and request-attribute
  binding. Always last in the pipeline.
- `MiddlewarePipeline` — composes a list of middleware around a final
  handler.
- `ExceptionHandlerMiddleware` — wraps the pipeline with `ErrorMode` /
  `onException` translation.

You'll rarely instantiate these directly — they're wired by the compiled
application.

## Route Caching

Skip discovery and route compilation on cold boot by caching the route
table to any PSR-16 store:

```php
use Psr\SimpleCache\CacheInterface;

$app->withRouteCache($psr16Cache, key: 'app-routes-v1')
    ->discover(__DIR__ . '/Handlers')
    ->compile();
```

On a cache hit the discoverer is skipped entirely. Bump the cache key
after deployment to invalidate; the framework treats the key as opaque.

## Composition

```
nexus-http (this package)              ← primitives: responses, attributes,
   │                                       routing, middleware, dispatcher,
   │                                       error modes, route cache
   ▼
nexus-http-ws                          ← user-facing fluent DSL:
   ├── HttpApplication                    HttpApplication / WsApplication
   ├── WsApplication                      WebSocket routes + handler base
   └── CompiledApplication
        │
        ▼
nexus-http-server-swoole               ← Swoole worker-mode runner
nexus-http-server-swoole-threads       ← Swoole thread-mode runner
```

For the full HTTP DSL (`get`/`post`/`group`/`middleware`/`actor`/etc.), see
[nexus-http-ws](./http-ws.md). For WebSocket routing on top of HTTP, see
[nexus-http-ws#websockethandler](./http-ws.md#websockethandler).
