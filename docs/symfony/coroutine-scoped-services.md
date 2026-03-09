# Coroutine-scoped services

## The problem: shared state under Swoole

In a standard PHP-FPM setup, each request runs in a separate OS process. Any object stored in a static property or a service container is private to that process. Under Swoole, multiple requests execute concurrently within the same process using coroutines. A service created once and stored in the Symfony container is shared across all concurrent requests — which means request-specific data (request ID, authenticated user, elapsed time, active transaction) leaks between requests.

The solution is to store per-request data in Swoole's coroutine context (`Coroutine::getContext()`). Each Swoole coroutine has its own context `ArrayObject`, similar to thread-local storage. Data written to it is invisible to all other coroutines.

## #[CoroutineScoped]

The `#[CoroutineScoped]` attribute marks a service as needing a fresh instance per coroutine (per request). It is a PHP 8 attribute defined in `Monadial\Nexus\Symfony\Attribute\CoroutineScoped`.

```php
use Monadial\Nexus\Symfony\Attribute\CoroutineScoped;

#[CoroutineScoped]
final class RequestContext
{
    public readonly string $requestId;
    public readonly float $startedAt;

    public function __construct()
    {
        $this->requestId = (string) new Ulid();
        $this->startedAt = microtime(true);
    }

    public function elapsedMs(): float
    {
        return (microtime(true) - $this->startedAt) * 1000;
    }
}
```

## How it works internally

### Compile time: CoroutineScopedPass

`CoroutineScopedPass` is a Symfony compiler pass that:

1. Finds all service definitions whose class carries the `#[CoroutineScoped]` attribute.
2. Marks each definition as **non-shared** (`setShared(false)`) so `$container->get(ServiceId::class)` always returns a new instance (prototype scope).
3. Collects all their service IDs into a `ServiceLocator` registered as `nexus.coroutine_scoped_locator`.
4. Stores the list of IDs in the `nexus.coroutine_scoped_services` container parameter.

Setting the service as non-shared is the critical step: each call to the service locator produces a fresh object.

### Request time: CoroutineScopeListener

`CoroutineScopeListener` listens on `KernelEvents::REQUEST` at priority 1000 (before almost everything else). On each main request it:

1. Iterates the services provided by `nexus.coroutine_scoped_locator`.
2. For each service, stores a factory closure (calling `$locator->get($id)`) in a local map.
3. Calls `CoroutineScope::initialize($factories)`, which executes every factory and stores the resulting instances in the current coroutine's context under the key `__nexus_scope__`.

All instances are created at the start of the request and live until the coroutine ends. There is no lazy resolution.

### Accessing scoped services

`CoroutineScope::get(string $id)` reads from the coroutine context:

```php
// Internal to the framework — normally accessed via injection, not directly.
$requestContext = $scope->get(RequestContext::class);
```

Because the instances are stored in `Coroutine::getContext()`, each concurrent request has its own isolated copies.

### SwooleCoroutineContext

`SwooleCoroutineContext` implements `CoroutineContext` by delegating to `Coroutine::getContext()`:

```php
final class SwooleCoroutineContext implements CoroutineContext
{
    public function current(): ArrayObject
    {
        return Coroutine::getContext();
    }
}
```

This is the single seam between the Nexus framework code and Swoole's coroutine API. For testing outside Swoole (e.g., in unit tests with `FiberRuntime`), a different `CoroutineContext` implementation can be substituted.

## RequestContext example

`RequestContext` in the symfony-demo demonstrates the pattern end-to-end:

```php
#[CoroutineScoped]
final class RequestContext
{
    public readonly string $requestId;
    public readonly float $startedAt;

    public function __construct()
    {
        $this->requestId = (string) new Ulid();
        $this->startedAt = microtime(true);
    }

    public function elapsedMs(): float
    {
        return (microtime(true) - $this->startedAt) * 1000;
    }
}
```

Injection into a controller works via standard autowiring:

```php
final class CatalogController extends AbstractController
{
    #[Route('/catalog', methods: ['GET'])]
    public function list(RequestContext $ctx): Future
    {
        // $ctx is unique to this request coroutine.
        $requestId = $ctx->requestId;
        // ...
    }
}
```

Because `RequestContext` is non-shared, the Symfony DI container creates a new instance on each `get()` call. The `CoroutineScopeListener` calls `get()` once at the start of every request and stores the result in the coroutine context. Subsequent injections within the same request resolve the same instance from the coroutine context.

## What services should be #[CoroutineScoped]

Mark a service `#[CoroutineScoped]` when it:

- Holds request-specific state (request ID, user identity, active locale).
- Wraps a stateful connection borrowed per-request from a pool (e.g., a PDO wrapper backed by `SwoolePoolMiddleware`).
- Tracks timing or metrics for a single request.
- Must not share state with concurrent requests.

Do not mark services `#[CoroutineScoped]` when they are genuinely stateless — unnecessary prototype scoping adds allocation overhead.

## Coroutine context vs Symfony request scope

Symfony's built-in request scope (`request` scope in older versions, or using `RequestStack`) is not coroutine-safe under Swoole: the `RequestStack` is a shared service and concurrent requests would overwrite each other's stack frame. `#[CoroutineScoped]` is the Nexus replacement for request-scoped services in a Swoole environment.
