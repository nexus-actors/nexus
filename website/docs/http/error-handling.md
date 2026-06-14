---
sidebar_position: 7
title: Error Handling
---

# Error Handling

Errors in Nexus HTTP follow a single flow: handlers throw, middleware
catches, mappers translate, the response is written. There is no parallel
error channel.

## The Default Behaviour

If you don't configure anything, an unhandled exception in a handler
becomes a `500 Internal Server Error` with a generic message. The full
exception is logged via the configured PSR-3 logger (if any), but never
exposed on the wire.

```php
$app->get('/boom', static function () {
    throw new RuntimeException('something broke');
});
// → 500 Internal Server Error
// → log: "Unhandled exception in handler" with stack trace
```

That's safe by default. Now refine.

## ErrorMode

Two modes control how unmapped exceptions are serialised:

```php
use Monadial\Nexus\Http\App\ErrorMode;

$app->errorMode(ErrorMode::Production);   // default
$app->errorMode(ErrorMode::Development);
```

| Mode | Body of an unmapped exception |
|---|---|
| `ErrorMode::Production` | `{"error":"Internal Server Error"}` (sanitized) |
| `ErrorMode::Development` | Full message + class + stack trace as JSON |

**Production** is the default. Pick **Development** for local
development — but never in production deploys; stack traces leak
internal structure to attackers.

## Mapping Domain Exceptions

The interesting work happens in `onException()`. Register a mapper from
exception class to response:

```php
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

Mappers are looked up by exact class first, then by ancestor (parent
class, interface) in reverse declaration order. The first match wins;
remaining mappers are not consulted.

## Mapper Resolution Order

```
catch (Throwable $e) {
    foreach (registered_mappers as $class => $mapper) {
        if ($e instanceof $class) {
            return $mapper($e);   // first match wins
        }
    }
    // Fall through:
    if (ErrorMode::Development) {
        return JsonResponse with full details;
    } else {
        log $e;
        return Response::internalServerError();
    }
}
```

So you can map a base class once and let subclasses inherit:

```php
$app->onException(DomainException::class, static fn(DomainException $e) =>
    JsonResponse::ok(['error' => $e->getMessage()])->withStatus(400));
```

Every subclass of `DomainException` hits this mapper unless a more
specific one is registered first.

## Mapping Built-in Exceptions

The framework throws three exceptions you might want to customise:

```php
use Monadial\Nexus\Http\Exception\{NotFoundException, MethodNotAllowedException, HandlerNotFoundException};

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

## Mapping Validation Errors

A common pattern — collect field errors into a structured payload:

```php
final class ValidationException extends RuntimeException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('validation failed');
    }
}

$app->onException(ValidationException::class, static fn(ValidationException $e) =>
    JsonResponse::ok([
        'error'  => 'validation',
        'fields' => $e->errors,
    ])->withStatus(422));

// In the handler:
public function __invoke(ServerRequestInterface $req, #[FromBody] CreateOrderDto $dto): ResponseInterface
{
    $errors = $this->validator->validate($dto);

    if ($errors !== []) {
        throw new ValidationException($errors);
    }
    // …
}
```

Handler stays focused on the happy path; serialisation is centralised.

## Mapping Actor Errors

`AskTimeoutException` from a slow actor reply, `WriterConflictException`
from event-sourced persistence — translate them like any other:

```php
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Persistence\Exception\WriterConflictException;

$app->onException(AskTimeoutException::class, static fn() => Response::gatewayTimeout());
$app->onException(WriterConflictException::class, static fn() => JsonResponse::ok([
    'error' => 'conflict, retry',
])->withStatus(409));
```

This is how you decouple your HTTP layer from your actor layer's failure
modes — actors don't know what 504 means, and they don't need to.

## Disabling the Default Handler

If you'd rather assemble the exception middleware yourself, drop the
built-in:

```php
$app->withoutDefaultExceptionHandler()
    ->middleware(MyCustomExceptionMiddleware::class);
```

You're now responsible for catching `Throwable` at the top of the
pipeline. Use this only if you have specific requirements
(Sentry-flavoured error reports, OpenTelemetry spans tied to exceptions,
etc.).

## Exceptions vs Error Responses

Throw exceptions for **abnormal** conditions:

- Resource not found (`OrderNotFoundException`)
- Validation failure (`ValidationException`)
- Authorisation failure (`UnauthorizedException`)
- Upstream timeout (`AskTimeoutException`)

Return early with an explicit response for **normal** outcomes:

- Empty result set → `JsonResponse::ok([])`
- Idempotent retry of an already-completed action → `Response::ok()`
- Conditional GET with `If-None-Match` matching → `Response::noContent()->withStatus(304)`

Mixing the two patterns is fine; pick by whether the handler's
"success" code path produces the response. Anything outside that
code path is an exception.

## Composition

```
Handler::__invoke() ──→ throws DomainException
                              │
                              ▼
        ExceptionHandlerMiddleware (outermost)
                              │
       ┌──────────────────────┴────────────────────────┐
       │ for each onException mapper (registration order):
       │   if $e instanceof $registeredClass:
       │     return $mapper($e)
       └──────────────────────┬────────────────────────┘
                              │ fall through
                              ▼
                  ErrorMode::Production / Development
                              │
                              ▼
                       ResponseInterface
                              │
                              ▼
                    PSR-15 pipeline tail
                              │
                              ▼
                 Server adapter writes to socket
```

Up next: [WebSockets](./websockets.md), or jump to
[Observability](./observability.md) for how to log exceptions with full
context.
