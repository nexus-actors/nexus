---
title: nexus-http-toolkit
related:
  - packages/http
  - http/middleware
  - packages/logger
---

# nexus-http-toolkit

Production middleware and testing utilities that complement the core HTTP package: structured access logging, W3C trace context propagation, request body size limiting, and an in-process test client.

## Install

```bash title="terminal"
composer require nexus-actors/http-toolkit
```

## Bundled components

### Middleware

| Class | Purpose |
|---|---|
| `AccessLogMiddleware` | Emits one PSR-3 log line per request: method, path, status, response size, and latency in ms |
| `BodySizeLimitMiddleware` | Rejects bodies exceeding a byte limit with `413 Payload Too Large` |
| `TraceContextMiddleware` | Parses/generates W3C `traceparent` headers; pushes `traceId`/`spanId` into MDC |

### Health checks

| Class | Purpose |
|---|---|
| `HealthCheck` | Interface — implement `name(): string` and `check(): HealthStatus` |
| `HealthCheckRegistry` | Aggregates `HealthCheck` implementations; iterable at request time |
| `HealthCheckHandler` | Invokable handler — mount on a route to serve `/health` |
| `HealthStatus` | Value object: `up(array $detail)`, `degraded(array $detail)`, `down(array $detail)` |
| `State` | Enum: `Up`, `Degraded`, `Down` |

### Test utilities

| Class | Purpose |
|---|---|
| `HttpTestClient` | In-process test client — dispatches requests through a `CompiledApplication` with no socket |
| `TestResponse` | Fluent assertion wrapper: `assertOk()`, `assertStatus()`, `assertJsonPath()` |

## Quick example: wiring middleware

Register `AccessLogMiddleware` as the outermost layer and `BodySizeLimitMiddleware` just inside it so oversized bodies are rejected before the body parser runs.

```php title="src/Http/Bootstrap.php"
use Monadial\Nexus\Http\Toolkit\Middleware\AccessLogMiddleware;
use Monadial\Nexus\Http\Toolkit\Middleware\BodySizeLimitMiddleware;
use Monadial\Nexus\Http\Toolkit\Middleware\TraceContextMiddleware;

$app = HttpApplication::create($system)
    ->middleware(new AccessLogMiddleware($logger))
    ->middleware(new TraceContextMiddleware())
    ->middleware(new BodySizeLimitMiddleware(maxBytes: 10 * 1024 * 1024))
    ->get('/orders', ListOrdersHandler::class)
    ->post('/orders', CreateOrderHandler::class);
```

`TraceContextMiddleware` sets `trace.id`, `trace.parentSpanId`, and `trace.spanId` as request attributes and writes the corresponding `traceparent` response header. If `nexus-actors/logger` is installed the IDs are also pushed into MDC so every log line inside the request carries them automatically.

## Health check endpoint

```php title="src/Http/Bootstrap.php"
use Monadial\Nexus\Http\Toolkit\Health\HealthCheckHandler;
use Monadial\Nexus\Http\Toolkit\Health\HealthCheckRegistry;

$registry = (new HealthCheckRegistry())
    ->add(new DatabaseHealthCheck($pdo))
    ->add(new RedisHealthCheck($redis));

$app = HttpApplication::create($system)
    ->get('/health', new HealthCheckHandler($registry));
```

The handler returns `200` when all checks are `Up` or `Degraded`, and `503` when any check is `Down`. The response body follows an RFC Health JSON-inspired shape:

```json title="GET /health — example response"
{
  "status": "degraded",
  "checks": {
    "database": { "state": "up",       "detail": { "latencyMs": 1.2 } },
    "redis":    { "state": "degraded", "detail": { "latencyMs": 48.9 } }
  }
}
```

A check that throws is treated as `Down`; the exception class becomes the `error` detail. `HealthCheckHandler` itself never throws.

## In-process testing

`HttpTestClient` drives a `CompiledApplication` without a real socket, making HTTP tests fast and deterministic.

```php title="tests/Integration/OrdersApiTest.php"
use Monadial\Nexus\Http\Toolkit\Test\HttpTestClient;

$app = HttpApplication::create($system)
    ->get('/orders/{id}', ShowOrderHandler::class)
    ->compile();

$client = HttpTestClient::for($app)
    ->withBearerToken('test-token');

$response = $client->get('/orders/42');

$response->assertOk()->assertJsonPath('id', '42');
```

Pair `HttpTestClient` with `StepRuntime` and call `$runtime->drain()` between requests for fully deterministic actor-driven tests.

## BodySizeLimitMiddleware constructor

```php title="src/Http/Bootstrap.php"
// Global 10 MB limit
$app->middleware(new BodySizeLimitMiddleware(maxBytes: 10 * 1024 * 1024));

// Per-route 100 MB limit for upload endpoint
$app->post('/upload', UploadHandler::class)
    ->middleware(new BodySizeLimitMiddleware(maxBytes: 100 * 1024 * 1024));
```

The middleware trusts `Content-Length` for upfront rejection. For streaming/chunked bodies it falls back to `getSize()` after the body is read. Register it outside any body parser so oversized bodies never reach JSON decoding.

Pass a custom `ResponseFactoryInterface` and `StreamFactoryInterface` when your application uses a different PSR-17 implementation:

```php title="src/Http/Bootstrap.php"
new BodySizeLimitMiddleware(
    maxBytes: 5 * 1024 * 1024,
    responseFactory: $myResponseFactory,
    streamFactory: $myStreamFactory,
);
```

## See also

- [HTTP middleware](../http/middleware.md) — middleware pipeline and execution order
- [nexus-http package](http.md) — routing, handlers, and the application interface
- [nexus-logger package](logger.md) — MDC and structured logging
