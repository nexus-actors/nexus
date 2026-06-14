---
sidebar_position: 5
title: Middleware
---

# Middleware

Standard PSR-15. A middleware is anything that implements
`Psr\Http\Server\MiddlewareInterface`:

```php
interface MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface;
}
```

You receive the request, decide what to do, and either short-circuit with
your own response or pass control to the next handler via
`$handler->handle($request)`.

## Registration

Three scopes, evaluated outermost to innermost:

```php
$app->middleware(RequestIdMiddleware::class)              // global
    ->group('/api', static function ($g) {
        $g->middleware(ApiKeyMiddleware::class);          // group
        $g->get('/orders', ListHandler::class)
            ->middleware(RateLimitMiddleware::class);     // per-route
    });
```

The pipeline for `GET /api/orders` becomes:

```
RequestIdMiddleware
  └─ ApiKeyMiddleware
       └─ RateLimitMiddleware
            └─ ListHandler
```

Each middleware decides whether to call `$handler->handle($request)`.
Short-circuiting (returning early without delegating) skips everything
inside.

## Two Ways to Pass Middleware

```php
// Class-string — resolved from the PSR-11 container at compile time
$app->middleware(AuthMiddleware::class);

// Pre-instantiated — useful when middleware needs runtime config
$app->middleware(new RateLimitMiddleware($limiter, $bucketSize));
```

Class-string registration costs zero per-request — the same instance is
reused. Use the instance form only when the middleware genuinely needs
runtime state.

## Writing Middleware

A minimal request-ID stamper:

```php
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $requestId = $request->getHeaderLine('X-Request-Id')
            ?: bin2hex(random_bytes(8));

        $request = $request->withAttribute('requestId', $requestId);
        $response = $handler->handle($request);

        return $response->withHeader('X-Request-Id', $requestId);
    }
}
```

Three things to notice:

1. **Mutate before** by re-binding `$request = $request->withAttribute(...)`.
   PSR-7 messages are immutable; the `with*` methods return new instances.
2. **Pass control** by calling `$handler->handle($request)`. Anything
   you don't return is dropped.
3. **Mutate after** by chaining `->withHeader(...)` on the returned
   response. Same immutability rules apply.

## Common Patterns

### Authentication

```php
final class BearerAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly TokenVerifier $verifier) {}

    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
    {
        $token = $this->extractBearer($req->getHeaderLine('Authorization'));

        if ($token === null) {
            return Response::badRequest('missing bearer token');
        }

        try {
            $principal = $this->verifier->verify($token);
        } catch (InvalidTokenException) {
            return JsonResponse::ok(['error' => 'invalid token'], status: 401);
        }

        return $next->handle($req->withAttribute('principal', $principal));
    }

    private function extractBearer(string $header): ?string { /* … */ }
}
```

Downstream handlers read `$req->getAttribute('principal')` to access the
authenticated identity.

### CORS

For any non-trivial CORS handling, install a battle-tested PSR-15
middleware (e.g. `tuupola/cors-middleware`) and register it globally:

```php
$app->middleware(new \Tuupola\Middleware\CorsMiddleware([
    'origin'  => ['https://app.example.com'],
    'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    'headers.allow' => ['Authorization', 'Content-Type'],
]));
```

Any PSR-15 middleware from the wider ecosystem composes cleanly with
Nexus — there is no Nexus-specific extension point.

### Rate Limiting

```php
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly int $perMinute = 60,
    ) {}

    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
    {
        $key = $req->getAttribute('principal')?->id() ?? $req->getServerParams()['REMOTE_ADDR'] ?? 'anon';

        if (!$this->limiter->take($key, $this->perMinute)) {
            return Response::serviceUnavailable(Duration::seconds(60))
                ->withHeader('X-RateLimit-Limit', (string) $this->perMinute);
        }

        return $next->handle($req);
    }
}
```

For per-route limits, register the middleware on the route, not globally:

```php
$app->post('/orders', CreateOrderHandler::class)
    ->middleware(new RateLimitMiddleware($limiter, perMinute: 10));
```

### Logging

Wrap the entire pipeline to log every request:

```php
final class AccessLogMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $log) {}

    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
    {
        $start = hrtime(true);
        $response = $next->handle($req);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $this->log->info('{method} {path} → {status} ({ms}ms)', [
            'method' => $req->getMethod(),
            'path'   => $req->getUri()->getPath(),
            'status' => $response->getStatusCode(),
            'ms'     => round($elapsedMs, 2),
        ]);

        return $response;
    }
}
```

Pair with [MDC](./observability.md#mdc) so per-request metadata flows
into every downstream log line, not just the access log.

### Exception Translation Inside Middleware

You can convert specific exceptions to responses directly in middleware:

```php
final class DomainErrorMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
    {
        try {
            return $next->handle($req);
        } catch (DomainException $e) {
            return JsonResponse::ok(['error' => $e->getMessage()], status: 422);
        }
    }
}
```

Most of the time `$app->onException(...)` is cleaner — it centralises the
mapping in one place. Middleware-level catches make sense when you need
access to the request (e.g. for correlation IDs or per-tenant routing).

## Built-In Middleware

`Monadial\Nexus\Http\Middleware\` ships the pieces the framework itself
uses:

| Class | Role |
|---|---|
| `RouterMiddleware` | Performs route matching; binds path parameters. Always last in the pipeline. |
| `MiddlewarePipeline` | Composes a list of middleware around a final handler. |
| `MiddlewareInvoker` | Decorates per-route middleware around the matched handler. |
| `MiddlewareResolver` | Resolves class-string middleware via the PSR-11 container. |
| `ExceptionHandlerMiddleware` | Wraps the entire pipeline with `ErrorMode` and `onException` translation. |

You rarely instantiate these directly — the compiled application wires
them in the correct order. They're public so you can replace them if you
need to.

## Pipeline Order in Full

Putting all three scopes together:

```
ExceptionHandlerMiddleware           ← always outermost (catches everything)
  Global middleware (registration order)
    Group middleware (registration order)
      Per-route middleware (registration order)
        RouterMiddleware              ← finds the matched handler
          Handler::__invoke()
```

The exception handler is outermost so it sees errors from every layer.
The router is innermost (just before the handler) because routing depends
on attributes possibly set by upstream middleware.

## Composition

```
HttpApplication
  ├── ->middleware(M)         (global)
  ├── ->group(...)->middleware(M)   (group-scoped)
  └── ->get(...)->middleware(M)     (per-route)
                    │
                    ▼
            CompiledApplication
                    │
                    ▼
          PSR-15 MiddlewarePipeline
                    │
                    ▼
               Handler
```

Next: [Responses](./responses.md), [Error Handling](./error-handling.md),
and the wider [Actors in HTTP](./actors-in-http.md) story.
