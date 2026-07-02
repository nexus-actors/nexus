---
sidebar_position: 7
title: Error Handling
related:
  - http/handlers
  - http/middleware
  - http/actors-in-http
  - packages/http
---

# Error Handling

Errors in Nexus HTTP follow a single flow: handlers throw, middleware catches, mappers translate, the response is written. There is no parallel error channel.

## Default behaviour

An unhandled exception in a handler becomes a `500 Internal Server Error` with a generic message. The full exception is logged via the configured PSR-3 logger but never exposed on the wire.

```php title="server.php"
$app->get('/boom', static function () {
    throw new RuntimeException('something broke');
});
// → 500 Internal Server Error
// → log: "Unhandled exception in handler" with stack trace
```

## ErrorMode

Two modes control how unmapped exceptions are serialised:

```php title="server.php"
use Monadial\Nexus\Http\App\ErrorMode;

$app->errorMode(ErrorMode::Production);   // default
$app->errorMode(ErrorMode::Development);
```

| Mode | Body of an unmapped exception |
|---|---|
| `ErrorMode::Production` | `{"error":"Internal Server Error"}` (sanitised) |
| `ErrorMode::Development` | Full message, class, and stack trace as JSON |

`ErrorMode::Production` is the default. Set `ErrorMode::Development` for local development — never in production deploys; stack traces leak internal structure.

## Mapping domain exceptions

Register a mapper from exception class to response with `onException()`:

```php title="server.php"
$app->onException(OrderNotFoundException::class, static function (OrderNotFoundException $e) {
    return Response::notFound($e->getMessage());
});

$app->onException(ValidationException::class, static function (ValidationException $e) {
    return JsonResponse::ok(['errors' => $e->errors()])->withStatus(422);
});

$app->onException(RateLimitedException::class, static function (RateLimitedException $e) {
    return Response::serviceUnavailable($e->retryAfter());
});
```

Mappers are looked up by exact class first, then by ancestor in reverse declaration order. The first match wins.

Map a base class once and let subclasses inherit:

```php title="server.php"
$app->onException(DomainException::class, static fn(DomainException $e) =>
    JsonResponse::ok(['error' => $e->getMessage()])->withStatus(400));
```

Every subclass of `DomainException` hits this mapper unless a more specific one is registered first.

## Mapping built-in exceptions

The framework throws three exceptions you can customise:

```php title="server.php"
use Monadial\Nexus\Http\Exception\{HandlerNotFoundException, MethodNotAllowedException, NotFoundException};

$app->onException(NotFoundException::class, static fn() => JsonResponse::ok([
    'error' => 'route not found',
    'docs'  => 'https://api.example.com/docs',
])->withStatus(404));

$app->onException(MethodNotAllowedException::class, static fn(MethodNotAllowedException $e) =>
    Response::ok()->withStatus(405)->withHeader('Allow', implode(', ', $e->allowed())));
```

| Exception | Default response |
|---|---|
| `NotFoundException` | `404 Not Found` |
| `MethodNotAllowedException` | `405 Method Not Allowed` with `Allow` header |
| `HandlerNotFoundException` | `500 Internal Server Error` (configuration bug) |

## Mapping validation errors

Collect field errors into a structured payload:

```php title="src/Http/Handler/CreateOrderHandler.php"
public function __invoke(ServerRequestInterface $req, #[FromBody] CreateOrderDto $dto): ResponseInterface
{
    $errors = $this->validator->validate($dto);

    if ($errors !== []) {
        throw new ValidationException($errors);
    }
    // …
}
```

```php title="server.php"
$app->onException(ValidationException::class, static fn(ValidationException $e) =>
    JsonResponse::ok([
        'error'  => 'validation',
        'fields' => $e->errors,
    ])->withStatus(422));
```

The handler stays focused on the happy path; serialisation is centralised in the mapper.

## Mapping actor errors

`AskTimeoutException` from a slow actor reply and `WriterConflictException` from event-sourced persistence translate like any other exception:

```php title="server.php"
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Persistence\Exception\WriterConflictException;

$app->onException(AskTimeoutException::class, static fn() => Response::gatewayTimeout());
$app->onException(WriterConflictException::class, static fn() => JsonResponse::ok([
    'error' => 'conflict, retry',
])->withStatus(409));
```

This decouples your HTTP layer from your actor layer's failure modes — actors don't know what `504` means, and they don't need to.

## Disabling the default handler

If you need to assemble the exception middleware yourself:

```php title="server.php"
$app->withoutDefaultExceptionHandler()
    ->middleware(MyCustomExceptionMiddleware::class);
```

You are now responsible for catching `Throwable` at the top of the pipeline. Use this only for specific requirements (Sentry-flavoured error reports, OpenTelemetry spans tied to exceptions).

## Exceptions vs error responses

Throw exceptions for abnormal conditions:

- Resource not found (`OrderNotFoundException`)
- Validation failure (`ValidationException`)
- Authorisation failure (`UnauthorizedException`)
- Upstream timeout (`AskTimeoutException`)

Return an explicit response for normal outcomes:

- Empty result set → `JsonResponse::ok([])`
- Idempotent retry of an already-completed action → `Response::ok()`
- Conditional GET matching `If-None-Match` → `Response::noContent()->withStatus(304)`

## See also

- [Handlers](./handlers.md) — letting exceptions propagate vs early returns.
- [Middleware](./middleware.md) — exception translation inside middleware.
- [Actors in HTTP](./actors-in-http.md) — handling `AskTimeoutException` locally.
