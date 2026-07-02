---
title: RouteBuilder
sidebar_position: 18
related:
  - http/routing
  - reference/classes/http-app
---

# RouteBuilder

Fluent per-route configuration builder returned by HTTP verb methods.

## What it does

Every `$app->get()`, `$app->post()`, `$app->put()`, `$app->patch()`, and
`$app->delete()` call returns a `RouteBuilder`. It is the object you chain additional
calls on to attach per-route middleware or assign a named route identifier.

`RouteBuilder` is intentionally mutable during the DSL phase. When `HttpApp::compile()`
is called, it atomically freezes all pending builders into immutable `Route` value
objects that drive the dispatcher. After that point the builder is discarded — nothing
else holds a reference to it.

Per-route middleware registered on a `RouteBuilder` runs *after* the global middleware
stack and *before* the route handler, making it well-suited for auth checks,
per-endpoint rate limits, or request-scoped logging.

## Example

```php title="src/Http/bootstrap.php"
use App\Handler\OrderHandler;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\RequiresRole;
use App\Middleware\RateLimitMiddleware;

// Simple: just register the route
$app->get('/orders', OrderHandler::class);

// With per-route middleware and a name
$app->post('/orders', OrderHandler::class)
    ->middleware(AuthenticationMiddleware::class)
    ->middleware(RequiresRole::class)      // stacked in order
    ->name('order.create');

$app->delete('/orders/{id}', OrderHandler::class)
    ->middleware(AuthenticationMiddleware::class)
    ->name('order.delete');

// Inline closure handler (useful for simple routes or tests)
$app->get('/ping', static fn() => new JsonResponse(['ok' => true]))
    ->name('health.ping');
```

## Key methods

- `middleware(string $class): self` — appends a PSR-15 middleware class name to this
  route's chain. Multiple calls append in registration order. Accepts fully-qualified
  class names; the middleware is resolved from the PSR-11 container at compile time.

- `name(string $name): self` — assigns a unique human-readable identifier to the
  route. Names are used in reverse routing, logging, and debugging output from
  `HttpApp::registeredRoutes()`.

- `build(): Route` — freezes the builder into an immutable `Route` value object.
  Called internally by `HttpApp::compile()`; application code should not need to call
  this directly.

## Ordering of middleware

Middleware executes in this order for every request:

1. Built-in exception handler (unless disabled with `withoutDefaultExceptionHandler()`)
2. Global middleware (registered on `HttpApp` via `middleware()`)
3. Per-route middleware (registered on `RouteBuilder` via `middleware()`)
4. Route handler

Both global and per-route chains respect registration order within their respective
layers.

## Full API reference

[Full method list](https://api.nexusactors.com/classes/Monadial-Nexus-Http-Dsl-RouteBuilder.html)

## See also

- [HTTP routing](../../http/routing) — path parameters, groups, attribute-based routing
- [HttpApp](http-app) — the DSL that produces `RouteBuilder` instances
