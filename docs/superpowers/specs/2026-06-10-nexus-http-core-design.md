# nexus-http core — design

**Date:** 2026-06-10
**Status:** Approved for implementation planning
**Scope:** `packages/nexus-http` only. The Swoole HTTP server (`nexus-http-server-swoole`), the PSR-18 HTTP client (`nexus-http-client`), and async DBAL are out of scope for this spec — they will be brainstormed and specced separately.

---

## 1. Goal

A PSR-compliant HTTP framework that is:

- **Lightweight** — minimal dependency footprint (`nyholm/psr7` + `nikic/fast-route` + PSR interfaces).
- **Fast** — reflection-free hot path. Compile-time pipeline, file-based caches, no per-request allocations beyond what the request itself carries.
- **Actor-native** — first-class injection of `ActorRef<T>` into handlers and middleware, three lifecycle modes (pool-singleton, worker-local, per-request), full async via cooperative suspension and explicit `Future` composition.
- **PSR-15 on the wire** — every conventional PSR-15 middleware works as-is. No proprietary middleware contracts.
- **Beautiful DSL** — fluent builder primary, attribute discovery opt-in. Both compile to the same internal route model.

This package is runtime-agnostic. It defines contracts and the request pipeline; concrete HTTP servers consume `RequestHandlerInterface` and live in adjacent packages.

## 2. Decisions locked

| # | Decision |
|---|---|
| 1 | Handler ↔ actor model: handlers can either consume actors via injection (default) or *be* actor behaviors via a `Behavior<HttpRequest>`-backed route. Consume-actors default; behavior-handlers opt-in per route. |
| 2 | Lifecycle modes supported: pool-singleton, worker-local, per-request. Pool-of-N deferred to v2. |
| 3 | Handler async shape: both sync `ResponseInterface` (default, cooperative suspension makes it truly async) and `Future<ResponseInterface>` (opt-in for fan-out composition). Normalized at compile time. |
| 4 | DSL flavor: fluent builder primary; attribute discovery opt-in via `$app->discover(directory)`. Both produce identical `Route` value objects. |
| 5 | Actor injection: centralized registration at app boot (`$app->actor(name, props)->mode(...)`), referenced by handlers/middleware via `#[FromActor('name')]` attribute. Lifecycle is operational metadata; handler code is mode-agnostic. |
| 6 | Middleware model: plain PSR-15. No `AbstractActorMiddleware` convenience. Injection attribute works on any PSR-15 class. |
| 7 | PSR-7 implementation: depend on `nyholm/psr7`. We do not reimplement PSR-7. |
| 8 | Per-request actor scope: lazy. Allocated on first `spawn()` call from within the handler resolver. Dispose in `finally`. |
| 9 | Per-request actor supervision default: `Stop`. Naming: `{registeredName}-{requestId}`. Memoized within a request — multiple injection points referencing the same per-request actor name resolve to the same instance. |
| 10 | Streaming response sugar lives in core: `StreamingResponse::fromGenerator()`, `::ndjson()`, `::sse()`, `::file()`. |
| 11 | Route caching: in-scope for v1. Two cache files — our metadata (`var_export`) + FastRoute's built-in dispatcher cache. User invalidates on deploy. |
| 12 | Swoole server packaging: Option C — `nexus-http-server-swoole` works standalone (worker-local + per-request only). Pool-singleton becomes available when `nexus-worker-pool-swoole` is wired in. Compile-time validation fails fast if pool-singleton actors are declared without a wired worker-pool. |
| 13 | PSR-14 events: `RouteMatched`, `RequestStarted`, `RequestCompleted` — included v1. Dispatched only when an `EventDispatcher` is non-null on the system. |

## 3. Approach

**Approach 1 — Compile-time pipeline (chosen).** The HTTP app is built declaratively at boot. `$app->compile()`:

1. Resolves the actor registry → builds an immutable `ResolvedActorTable`.
2. Walks every registered handler/middleware class → inspects `#[FromActor]` attributes once via reflection → produces one `ResolvedHandler` per route. The hot path runs no reflection.
3. Builds the FastRoute dispatcher (cached to disk when `withRouteCache` is set).
4. Returns `RequestHandlerInterface` ready to serve requests.

Rejected: lazy per-request resolution (simpler boot, slower hot path — wrong tradeoff for thread-based Swoole where compile-once-serve-N is the natural shape).

## 4. Package layout

```
packages/nexus-http/
├── composer.json
├── README.md
└── src/
    ├── App/
    │   ├── HttpApp.php                 # Top-level fluent DSL + compile()
    │   ├── HttpAppConfig.php
    │   └── ErrorMode.php
    ├── Routing/
    │   ├── Router.php                  # Fluent router (FastRoute under the hood)
    │   ├── Route.php                   # Immutable Route value object
    │   ├── RouteBuilder.php
    │   ├── RouteGroup.php
    │   ├── RouteCollection.php
    │   ├── Dispatcher.php              # Wraps FastRoute dispatcher
    │   └── Attribute/
    │       └── Route.php               # #[Route('GET','/path')] attribute
    ├── Handler/
    │   ├── ResolvedHandler.php         # Compiled handler closure
    │   ├── HandlerResolver.php         # Reflection → factory at compile-time
    │   ├── AsyncHandler.php            # Marker for Future<Response> handlers
    │   └── Attribute/
    │       └── FromActor.php           # #[FromActor('name')] attribute
    ├── Middleware/
    │   ├── MiddlewarePipeline.php      # PSR-15 stack assembler
    │   ├── RouterMiddleware.php        # Inner-most: dispatches matched handler
    │   └── ExceptionHandlerMiddleware.php  # Outer-most by default
    ├── Actor/
    │   ├── ActorRegistry.php           # Boot-time table: name → (Props, mode)
    │   ├── ActorRegistration.php
    │   ├── ActorMode.php               # enum: PoolSingleton | WorkerLocal | PerRequest
    │   ├── ResolvedActorTable.php      # Compiled: name → ref factory
    │   └── PerRequestActorScope.php    # Per-request actor lifecycle scope (lazy)
    ├── Response/
    │   ├── Response.php                # Sugar: ::ok(), ::noContent(), ::notFound() …
    │   ├── JsonResponse.php
    │   └── StreamingResponse.php
    ├── Event/
    │   ├── RouteMatched.php
    │   ├── RequestStarted.php
    │   └── RequestCompleted.php
    ├── Server/
    │   └── HttpServerAdapter.php       # Interface implemented by server packages
    └── Exception/
        ├── HttpException.php
        ├── RouteNotFoundException.php
        ├── MethodNotAllowedException.php
        └── ExceptionMapperRegistry.php
```

### Dependencies

```json
{
  "require": {
    "php": "^8.5",
    "nexus/core": "^0.x",
    "nyholm/psr7": "^1.8",
    "nikic/fast-route": "^2.0",
    "psr/http-message": "^2.0",
    "psr/http-server-middleware": "^1.0",
    "psr/http-server-handler": "^1.0",
    "psr/http-factory": "^1.1",
    "psr/container": "^2.0"
  }
}
```

## 5. Core abstractions

### `HttpApp`

```php
final class HttpApp implements RequestHandlerInterface {
    public static function create(ActorSystem $system, ?ContainerInterface $container = null): self;

    // Actor registration
    public function actor(string $name, Props $props): ActorRegistration;
    public function perRequestActor(string $name, Props $props): ActorRegistration;

    // Routing
    public function get(string $path, string|Closure $handler): RouteBuilder;
    public function post(string $path, string|Closure $handler): RouteBuilder;
    public function put(string $path, string|Closure $handler): RouteBuilder;
    public function patch(string $path, string|Closure $handler): RouteBuilder;
    public function delete(string $path, string|Closure $handler): RouteBuilder;
    public function any(array $methods, string $path, string|Closure $handler): RouteBuilder;
    public function group(string $prefix, Closure $register): RouteGroup;
    public function discover(string $directory): self;

    // Middleware
    public function middleware(string|MiddlewareInterface $middleware): self;

    // Errors
    public function onException(string $class, Closure $mapper): self;
    public function errorMode(ErrorMode $mode): self;
    public function withoutDefaultExceptionHandler(): self;

    // Caching
    public function withRouteCache(string $path): self;
    public function clearRouteCache(): void;

    // Capability flags (for server adapters)
    public function requiresPoolSingleton(): bool;

    // Compile + serve
    public function compile(): self;             // idempotent
    public function handle(ServerRequestInterface $r): ResponseInterface;
}
```

### `Route`

```php
final readonly class Route {
    public function __construct(
        public string $method,
        public string $path,
        public string|Closure $handler,
        public array $middleware,
        public ?string $name,
    ) {}
}
```

### `ActorRegistration`

```php
final class ActorRegistration {
    public function mode(ActorMode $mode): self;
    public function poolSingleton(): self;
    public function workerLocal(): self;
    public function withSupervision(SupervisionStrategy $strategy): self;
    public function withMailbox(MailboxConfig $config): self;
}
```

### `ActorMode`

```php
enum ActorMode {
    case PoolSingleton;
    case WorkerLocal;
    case PerRequest;
}
```

### `ResolvedActorTable`

```php
final class ResolvedActorTable {
    /** Looked up at compile time for PoolSingleton + WorkerLocal. */
    public function resolve(string $name): ActorRef;

    /** Closure that spawns into the request scope. */
    public function perRequestFactory(string $name): Closure;
}
```

### `PerRequestActorScope`

```php
final class PerRequestActorScope {
    /** Memoized — same name returns same ref within a request. */
    public function spawn(string $name): ActorRef;

    /** Idempotent. PoisonPills every spawned actor. */
    public function dispose(): void;
}
```

### `HttpServerAdapter`

```php
interface HttpServerAdapter {
    /**
     * Block and serve until shutdown is called. Implementation reads raw HTTP →
     * ServerRequest → $app->handle($r) → writes response. For streaming responses,
     * MUST read+write per StreamInterface::read() chunk.
     */
    public function serve(RequestHandlerInterface $app): void;

    /** Graceful — finish in-flight requests within timeout. */
    public function shutdown(Duration $timeout): void;
}
```

## 6. Request flow (hot path)

```
1. Server reads raw HTTP, builds nyholm ServerRequest
2. server calls $app->handle($request)
3. ExceptionHandlerMiddleware             (outermost, always)
4. user global middlewares                (registration order, outside→in)
5. RouterMiddleware                       (FastRoute dispatch, attach path params, resolve handler)
6. matched route's group middlewares      (outer-group → inner-group, registration order)
7. matched route's per-route middlewares
8. ResolvedHandler closure                (direct call, no reflection; spawns per-request actors lazily)
9. Response unwinds back through MW stack
10. Server writes response
11. PerRequestActorScope::dispose() in finally  (idempotent, no-op when never accessed)
```

The scope is attached to the `ServerRequestInterface` as a request attribute under the key `PerRequestActorScope::class` (well-known). Handlers and middleware that need explicit access (not via `#[FromActor]`) read it from the attribute.

### Hot-path budget per request

| Step | Cost |
|---|---|
| ServerRequest build (nyholm) | cheap, no reflection |
| ExceptionHandlerMiddleware | 1 try/catch frame |
| Global middleware chain | user-controlled |
| FastRoute dispatch | regex match (sub-microsecond) |
| RouterMiddleware | hash lookup of compiled handler |
| Per-request actor spawn | only if the matched route's handler/middleware references one; lazy via `PerRequestActorScope` |
| Handler invoke | direct closure call (no reflection) |
| Future await | 1 fiber suspension (only for async handlers) |
| Scope dispose | one `PoisonPill` per spawned actor; no-op when none spawned |

### Handler signatures supported

```php
// Closure — sync
fn (ServerRequestInterface $r): ResponseInterface

// Closure — async
fn (ServerRequestInterface $r): Future  // Future<ResponseInterface>

// Class — sync, __invoke
final class Foo {
    public function __construct(
        #[FromActor('store')] private ActorRef $store,
    ) {}
    public function __invoke(ServerRequestInterface $r): ResponseInterface;
}

// Class — implements RequestHandlerInterface
final class Bar implements RequestHandlerInterface { ... }

// Method handler — 'App\Controllers\UserCtrl::show' string
```

Rule: constructor `#[FromActor]` may reference `PoolSingleton` or `WorkerLocal` only. Method-parameter `#[FromActor]` may reference any mode including `PerRequest`. Violations fail at `compile()`.

## 7. Lifecycle modes — concrete implementation

### Pool-singleton

- Lives on whichever worker thread the consistent-hash ring assigns to its name.
- Supervised by the user guardian with the declared strategy.
- Handlers receive a `WorkerActorRef<T>` resolved at compile via the worker-pool hash ring.
- `HttpApp::compile()` blocks until the worker-pool's hash ring is ready, or fails fast if no worker-pool is wired.

### Worker-local

- Each worker thread spawns its own copy at `HttpApp::compile()`.
- Supervised by the user guardian with the declared strategy.
- Handlers receive a `LocalActorRef<T>`.
- Not addressable from other threads; not registered in the cross-thread directory.

### Per-request

- Spawned lazily by `PerRequestActorScope::spawn(name)` inside the handler resolver on first reference.
- Child of a dedicated `requestScopeGuardian` actor that the framework spawns at boot.
- Default supervision: `Stop` (crash fails the request; no restart). Overridable per registration.
- Naming: `{registeredName}-{requestId}` where `requestId` is read from the conventional `X-Request-Id` header (generated as ULID when absent).
- Memoization: same name within a request resolves to the same `ActorRef` across multiple injection points.
- Disposal: `PoisonPill` in `finally`, fire-and-forget.

### Compile-time validation

| Rule | Enforcement |
|---|---|
| Constructor `#[FromActor]` cannot reference `PerRequest` | Boot fails |
| Pool-singleton declared but no worker-pool wired | Boot fails |
| Two actors with same name | Boot fails |
| `#[FromActor('xyz')]` with no `'xyz'` registered | Boot fails |
| Worker-local referenced via cross-thread API | Structurally impossible |

## 8. Async, futures, and ask integration

### Two handler return shapes, normalized at compile

**Sync (default):**

```php
$app->get('/users/{id}', static function (
    ServerRequestInterface $r,
    #[FromActor('user-store')] ActorRef $store,
): ResponseInterface {
    $user = $store->ask(
        fn(ActorRef $reply) => new GetUser($r->getAttribute('id'), $reply),
        Duration::seconds(2),
    );
    return JsonResponse::ok($user);
});
```

**Future-returning (opt-in):**

```php
$app->get('/dashboard/{id}', static fn(
    ServerRequestInterface $r,
    #[FromActor('user-store')] ActorRef $store,
    #[FromActor('order-store')] ActorRef $orders,
): Future => Future::all([
    'user'   => $store->askFuture(fn($reply) => new GetUser($r->getAttribute('id'), $reply), Duration::seconds(1)),
    'orders' => $orders->askFuture(fn($reply) => new ListOrders($r->getAttribute('id'), $reply), Duration::seconds(1)),
])->map(JsonResponse::ok(...)));
```

### `Future` API (reused from `nexus-core`)

```php
final class Future {
    /** @template U @param Closure(T): U $fn @return Future<U> */
    public function map(Closure $fn): Future;
    /** @template U @param Closure(T): Future<U> $fn @return Future<U> */
    public function flatMap(Closure $fn): Future;
    /** @param Closure(Throwable): T $fn @return Future<T> */
    public function recover(Closure $fn): Future;
    public function withTimeout(Duration $timeout): Future;
    public function await(): mixed;

    /** @param array<string|int, Future> $futures @return Future<array> */
    public static function all(array $futures): Future;
    public static function race(array $futures): Future;
    public static function resolved(mixed $value): Future;
    public static function failed(Throwable $error): Future;
}
```

### Compile-time normalization

The resolver inspects the declared return type once at compile time and emits either a direct invoker or a `(...)->await()` wrapper. Request-time has no type check.

### Streaming responses

```php
final class StreamingResponse {
    public static function fromGenerator(Generator $chunks, int $status = 200, array $headers = []): ResponseInterface;
    public static function ndjson(iterable $items, ?Closure $encoder = null): ResponseInterface;
    public static function sse(iterable $events): ResponseInterface;
    public static function file(string $path, ?string $contentType = null): ResponseInterface;
}
```

Implementation: wraps the iterable in a `StreamInterface` that pulls chunks on each `read()`. Server adapters MUST read + flush per chunk for SSE/NDJSON to work — documented on `HttpServerAdapter`.

### Out of scope for v1

- Request-wide deadline context propagation through `Envelope` metadata (v2).
- `onComplete`/`onSuccess`/`onFailure` callback variants (composition via `map`/`flatMap`/`recover` covers v1).

## 9. Middleware

### Pipeline (outer → inner)

1. `ExceptionHandlerMiddleware` (always, outermost — disable via `$app->withoutDefaultExceptionHandler()`)
2. User global middlewares (registration order)
3. Matched route's group middlewares (outer-group → inner-group)
4. Matched route's per-route middlewares
5. `RouterMiddleware` (innermost)

### Properties

- All middleware is plain PSR-15 `MiddlewareInterface`. No proprietary base class.
- `#[FromActor]` injection works on middleware constructors via the same compile-time resolver. Middleware is instantiated once per worker thread.
- Per-request actor access from inside middleware: pull `PerRequestActorScope` from the request attributes (well-known key) and call `->spawn('name')`.

### Groups nest

```php
$app->group('/api/v1', static function (RouteGroup $g): void {
    $g->middleware(AuthMiddleware::class);
    $g->get('/orders', ListOrdersAction::class);
    $g->post('/orders', CreateOrderAction::class)
        ->middleware(IdempotencyMiddleware::class);
});
```

Compiled stack for a deeply-nested route: `[outer-group middlewares..., inner-group middlewares..., per-route middlewares]` in registration order.

### Attribute discovery

```php
#[Route('GET', '/users/{id}', name: 'users.show', middleware: [AuthMiddleware::class])]
final class GetUserAction {
    public function __construct(
        #[FromActor('user-store')] private ActorRef $store,
    ) {}

    public function __invoke(ServerRequestInterface $r): ResponseInterface { ... }
}
```

`$app->discover('src/Http/')` walks the directory and reflects `#[Route]` attributes once at compile. Composer's classmap is used when available; otherwise `RecursiveDirectoryIterator`. The discovered routes feed the same `RouteCollection` as the fluent DSL.

`#[Route]` is `Attribute::IS_REPEATABLE` — one class may declare multiple endpoints.

## 10. Route caching

Two cache files written and read side by side:

| File | Purpose | Generator |
|---|---|---|
| `{path}/routes.php` | Our metadata: route table + per-handler reflection results (`var_export`) | Custom |
| `{path}/routes.fast.php` | FastRoute regex-tree cache | `FastRoute\cachedDispatcher` |

```php
$app->withRouteCache('/var/cache/nexus-http');
```

Both regenerated when missing. Deploy-time invalidation is the user's responsibility (`$app->clearRouteCache()` available as a CLI hook). FastRoute's cache uses `var_export` to a PHP file that opcache compiles; ours mirrors that strategy.

### Metadata cache shape

```php
<?php return [
    'routes' => [
        // [method, path, handlerRef, name, middleware[]]
        ['GET',  '/users/{id}',    'App\\Http\\GetUserAction',     'users.show', []],
        ['POST', '/api/v1/orders', 'App\\Http\\CreateOrderAction', null,         ['App\\Mw\\Idempotency']],
    ],
    'handlers' => [
        'App\\Http\\GetUserAction' => [
            'ctorInject'     => [['name' => 'store', 'fromActor' => 'user-store']],
            'invokeParams'   => [
                ['name' => 'r',    'type' => 'Psr\\Http\\Message\\ServerRequestInterface'],
                ['name' => 'saga', 'type' => 'Nexus\\Core\\Actor\\ActorRef', 'fromActor' => 'order-saga'],
            ],
            'returnIsFuture' => false,
        ],
    ],
    'middleware' => [ /* same shape */ ],
];
```

What is NOT cached: `ResolvedHandler` closures, `ActorRef` instances, middleware instances — these are rebuilt at boot from the cached metadata in microseconds without reflection.

## 11. Error handling

### `ExceptionHandlerMiddleware`

Outermost by default. Catches every `Throwable`, walks the `ExceptionMapperRegistry`, returns the mapped response. Disable via `$app->withoutDefaultExceptionHandler()` to take full PSR-15 control.

### `ExceptionMapperRegistry`

```php
$app
    ->onException(AskTimeoutException::class, fn($e, $r) => Response::gatewayTimeout())
    ->onException(MailboxOverflowException::class, fn($e, $r) =>
        Response::serviceUnavailable(retryAfter: Duration::seconds(1)))
    ->onException(ValidationException::class, fn($e, $r) =>
        JsonResponse::ok(['errors' => $e->errors()])->withStatus(422))
    ->onException(Throwable::class, fn($e, $r) => Response::internalServerError());
```

Resolution: walk the exception's class hierarchy (class → parents → interfaces), return the first match. `Throwable::class` is the catch-all.

### Built-in default mappers

| Exception | Default response |
|---|---|
| `RouteNotFoundException` | 404 |
| `MethodNotAllowedException` | 405 + `Allow` header |
| `AskTimeoutException` | 504 |
| `MailboxOverflowException` | 503 + `Retry-After: 1` |
| `MailboxClosedException` | 503 |
| `HttpException` (our base) | Uses its own status code |
| `Throwable` | 500 — body controlled by `errorMode` |

User registrations override defaults (last registration for a given exact class wins).

### `ErrorMode`

```php
enum ErrorMode { case Production; case Development; }
```

- `Production`: 500 body is `{"error":"Internal Server Error"}`. Exception logged via PSR-3 with full trace.
- `Development`: 500 body includes exception class, message, file, line, trace. Still logged.

Default: `Production`.

### `HttpException`

```php
class HttpException extends NexusException {
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notFound(string $message = 'Not Found'): self;
    public static function unauthorized(): self;
    public static function forbidden(): self;
    public static function unprocessableEntity(array $errors): self;
}
```

### Per-request actor crash propagation

- Synchronous (handler is `ask`-ing the crashing actor): supervision is `Stop`; the awaiting `ask` receives `MailboxClosedException` (or `AskTimeoutException` if the deadline arrives first); bubbles to `ExceptionHandlerMiddleware` → mapped to 503/504.
- Asynchronous (no awaiting handler): captured by the `requestScopeGuardian` parent's `ChildFailed` signal handler; logged via PSR-3; no response impact.
- Scope disposal: `RequestScopeMiddleware`'s `finally` always runs; idempotent.

## 12. PSR-14 events

Dispatched only when an `EventDispatcher` is non-null on the system. Cost when disabled: a single null check.

| Event | Carries |
|---|---|
| `RouteMatched` | `ServerRequestInterface`, matched `Route`, path params |
| `RequestStarted` | `ServerRequestInterface`, monotonic start time |
| `RequestCompleted` | `ServerRequestInterface`, `ResponseInterface`, monotonic duration |

Useful for tracing/metrics integrations. Not extensible in v1 (the three events are sufficient for the standard tracing model).

## 13. Server adapter contract

`HttpServerAdapter` is the seam to server packages. The contract is one method (`serve`) plus a graceful shutdown. The server owns:

- Socket binding
- Worker thread pool (delegates to `nexus-worker-pool-swoole` when present)
- Raw HTTP ↔ PSR-7 translation (via `nyholm/psr7`)
- Streaming write semantics (read+flush per `StreamInterface::read()` chunk)
- Per-worker `ActorSystem` lifecycle

The server does NOT own: routing, middleware, handler resolution, actor injection. All of those belong to `HttpApp`.

### Multi-thread Swoole — packaging decision

`nexus-http-server-swoole` (future package, separate spec) ships in **two modes**:

1. **Standalone** — no `nexus-worker-pool-swoole`. Uses Swoole's own `worker_num`. Each Swoole worker has its own `ActorSystem`. `PoolSingleton` actors are unavailable; `HttpApp::compile()` fails fast with a clear error if any are declared.
2. **Wired with worker-pool** — `nexus-worker-pool-swoole` installed and wired. The HTTP server runs inside the existing thread pool. Each thread binds its own `Swoole\Http\Server` to the same address via `SO_REUSEPORT`; the kernel load-balances connections. Pool-singleton actors route via the existing consistent hash ring. Worker-local and per-request modes work the same as standalone.

`HttpApp::requiresPoolSingleton(): bool` lets the adapter detect the mismatch at compile time and refuse to boot with a useful error.

`HttpApp::compile()` detects worker-pool availability by querying the `ActorSystem` for an attached `WorkerNode` (whose presence implies the hash ring is wired). If `requiresPoolSingleton()` is true and no `WorkerNode` is attached, compile fails with a clear error naming the offending actor registrations.

## 14. End-to-end DSL example

```php
use Nexus\Core\Actor\{ActorRef, ActorSystem, Behavior, Duration, Props};
use Nexus\Core\Supervision\SupervisionStrategy;
use Nexus\Http\App\{ErrorMode, HttpApp};
use Nexus\Http\Handler\Attribute\FromActor;
use Nexus\Http\Response\{JsonResponse, Response, StreamingResponse};
use Nexus\Http\Routing\Attribute\Route;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

$app = HttpApp::create($system, $container)
    // Actors
    ->actor('user-store', Props::fromBehavior($userStoreBehavior))
        ->poolSingleton()
        ->withSupervision(SupervisionStrategy::exponentialBackoff(
            initialBackoff: Duration::millis(100),
            maxBackoff:     Duration::seconds(10),
            maxRetries:     5,
        ))
    ->actor('metrics', Props::fromFactory(fn() => new MetricsActor()))
        ->workerLocal()
    ->perRequestActor('order-saga', $orderSagaProps)

    // Global middleware
    ->middleware(LoggingMiddleware::class)
    ->middleware(RequestIdMiddleware::class)

    // Inline routes
    ->get('/health', fn() => Response::ok())
    ->get('/users/{id}', static fn(
        ServerRequestInterface $r,
        #[FromActor('user-store')] ActorRef $store,
    ) => JsonResponse::ok($store->ask(
        fn(ActorRef $reply) => new GetUser($r->getAttribute('id'), $reply),
        Duration::seconds(2),
    )))

    // Grouped + discovered
    ->group('/api/v1', static function (RouteGroup $g): void {
        $g->middleware(AuthMiddleware::class);
        $g->discover('src/Http/Api/');
    })

    // Async fan-out
    ->get('/dashboard/{id}', static fn(
        ServerRequestInterface $r,
        #[FromActor('user-store')] ActorRef $store,
        #[FromActor('order-store')] ActorRef $orders,
    ): Future => Future::all([
        'user'   => $store->askFuture(fn($reply) => new GetUser($r->getAttribute('id'), $reply), Duration::seconds(1)),
        'orders' => $orders->askFuture(fn($reply) => new ListOrders($r->getAttribute('id'), $reply), Duration::seconds(1)),
    ])->map(JsonResponse::ok(...)))

    // Streaming
    ->get('/exports/{type}', static fn(ServerRequestInterface $r) =>
        StreamingResponse::ndjson(
            (function () use ($r) {
                foreach (queryEachRow($r->getAttribute('type')) as $row) {
                    yield $row;
                }
            })(),
        )
    )

    // Errors
    ->onException(ValidationException::class, fn($e) =>
        JsonResponse::ok(['errors' => $e->errors()])->withStatus(422))
    ->onException(AskTimeoutException::class, fn() =>
        Response::gatewayTimeout())

    ->errorMode(ErrorMode::Production)
    ->withRouteCache('/var/cache/nexus-http')
    ->compile();

// Discovered action class
#[Route('POST', '/api/v1/orders', name: 'orders.create')]
final class CreateOrderAction {
    public function __construct(
        #[FromActor('order-store')] private ActorRef $orders,
    ) {}

    public function __invoke(
        ServerRequestInterface $r,
        #[FromActor('order-saga')] ActorRef $saga,  // per-request actor
    ): ResponseInterface {
        $command = OrderCommand::fromBody($r->getParsedBody());
        $orderId = $saga->ask(
            fn(ActorRef $reply) => new ProcessOrder($command, $reply),
            Duration::seconds(5),
        );
        return JsonResponse::created(['orderId' => $orderId], location: "/api/v1/orders/{$orderId}");
    }
}
```

## 15. Testing strategy

### Unit (`packages/nexus-http/tests/Unit/`)

- `RouterTest` — fluent registration → `Route` objects, group prefix composition, attribute discovery
- `RouteCollectionTest` — duplicate detection, route name lookup
- `HandlerResolverTest` — reflection-driven factory generation, validation rules (per-request actor in constructor → throws)
- `ActorRegistryTest` — duplicate name detection, mode setters
- `ExceptionMapperRegistryTest` — class-hierarchy walk, override behavior
- `PerRequestActorScopeTest` — memoization, lazy init, dispose idempotency
- `Response` / `JsonResponse` / `StreamingResponse` builders

### Integration (`tests/Integration/Http/`)

Full pipeline with `TestRuntime` + in-process actor system. No real server.

- Build a real `HttpApp` with real actors
- Drive via `$app->handle($psr7Request)` directly
- Assert responses, side effects on actors, exception mappings
- Cover all three lifecycle modes (`PoolSingleton` simulated via single-worker test ring)
- Cover Future-returning handlers (await happens inside `RouterMiddleware`)
- Cover streaming responses (read the response body stream chunk by chunk and assert)

### Psalm plugin rules (added to `nexus-psalm`)

- `FromActorReferencesRegisteredActorRule` — `#[FromActor('xyz')]` must reference a name passed to `$app->actor('xyz', ...)`. Best-effort: requires the app builder to be analyzable.
- Existing `ReadonlyMessageRule` already covers messages handlers send.

### Performance (`tests/Performance/Http/`)

- N=10_000 dispatches against a 50-route table → assert P99 < target
- Compile-time cost asserted bounded

### Server-package contract test

`HttpServerAdapterContractTest` abstract test class. Concrete adapters (Swoole, fiber-SAPI) extend and run it. Verifies: handle request, write response, stream chunks, shutdown drains in-flight requests.

## 16. PSR compliance

| PSR | Compliance | Notes |
|---|---|---|
| PSR-3 (Logger) | Consumes | Via `ActorSystem`'s logger; middleware/handlers can DI it |
| PSR-7 (HTTP messages) | Consumes | Via `nyholm/psr7` |
| PSR-11 (Container) | Optional consumes | `HttpApp::create(..., $container)` for handler instantiation |
| PSR-14 (EventDispatcher) | Consumes | `RouteMatched`, `RequestStarted`, `RequestCompleted` events |
| PSR-15 (Middleware + Handler) | Implements | `HttpApp implements RequestHandlerInterface`; all middleware is `MiddlewareInterface` |
| PSR-17 (HTTP factories) | Consumes | nyholm factories injected via PSR-17 interfaces |

## 17. Out of scope for v1

| Item | Disposition |
|---|---|
| Pool-of-N actor mode | v2 |
| Request-wide deadline context propagation | v2 |
| WebSocket support | Separate package: `nexus-websocket` |
| HTTP/2 server push | Server-package concern when added |
| Content negotiation helpers beyond basic JSON | Out — handled by user middleware or future package |
| OpenAPI generation from `#[Route]` | Separate package: `nexus-http-openapi` |
| Rate limiting | Separate package or user-supplied middleware |
| CSRF protection | Separate package or user-supplied middleware |
| Async DBAL integration | Separate package: `nexus-dbal-async` |
| PSR-18 HTTP client | Separate package: `nexus-http-client` |
| Concrete Swoole HTTP server | Separate package: `nexus-http-server-swoole` (depends on this spec) |

## 18. Open questions

None blocking. The following are clarifications expected during implementation, not redesign:

- Exact `Future` API surface in `nexus-core` to confirm `askFuture` exists by name (may need a rename if the core uses a different name); spec assumes `askFuture` and `await`.
- Final shape of `RouteBuilder` and `RouteGroup` (covered conceptually; field details emerge in code).
- File-system layout convention for `discover()` (recommend default of "any class with `#[Route]` in the configured directory tree").

## 19. API clarifications (post-spec, during implementation)

The following clarifications were recorded during the Phase 0–17 implementation. They are corrections to the spec's earlier wording, not redesigns.

- **`ActorRef::ask` returns `Future<R>` directly.** The synchronous form is `->ask(...)->await()`. The spec's earlier handler examples used `askFuture` as a hypothetical separate method; in practice `ask` *is* what would have been `askFuture`. All DSL examples should be read as `->ask($msg, $timeout)->await()` for the sync path and `->ask($msg, $timeout)` to keep the `Future` open for composition.
- **Namespace is `Monadial\Nexus\Http\`** to match the rest of the monorepo.
- **`Dsl\` sub-namespace.** The fluent DSL classes (`HttpApp`, `RouteBuilder`, `RouteGroup`, `ActorRegistration`) live under `Monadial\Nexus\Http\Dsl\`. Runtime and data classes stay in their topical namespaces (`App\`, `Actor\`, `Handler\`, `Routing\`, `Middleware\`, `Response\`, `Cache\`, `Event\`, `Server\`, `Exception\`).
- **`HttpApp::compile()` returns `CompiledHttpApp`**, not `self`. `HttpApp` is the mutable builder; `CompiledHttpApp` is the immutable `final readonly` runtime that implements `RequestHandlerInterface`. Server adapters consume `CompiledHttpApp`.
- **Compiled middleware stack.** The full PSR-15 chain is assembled once inside `compile()` into a single `RequestHandlerInterface` via `MiddlewareInvoker`. `handle()` runs the compiled chain directly — no per-request reassembly.
- **PSR-16 route caching.** `RouteCachePersister` wraps a `Psr\SimpleCache\CacheInterface`. Closure-handler routes are skipped from cache and re-added from the in-memory collection on boot.
- **`#[FromService]` attribute.** Handlers and middleware can inject any PSR-11 container service via `#[FromService('service.id')]` (by id) or `#[FromService] MyService $svc` (by parameter type).
- **`nikic/fast-route` ^1.3**, not the spec's ^2.0. Only `2.0.0-beta1` exists; v1.3 is stable, widely deployed, and matches our dispatcher code.
- **`Future` API extensions added in `nexus-runtime`:** `Future::all(array): Future<FutureResult>`, `Future::resolved(object): Future`, `Future::failed(FutureException): Future` were added to enable fan-out composition. `recover`, `race`, `withTimeout` remain deferred.
- **`ExceptionMapperRegistry` lookup order is class → parents → interfaces → `Throwable` fallback.** Parent classes win over interfaces in the walk, so if an exception extends `RuntimeException` AND implements a user interface, the `RuntimeException` mapper wins if both are registered. Users who want interface-keyed mappers should ensure the interface is more specific than the concrete parent chain.
