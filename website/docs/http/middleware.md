---
sidebar_position: 5
title: Middleware
related:
  - http/routing
  - http/handlers
  - http/error-handling
  - packages/http
---

# Middleware

Standard PSR-15. A middleware is anything that implements `Psr\Http\Server\MiddlewareInterface`, receives the request, and either short-circuits with its own response or passes control to the next handler.

## Registration

Three scopes, evaluated outermost to innermost:

```php title="server.php"
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

Each middleware decides whether to call `$handler->handle($request)`. Short-circuiting skips everything inside.

## Two ways to pass middleware

```php title="server.php"
// Class-string — resolved from the PSR-11 container at compile time
$app->middleware(AuthMiddleware::class);

// Pre-instantiated — useful when middleware needs runtime config
$app->middleware(new RateLimitMiddleware($limiter, $bucketSize));
```

Class-string registration reuses the same instance for every request. Use the instance form only when the middleware needs runtime-configured state.

## Writing middleware

A minimal request-ID stamper:

```php title="src/Http/Middleware/RequestIdMiddleware.php"
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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

        $request  = $request->withAttribute('requestId', $requestId);
        $response = $handler->handle($request);

        return $response->withHeader('X-Request-Id', $requestId);
    }
}
```

Three things to notice:

1. Re-bind `$request = $request->withAttribute(...)` before passing. PSR-7 messages are immutable; `with*` methods return new instances.
2. Call `$handler->handle($request)` to pass control. Anything you don't return is dropped.
3. Chain `->withHeader(...)` on the returned response to decorate after the fact.

## Common patterns

### Authentication

```php title="src/Http/Middleware/BearerAuthMiddleware.php"
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

For JWT-based auth, prefer [`nexus-http-auth`](./auth.md) — it handles token extraction, validation, and `#[FromPrincipal]` injection out of the box.

### CORS

For any non-trivial CORS handling, install a battle-tested PSR-15 middleware and register it globally:

```php title="server.php"
$app->middleware(new \Tuupola\Middleware\CorsMiddleware([
    'origin'         => ['https://app.example.com'],
    'methods'        => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    'headers.allow'  => ['Authorization', 'Content-Type'],
]));
```

Any PSR-15 middleware from the wider ecosystem composes cleanly with Nexus — there is no Nexus-specific extension point.

### Rate limiting

```php title="src/Http/Middleware/RateLimitMiddleware.php"
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly int $perMinute = 60,
    ) {}

    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
    {
        $key = $req->getAttribute('principal')?->id()
            ?? $req->getServerParams()['REMOTE_ADDR']
            ?? 'anon';

        if (!$this->limiter->take($key, $this->perMinute)) {
            return Response::serviceUnavailable(Duration::seconds(60))
                ->withHeader('X-RateLimit-Limit', (string) $this->perMinute);
        }

        return $next->handle($req);
    }
}
```

For per-route limits, register on the route rather than globally:

```php title="server.php"
$app->post('/orders', CreateOrderHandler::class)
    ->middleware(new RateLimitMiddleware($limiter, perMinute: 10));
```

### Logging

```php title="src/Http/Middleware/AccessLogMiddleware.php"
final class AccessLogMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $log) {}

    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
    {
        $start    = hrtime(true);
        $response = $next->handle($req);
        $elapsed  = (hrtime(true) - $start) / 1_000_000;

        $this->log->info('{method} {path} → {status} ({ms}ms)', [
            'method' => $req->getMethod(),
            'path'   => $req->getUri()->getPath(),
            'status' => $response->getStatusCode(),
            'ms'     => round($elapsed, 2),
        ]);

        return $response;
    }
}
```

### Exception translation

You can convert specific exceptions to responses directly in middleware when you need access to the request (correlation IDs, per-tenant routing):

```php title="src/Http/Middleware/DomainErrorMiddleware.php"
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

Most of the time `$app->onException(...)` is cleaner — it centralises the mapping in one place. See [Error Handling](./error-handling.md).

## Built-in middleware

`Monadial\Nexus\Http\Middleware\` ships the pieces the framework itself uses:

| Class | Role |
|---|---|
| `RouterMiddleware` | Performs route matching; binds path parameters. Always last in the pipeline. |
| `MiddlewarePipeline` | Composes a list of middleware around a final handler. |
| `MiddlewareInvoker` | Decorates per-route middleware around the matched handler. |
| `MiddlewareResolver` | Resolves class-string middleware via the PSR-11 container. |
| `ExceptionHandlerMiddleware` | Wraps the entire pipeline with `ErrorMode` and `onException` translation. |

You rarely instantiate these directly — the compiled application wires them in the correct order. They are public so you can replace them when needed.

## Pipeline order

```
ExceptionHandlerMiddleware           ← always outermost (catches everything)
  Global middleware (registration order)
    Group middleware (registration order)
      Per-route middleware (registration order)
        RouterMiddleware              ← finds the matched handler
          Handler::__invoke()
```

The exception handler is outermost so it sees errors from every layer. The router is innermost — just before the handler — because routing depends on attributes possibly set by upstream middleware.

## See also

- [Routing](./routing.md) — per-group and per-route middleware.
- [Error Handling](./error-handling.md) — the `onException` alternative to try/catch in middleware.
- [Auth](./auth.md) — `AuthenticationMiddleware` and `AuthorizationMiddleware`.
