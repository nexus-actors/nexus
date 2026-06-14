---
sidebar_position: 3
title: Routing
---

# Routing

The Nexus router is a `FastRoute`-class trie with PSR-7 attribute binding
and per-route middleware. Routes are registered through the builder, then
frozen into an immutable `CompiledApplication` at boot. There is no
runtime route mutation.

## HTTP Verbs

One method per HTTP verb:

```php
$app->get('/users', $handler);
$app->post('/users', $handler);
$app->put('/users/{id}', $handler);
$app->patch('/users/{id}', $handler);
$app->delete('/users/{id}', $handler);
```

Each returns a `RouteBuilder` for per-route configuration (middleware,
name).

## Path Parameters

Wrap parameter names in braces:

```php
$app->get('/orders/{id}', static function (ServerRequestInterface $req) {
    $id = (string) $req->getAttribute('id');
    return JsonResponse::ok(['id' => $id]);
});
```

Multiple parameters are extracted into separate request attributes:

```php
$app->get('/projects/{org}/tasks/{taskId}', static function (ServerRequestInterface $req) {
    $org = (string) $req->getAttribute('org');
    $taskId = (string) $req->getAttribute('taskId');
    // …
});
```

Parameters match a single path segment (anything except `/`). The matched
value is always a string; cast or validate inside the handler.

## Named Routes

Tag a route so other code (URL generation, link headers, tests) can
reference it without rebuilding the path:

```php
$app->get('/orders/{id}', ShowOrderHandler::class)->name('orders.show');
```

Names must be unique per application. Lookup is constant-time.

## Route Groups

Share a path prefix and a middleware stack across a block of routes:

```php
$app->group('/api/v1', static function ($group) {
    $group->get('/orders', OrderListHandler::class);
    $group->post('/orders', OrderCreateHandler::class);
    $group->get('/orders/{id}', ShowOrderHandler::class);
})->middleware(ApiKeyMiddleware::class);
```

The group's middleware runs **after** the global pipeline and **before**
each route's own middleware. Groups can nest:

```php
$app->group('/api/v1', static function ($g) {
    $g->group('/admin', static function ($admin) {
        $admin->get('/users', AdminUserListHandler::class);
    })->middleware(AdminAuthMiddleware::class);

    $g->get('/orders', OrderListHandler::class);
});
```

Nested groups inherit the parent prefix and middleware.

## Closure vs Class Handlers

Closures are convenient for tiny routes:

```php
$app->get('/health', static fn() => Response::ok());
```

Classes are the production default. Two flavours:

```php
// Invokable class
final class HealthHandler
{
    public function __invoke(): ResponseInterface
    {
        return Response::ok();
    }
}

$app->get('/health', HealthHandler::class);
```

```php
// Per-route method (uncommon — usually one class per endpoint)
$app->get('/orders', [OrderController::class, 'index']);
```

The framework prefers the invokable shape (`__invoke()`) — one class per
endpoint, single responsibility, easy to test. See [Handlers](./handlers.md)
for constructor injection.

## Route Discovery from Attributes

For larger applications, declare routes on the handler class itself and
point the discoverer at a directory:

```php title="src/Http/Handlers/ShowOrderHandler.php"
use Monadial\Nexus\Http\Routing\Attribute\Route;

#[Route('GET', '/orders/{id}', name: 'orders.show')]
final class ShowOrderHandler
{
    public function __invoke(ServerRequestInterface $req): ResponseInterface
    {
        // …
    }
}
```

```php title="server.php"
$app->discover(__DIR__ . '/src/Http/Handlers');
```

The discoverer scans the directory for classes carrying `#[Route]`. Each
class becomes one route. Per-route middleware:

```php
#[Route(
    method: 'POST',
    path: '/orders',
    name: 'orders.create',
    middleware: [AuthMiddleware::class, RateLimitMiddleware::class],
)]
final class CreateOrderHandler { /* … */ }
```

### Multi-method routes

A single class can serve multiple verbs:

```php
#[Route('GET', '/orders/{id}')]
#[Route('HEAD', '/orders/{id}')]
final class ShowOrderHandler { /* … */ }
```

### When to use discovery

| Application size | Recommendation |
|---|---|
| < 20 routes | Inline `->get/post(...)` calls |
| 20+ routes, single team | Either; pick by preference |
| Domain-driven design, many bounded contexts | Discovery (route lives next to handler) |

Mixing is fine — explicit `->get(...)` calls and `->discover(...)`
coexist in the same application.

## Route Caching

Discovery scans files and parses attributes. For production, cache the
compiled route table to any PSR-16 store:

```php
use Psr\SimpleCache\CacheInterface;

$app->withRouteCache($psr16Cache, key: 'app-routes-v1')
    ->discover(__DIR__ . '/src/Http/Handlers')
    ->compile();
```

On a cache hit, the discoverer is skipped entirely. Bump the cache key
when you deploy new code; the framework treats the key as opaque.

For deployments without a shared cache, use an in-memory PSR-16 implementation
plus OPcache — the route table is rebuilt once per worker on first boot
and held in memory for the worker's lifetime.

## Dispatcher Internals

The dispatcher is a trie keyed on path segments, with parameter slots
matching any single segment. Lookup is O(path length), not O(routes).

If you register two routes that overlap, the most specific wins:

```php
$app->get('/users/me', MyProfileHandler::class);          // wins for /users/me
$app->get('/users/{id}', ShowUserHandler::class);         // wins for /users/42
```

Literal segments beat parameter slots at the same level.

## 404 and 405

- **`404 Not Found`** — no route matched. The default handler returns
  `Response::notFound()`. Override via:
  ```php
  $app->onException(NotFoundException::class, static fn() => /* custom */);
  ```
- **`405 Method Not Allowed`** — a route matched the path but not the
  verb. The default handler returns `405` with an `Allow` header listing
  the supported verbs.

Both flow through the standard exception-handler middleware, so route
matchers behave like any other handler: middleware sees them, error
mappers can intercept them.

## Composition

```
HttpApplication ─┐                                     ┌─→ RouteCollection
                 ├─ get / post / group / discover ─────┤
WsApplication  ──┘                                     └─→ Dispatcher (trie)
                          ▲                                     │
                          │                                     ▼
                     ->compile()                       PSR-15 RouterMiddleware
                          │
                          ▼
                  CompiledApplication
```

See [Handlers](./handlers.md) next for how handlers are constructed and
injected with dependencies, or [Middleware](./middleware.md) for the
pipeline that wraps every route.
