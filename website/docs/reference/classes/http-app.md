---
title: HttpApp
sidebar_position: 17
related:
  - http/getting-started
  - http/routing
  - http/handlers
  - reference/classes/route-builder
---

# HttpApp

The fluent DSL entry point for building a Nexus HTTP application.

## What it does

`HttpApp` is a mutable builder: you chain calls to register routes, actors, global
middleware, and exception mappers. When you are done, one call to `compile()` freezes
the configuration into an immutable `CompiledHttpApp` that implements PSR-15
`RequestHandlerInterface` and is ready to be handed to a Swoole or Fiber HTTP server
adapter.

The split between "building" (`HttpApp`) and "serving" (`CompiledHttpApp`) means:

- The first `compile()` call is terminal: it freezes routes, actors, middleware, and configuration, and spawns worker-local/pool-singleton actors exactly once. Repeated `compile()` calls are idempotent and reuse the frozen state and live actors. Mutating the DSL after compilation throws `HttpAppAlreadyCompiledException`.
- The cost of wiring the middleware pipeline is paid once at boot, not per request.
- Server adapters stay thin: they receive a single `handle()` callable and know
  nothing about routing internals.

## Example

```php title="src/Http/bootstrap.php"
use Monadial\Nexus\Http\Dsl\HttpApp;
use App\Handler\UserHandler;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\RateLimitMiddleware;

// Build the application DSL
$app = HttpApp::create($system, container: $container, logger: $logger);

// Register routes
$app->get('/users/{id}', UserHandler::class);

$app->post('/users', UserHandler::class)
    ->middleware(AuthenticationMiddleware::class)
    ->name('user.create');

$app->delete('/users/{id}', UserHandler::class)
    ->middleware(AuthenticationMiddleware::class);

// Global middleware (applied to every request, in registration order)
$app->middleware(RateLimitMiddleware::class);

// Actor-backed route: register a long-lived actor, then inject it into a
// handler with the #[FromActor('orders')] parameter attribute.
$app->actor('orders', Props::fromBehavior($orderBehavior));
$app->post('/orders', OrderHandler::class); // handler declares #[FromActor('orders')] ActorRef $orders

// Override error verbosity in development
$app->errorMode(ErrorMode::Development);

// Freeze into a ready-to-serve handler
$compiled = $app->compile();
```

## Key methods

### Factory

- `create(ActorSystem $system, ?ContainerInterface $container, ?EventDispatcherInterface $events, ?LoggerInterface $logger): self` —
  creates a new DSL instance tied to the given actor system.

### Route registration

- `get(string $path, string|Closure $handler): RouteBuilder`
- `post(string $path, string|Closure $handler): RouteBuilder`
- `put(string $path, string|Closure $handler): RouteBuilder`
- `patch(string $path, string|Closure $handler): RouteBuilder`
- `delete(string $path, string|Closure $handler): RouteBuilder`

Each method returns a `RouteBuilder` for per-route configuration (middleware, name).
Path segments may contain `{param}` placeholders.

### Actor integration

- `actor(string $name, Props $props): ActorRegistration` — registers a long-lived actor
  shared across all requests on this worker. Inject it into a handler with the
  `#[FromActor('name')]` parameter attribute.
- `perRequestActor(string $name, Props $props): ActorRegistration` — spawns a fresh
  actor for each request and stops it after the handler returns.

### Middleware & errors

- `middleware(string|MiddlewareInterface $middleware): self` — appends a PSR-15
  middleware to the global stack. Runs before per-route middleware.
- `onException(string $exceptionClass, Closure $mapper): self` — registers a custom
  exception-to-response mapper. User mappers take precedence over built-in defaults.
- `withoutDefaultExceptionHandler(): self` — removes the built-in exception middleware
  when you supply your own.
- `errorMode(ErrorMode $mode): self` — `Production` (opaque 500s) or `Development`
  (stack traces in response body).

### Discovery & caching

- `discover(string $directory): self` — scans a directory for classes annotated with
  route attributes (`#[Get]`, `#[Post]`, etc.).
- `withRouteCache(CacheInterface $cache, ?string $key): self` — caches compiled routes
  in a PSR-16 store. Closure routes are always excluded from the cache.

### Compile & introspect

- `compile(): CompiledHttpApp` — freezes the DSL into a PSR-15 handler.
- `registeredRoutes(): list<RouteSummary>` — returns all registered routes; useful for
  admin pages and smoke tests.

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Http-Dsl-HttpApp.html)

## See also

- [HTTP getting started](../../http/getting-started) — end-to-end setup guide
- [HTTP routing](../../http/routing) — path parameters, groups, attribute discovery
- [HTTP handlers](../../http/handlers) — closure vs class-based handler patterns
- [RouteBuilder](route-builder) — per-route middleware and naming
- [CompiledHttpApp](compiled-http-app) — the PSR-15 handler produced by `compile()`
