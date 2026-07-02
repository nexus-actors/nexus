---
sidebar_position: 6
title: Responses
related:
  - http/handlers
  - http/error-handling
  - http/actors-in-http
  - packages/http
---

# Responses

Handlers must return a `Psr\Http\Message\ResponseInterface`. Nexus ships two convenience factories — `Response` for status-only and plain bodies, `JsonResponse` for JSON — plus `StreamingResponse` for chunked output.

## `Response`

Status-only helpers cover the common cases:

```php title="src/Http/Handler/OrderHandler.php"
use Monadial\Nexus\Http\Response\Response;

Response::ok();                                       // 200, empty body
Response::noContent();                                // 204
Response::created('/orders/42');                      // 201 + Location
Response::badRequest('Invalid SKU');                  // 400, body = message
Response::notFound('Order not found');                // 404
Response::gatewayTimeout();                           // 504
Response::serviceUnavailable(Duration::seconds(60));  // 503 + Retry-After: 60
Response::internalServerError();                      // 500
```

Each returns a fully-formed PSR-7 response. Chain `->withHeader(...)` or `->withBody(...)` to customise:

```php title="src/Http/Handler/CreateOrderHandler.php"
return Response::created('/orders/42')
    ->withHeader('X-Trace-Id', $traceId);
```

## `JsonResponse`

```php title="src/Http/Handler/ListOrdersHandler.php"
use Monadial\Nexus\Http\Response\JsonResponse;

JsonResponse::ok(['items' => $orders]);
JsonResponse::created(['id' => 42], '/orders/42');
```

The body is JSON-encoded with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. The `Content-Type: application/json; charset=utf-8` header is set automatically. Override encoding flags:

```php title="src/Http/Handler/DebugHandler.php"
return JsonResponse::ok($data, JSON_PRETTY_PRINT);
```

For status codes outside the `ok` / `created` helpers, chain `->withStatus()`:

```php title="src/Http/Handler/CreateOrderHandler.php"
return JsonResponse::ok(['error' => 'validation', 'fields' => $errors])
    ->withStatus(422);
```

## `StreamingResponse`

For long-lived bodies — NDJSON exports, server-sent events, large file downloads — wrap an iterable:

```php title="src/Http/Handler/ExportOrdersHandler.php"
use Monadial\Nexus\Http\Response\StreamingResponse;

return new StreamingResponse(
    static function () use ($db) {
        foreach ($db->stream('SELECT * FROM events') as $row) {
            yield json_encode($row) . "\n";
        }
    },
    headers: ['Content-Type' => 'application/x-ndjson'],
);
```

The generator runs lazily. Each yielded string becomes a chunk on the wire. Memory stays constant regardless of total response size.

### Server-Sent Events

```php title="src/Http/Handler/EventStreamHandler.php"
return new StreamingResponse(
    static function () use ($eventBus) {
        foreach ($eventBus->subscribe() as $event) {
            yield "event: {$event->name}\n";
            yield "data: " . json_encode($event->payload) . "\n\n";
        }
    },
    headers: [
        'Content-Type'      => 'text/event-stream',
        'Cache-Control'     => 'no-cache',
        'X-Accel-Buffering' => 'no',
    ],
);
```

For interactive realtime, prefer [WebSockets](./websockets.md). SSE is useful when the client must be a plain browser without a WebSocket client.

## Redirects

There is no dedicated redirect helper. Build with status and `Location`:

```php title="src/Http/Handler/RedirectHandler.php"
// 302 redirect
return Response::ok()
    ->withStatus(302)
    ->withHeader('Location', '/orders');

// 301 permanent redirect
return Response::ok()
    ->withStatus(301)
    ->withHeader('Location', '/new-path');
```

`Response::created($url)` is a `201` with `Location` — not a redirect, but shares the pattern.

## Headers and status

Every PSR-7 method is available on the returned response. PSR-7 immutability rules apply — every `with*` method returns a new instance:

```php title="src/Http/Handler/ShowOrderHandler.php"
return JsonResponse::ok(['id' => 42])
    ->withStatus(201)
    ->withHeader('X-Trace-Id', $traceId)
    ->withHeader('Cache-Control', 'no-store')
    ->withAddedHeader('Vary', 'Authorization');
```

## Asynchronous responses

Handlers can return a `Future<ResponseInterface>`. The router awaits the future before serialising the response:

```php title="src/Http/Handler/ShowOrderHandler.php"
use Monadial\Nexus\Runtime\Async\Future;

public function __invoke(ServerRequestInterface $req): Future
{
    return $this->orders
        ->ask(new GetOrder($req->getAttribute('id')), Duration::seconds(2))
        ->map(static fn($order) => JsonResponse::ok($order->toArray()));
}
```

This lets you compose async pipelines with `map` / `flatMap` and let the router handle the await. See [Actors in HTTP](./actors-in-http.md) for the full pattern.

## Custom responses

Both factories return PSR-7 `ResponseInterface` instances backed by the host's PSR-7 implementation. Produce an entirely custom response with the implementation directly:

```php title="src/Http/Handler/PlainTextHandler.php"
use Laminas\Diactoros\Response\TextResponse;

return new TextResponse('plain text body', 200);
```

## See also

- [Error Handling](./error-handling.md) — mapping exceptions to responses.
- [Actors in HTTP](./actors-in-http.md) — `Future`-returning handlers.
- [WebSockets](./websockets.md) — `$ctx->send()` for push responses.
