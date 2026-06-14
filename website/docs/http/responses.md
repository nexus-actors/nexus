---
sidebar_position: 6
title: Responses
---

# Responses

Handlers must return a `Psr\Http\Message\ResponseInterface`. Nexus ships
two convenience factories — `Response` for status-only and plain bodies,
`JsonResponse` for JSON — plus `StreamingResponse` for chunked output. For
anything else, build a response directly with your PSR-7 implementation.

## `Response`

Status-only helpers cover the common cases:

```php
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

Each returns a fully-formed PSR-7 response — pass it back to the caller,
or chain `->withHeader(...)` / `->withBody(...)` to customise.

```php
return Response::created('/orders/42')
    ->withHeader('X-Trace-Id', $traceId);
```

## `JsonResponse`

```php
use Monadial\Nexus\Http\Response\JsonResponse;

JsonResponse::ok(['items' => $orders]);
JsonResponse::created(['id' => 42], '/orders/42');
```

The body is JSON-encoded with
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` and the
`Content-Type: application/json; charset=utf-8` header is set
automatically. Override the encoding flags:

```php
return JsonResponse::ok($data, JSON_PRETTY_PRINT);
```

### Non-200 JSON

For status codes outside the `ok` / `created` helpers, pass `status` to a
plain `JsonResponse::ok(...)` and rebuild:

```php
return JsonResponse::ok(['error' => 'validation', 'fields' => $errors])
    ->withStatus(422);
```

Or use `Response` for the status and append a JSON body manually — but in
practice, prefer mapping exceptions to responses in `onException()` so
your handler stays focused on success paths.

## `StreamingResponse`

For long-lived bodies (NDJSON exports, server-sent events, large file
downloads), wrap an iterable:

```php
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

The generator runs lazily — each `yield`ed string becomes a chunk on the
wire. Yielding does not buffer; for Swoole the chunk is flushed
immediately. Memory stays constant regardless of total response size.

### Server-Sent Events

```php
return new StreamingResponse(
    static function () use ($eventBus) {
        foreach ($eventBus->subscribe() as $event) {
            yield "event: {$event->name}\n";
            yield "data: " . json_encode($event->payload) . "\n\n";
        }
    },
    headers: [
        'Content-Type'  => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'X-Accel-Buffering' => 'no',
    ],
);
```

For interactive realtime, prefer [WebSockets](./websockets.md) — SSE is
useful when the client must be a plain browser without a WebSocket
client.

## Redirects

There's no dedicated helper — build with status + `Location`:

```php
return Response::created($url);   // 201 with Location

// 302 redirect
return Response::ok()
    ->withStatus(302)
    ->withHeader('Location', '/orders');

// 301 permanent
return Response::ok()
    ->withStatus(301)
    ->withHeader('Location', '/new-path');
```

## Custom Responses

Both factories return PSR-7 `ResponseInterface` instances backed by the
host's PSR-7 implementation. To produce an entirely custom response, use
the implementation directly:

```php
use Laminas\Diactoros\Response\TextResponse;

return new TextResponse('plain text body', 200);
```

Or wire whichever PSR-7 implementation your project uses.

## Headers and Status

Every PSR-7 method is available on the returned response:

```php
return JsonResponse::ok(['id' => 42])
    ->withStatus(201)
    ->withHeader('X-Trace-Id', $traceId)
    ->withHeader('Cache-Control', 'no-store')
    ->withAddedHeader('Vary', 'Authorization');
```

PSR-7 immutability rules apply — every `with*` method returns a new
instance. Chain or rebind:

```php
$response = JsonResponse::ok($data);
$response = $response->withHeader('X-Trace-Id', $traceId);
return $response;
```

## Asynchronous Responses

Handlers can also return a `Future<ResponseInterface>`. The router awaits
the future before serialising the response:

```php
use Monadial\Nexus\Runtime\Async\Future;

public function __invoke(ServerRequestInterface $req): Future
{
    return $this->orders
        ->askFuture(fn($reply) => new GetOrder($req->getAttribute('id'), $reply))
        ->map(static fn($order) => JsonResponse::ok($order->toArray()));
}
```

This lets you compose async pipelines with `map` / `flatMap` and let the
router handle the await. See [Actors in HTTP](./actors-in-http.md#future-returning-handlers)
for the full pattern.

## Composition

```
Handler::__invoke()
  ├── return Response::ok()                ┐
  ├── return JsonResponse::ok($data)       ├→ ResponseInterface (PSR-7)
  ├── return new StreamingResponse(…)      │
  └── return $future                       ┘  ← awaited by RouterMiddleware
                                                before the pipeline returns
              │
              ▼
       PSR-15 pipeline
       (middleware chains may decorate further)
              │
              ▼
       Server adapter writes to socket
```

Next: [Error Handling](./error-handling.md) for the mapping from
exceptions to responses, or [Actors in HTTP](./actors-in-http.md) for the
Future return pattern in depth.
