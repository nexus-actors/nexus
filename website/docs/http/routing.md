---
sidebar_position: 3
title: Routing
related:
  - http/handlers
  - http/middleware
  - http/overview
  - packages/http
---

# Routing

The Nexus router is a trie keyed on path segments with PSR-7 attribute binding and per-route middleware. Routes are registered through the builder, then frozen into an immutable `CompiledApplication` at boot. There is no runtime route mutation.

## HTTP verbs

One method per HTTP verb:

```php title="server.php"
$app->get('/users', $handler);
$app->post('/users', $handler);
$app->put('/users/{id}', $handler);
$app->patch('/users/{id}', $handler);
$app->delete('/users/{id}', $handler);
```

Each returns a `RouteBuilder` for per-route configuration (middleware, name).

## Path parameters

Wrap parameter names in braces. The router extracts each matched value as a PSR-7 request attribute:

```php title="server.php"
$app->get('/orders/{id}', static function (ServerRequestInterface $req) {
    $id = (string) $req->getAttribute('id');
    return JsonResponse::ok(['id' => $id]);
});
```

Multiple parameters are extracted into separate request attributes:

```php title="server.php"
$app->get('/projects/{org}/tasks/{taskId}', static function (ServerRequestInterface $req) {
    $org    = (string) $req->getAttribute('org');
    $taskId = (string) $req->getAttribute('taskId');
    // …
});
```

Parameters match a single path segment (anything except `/`). The matched value is always a string; cast or validate inside the handler.

## Named routes

Tag a route so other code (URL generation, link headers, tests) can reference it without rebuilding the path:

```php title="server.php"
$app->get('/orders/{id}', ShowOrderHandler::class)->name('orders.show');
```

Names must be unique per application. Lookup is constant-time.

## Route groups

Share a path prefix and a middleware stack across a block of routes:

```php title="server.php"
$app->group('/api/v1', static function ($group) {
    $group->get('/orders', OrderListHandler::class);
    $group->post('/orders', OrderCreateHandler::class);
    $group->get('/orders/{id}', ShowOrderHandler::class);
})->middleware(ApiKeyMiddleware::class);
```

The group's middleware runs after the global pipeline and before each route's own middleware. Groups nest:

```php title="server.php"
$app->group('/api/v1', static function ($g) {
    $g->group('/admin', static function ($admin) {
        $admin->get('/users', AdminUserListHandler::class);
    })->middleware(AdminAuthMiddleware::class);

    $g->get('/orders', OrderListHandler::class);
});
```

Nested groups inherit the parent prefix and middleware.

## Closure vs class handlers

Closures are convenient for tiny routes:

```php title="server.php"
$app->get('/health', static fn() => Response::ok());
```

Classes are the production default. The recommended shape is one invokable class per endpoint:

```php title="src/Http/Handler/HealthHandler.php"
final class HealthHandler
{
    public function __invoke(): ResponseInterface
    {
        return Response::ok();
    }
}
```

```php title="server.php"
$app->get('/health', HealthHandler::class);
```

The `[Class, 'method']` form is available when one controller class serves multiple routes, but one class per endpoint is easier to test and inject:

```php title="server.php"
$app->get('/orders', [OrderController::class, 'index']);
```

See [Handlers](./handlers.md) for constructor injection.

## Route discovery from attributes

For larger applications, declare routes on the handler class and point the discoverer at a directory:

```php title="src/Http/Handler/ShowOrderHandler.php"
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
$app->discover(__DIR__ . '/src/Http/Handler');
```

The discoverer scans the directory for classes carrying `#[Route]`. Each class becomes one route. Per-route middleware:

```php title="src/Http/Handler/CreateOrderHandler.php"
#[Route(
    method: 'POST',
    path: '/orders',
    name: 'orders.create',
    middleware: [AuthMiddleware::class, RateLimitMiddleware::class],
)]
final class CreateOrderHandler { /* … */ }
```

A single class can serve multiple verbs:

```php title="src/Http/Handler/ShowOrderHandler.php"
#[Route('GET', '/orders/{id}')]
#[Route('HEAD', '/orders/{id}')]
final class ShowOrderHandler { /* … */ }
```

### When to use discovery

| Application size | Recommendation |
|---|---|
| Fewer than 20 routes | Inline `->get/post(...)` calls |
| 20+ routes, single team | Either; pick by preference |
| Domain-driven, many bounded contexts | Discovery (route lives next to handler) |

Mixing is fine — explicit `->get(...)` calls and `->discover(...)` coexist in the same application.

## Route caching

Discovery scans files and parses attributes on every boot. Cache the compiled route table to any PSR-16 store for production:

```php title="server.php"
use Psr\SimpleCache\CacheInterface;

$app->withRouteCache($psr16Cache, key: 'app-routes-v1')
    ->discover(__DIR__ . '/src/Http/Handler')
    ->compile();
```

On a cache hit, the discoverer is skipped entirely. Bump the cache key when you deploy new code; the framework treats the key as opaque.

## Dispatcher internals

The dispatcher is a trie keyed on path segments, with parameter slots matching any single segment. Lookup is O(path length), not O(routes).

When two routes overlap, the most specific wins:

```php title="server.php"
$app->get('/users/me', MyProfileHandler::class);      // wins for /users/me
$app->get('/users/{id}', ShowUserHandler::class);     // wins for /users/42
```

Literal segments beat parameter slots at the same level.

## 404 and 405

- **`404 Not Found`** — no route matched. The default handler returns `Response::notFound()`. Override via `$app->onException(NotFoundException::class, ...)`.
- **`405 Method Not Allowed`** — a route matched the path but not the verb. The default handler returns `405` with an `Allow` header listing supported verbs.

Both flow through the standard exception handler middleware, so route matchers behave like any other handler: middleware sees them, error mappers can intercept them.

## See also

- [Handlers](./handlers.md) — how handlers are constructed and injected with dependencies.
- [Middleware](./middleware.md) — the pipeline that wraps every route.
- [Error Handling](./error-handling.md) — customising 404 and 405 responses.
