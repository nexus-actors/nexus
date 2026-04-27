# Nexus HTTP — Akka HTTP-style toolkit for Swoole

## Summary

A composable, type-safe HTTP toolkit for the Nexus actor system. Closure-nested directive DSL on top of PSR-7/15/17, type-safe path/query/body extractors, auto-marshalling via a content-type registry, actor-per-connection WebSockets, and two runtime modes — single-coroutine for development and multi-thread (Swoole 6.0 ZTS) for production. Three packages, fully modular, runtime-agnostic core.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| DSL style | Closure-nested directives, type-safe extractors | Closest to Akka HTTP's directive composition under PHP's lack of operator overloading |
| Data plane | PSR-7/15/17 throughout | Maximum PSR compliance; reuse PHP middleware ecosystem; consistent with project's PSR-3/11/14/20 baseline |
| WebSocket model | Actor-per-connection via accept factory | Connection lifecycle = actor lifecycle; supervision, mailbox, watchers all apply transparently |
| Runtime topology | T1 (SWOOLE_THREAD multi-core) + T3 (single-coroutine dev) | T1 zero-hop locality + multi-core scaling; T3 fast feedback for tests/dev. Skip frontend/backend split (always slower than T1) |
| Marshalling | Per-content-type registry, Valinor+JSON default, `Accept` negotiation with cache | Ergonomic auto-completion + future-proof for msgpack/igbinary |
| Error handling | Single top-level `errorMapper`, per-route via `mapRejection()` | Predictable global path; local control where needed |
| Middleware ecosystem | Three baseline (`BearerToken`, `RequestId`, `Logging`) — community for CORS/gzip/security | Avoid lock-in; integrate the contract, not the implementations |
| Default PSR-7 impl | nyholm/psr7 | Lightest implementation |
| Test ergonomics | `nexus-http-testkit` (no Swoole) + `StepRuntime` for actor-aware tests | Pure-function route tests; deterministic actor tests; reuse battle-tested runtime |
| Package layout | `nexus-http` + `nexus-http-swoole` + `nexus-http-testkit` | Modular; runtime-agnostic core |
| Hash-ring placement | Always-on for domain actors; connection actors always local | Reuses existing `ConsistentHashRing` from `nexus-worker-pool`; predictable locality |

## Out of scope (v1)

- HTTP client
- Server-Sent Events directive
- Symfony / Laravel framework adapters
- HTTP/3 / QUIC
- Multipart / streaming uploads
- WebSocket subprotocols and `permessage-deflate`
- Non-JSON marshallers (msgpack, igbinary) — opt-in later

## Architecture overview

```
nexus-http             [runtime-agnostic, the brain]
  ├─ Route, Directive, Extractor, RequestCtx
  ├─ Marshaller registry + JsonValinorMarshaller
  ├─ MiddlewareDirective (PSR-15 adapter)
  ├─ WsConnection contract (impl-agnostic)
  └─ depends on: psr/{http-message,http-server-handler,http-server-middleware,http-factory},
                 nyholm/psr7, nexus-core, nexus-serialization

nexus-http-swoole      [runtime adapter, the body]
  ├─ HttpServerBootstrap (T1 + T3)
  ├─ SwooleRequestConverter (Swoole\Http\Request → PSR-7)
  ├─ SwooleResponseEmitter (PSR-7 → Swoole\Http\Response)
  ├─ SwooleWsConnection (impl of WsConnection)
  ├─ ThreadHttpRunnable (per-thread bootstrap, Swoole\Thread\Runnable)
  └─ depends on: nexus-http, nexus-runtime-swoole, nexus-worker-pool-swoole

nexus-http-testkit     [test ergonomics, no Swoole]
  ├─ RouteTestKit::route($routes)->get('/orders/1')->expectStatus(200)->expectJson([...])
  ├─ Synthetic PSR-7 request builders
  ├─ WebSocketTestKit (synthetic WsConnection)
  └─ depends on: nexus-http only
```

Deptrac rules:
- `nexus-http` must not depend on Swoole or any runtime package.
- `nexus-http-testkit` must not depend on Swoole.

## Two-stage request lifecycle

```
Stage 1 — Boot (once per thread in T1, once in T3)
─────────────────────────────────────────────
  HttpServerBootstrap::create($routes, $config)
    → compile($routes) into static dispatch trie
    → register MarshallerRegistry (defaults + user-added)
    → register Swoole Http\Server callbacks
    → T1: spawn N threads via Thread\Pool, each runs ThreadHttpRunnable
    → T3: one coroutine, one ActorSystem, server runs in main coroutine
    → ActorSystem + WorkerNode (T1) bound to thread

Stage 2 — Per-request (hot path)
─────────────────────────────────────────────
  Swoole\Http\Request
    → SwooleRequestConverter (lazy-body PSR-7 ServerRequest)
    → DispatchTrie::match(method, path) → RouteMatch | 404
    → Build RequestCtx (request, params, system, node, marshaller)
    → Walk Directive chain:
         middleware → extractors → completion closure → response value
    → Marshaller serializes value (Accept-negotiated) → PSR-7 Response
    → SwooleResponseEmitter → Swoole\Http\Response
```

The directive chain is **evaluated as a function**, not a middleware stack. PSR-15 middlewares plug in via the `useMiddleware()` directive — they are adapters, not the primary surface.

## Core abstractions

### `Route` and the directive contract

```php
namespace Monadial\Nexus\Http\Routing;

/**
 * (RequestCtx) → ?ResponseInterface
 *   null     = rejection (try sibling in concat / fall through to 404)
 *   response = completion
 */
final readonly class Route
{
    /** @param Closure(RequestCtx): ?ResponseInterface $run */
    public function __construct(public Closure $run) {}
}
```

Directive functions take their args + a child factory and return a `Route`:

```php
function path(string|class-string<Extractor> ...$segments, callable $child): Route;
function pathPrefix(string $literal, callable $child): Route;
function pathEnd(callable $child): Route;
function get(callable $child): Route;
function post(callable $child): Route;
function put(callable $child): Route;
function delete(callable $child): Route;
function patch(callable $child): Route;
function method(string $verb, callable $child): Route;
function query(string $name, ?string $extractorClass, callable $child): Route;
function optionalQuery(string $name, ?string $extractorClass, callable $child): Route;
function header(string $name, callable $child): Route;
function optionalHeader(string $name, callable $child): Route;
function jsonBody(string $targetClass, callable $child): Route;
function formBody(string $targetClass, callable $child): Route;
function rawBody(callable $child): Route;
function extractRequest(callable $child): Route;
function useMiddleware(MiddlewareInterface $mw, callable $child): Route;
function useMiddlewares(array $mws, callable $child): Route;
function mapResponse(callable $transform, callable $child): Route;
function mapRejection(callable $transform, callable $child): Route;
function concat(Route ...$routes): Route;

// Terminal — auto-marshal. If $value is callable, invoked with RequestCtx and the result is marshalled.
function complete(mixed $value, int $status = 200): Route;
function completeWith(ResponseInterface $response): Route;     // explicit PSR-7 response
function completeBuilt(callable $build): Route;                // fn(RequestCtx): ResponseInterface
function redirect(string $location, int $status = 302): Route;
function reject(RouteRejection $r): Route;

// Phase 3 (WebSocket)
function webSocket(callable $accept, ?string $marshalAs = null): Route;
```

### Extractors

```php
namespace Monadial\Nexus\Http\Extract;

/** @template T */
interface Extractor
{
    /** @return T */
    public function extract(RequestCtx $ctx): mixed;
}
```

Built-in extractors (final, readonly):

| Extractor | Yields | Use |
|---|---|---|
| `IntNumber` | `int` | `path('orders', IntNumber::class, fn(int $id) => ...)` |
| `LongNumber` | `int` | larger range / 64-bit |
| `StringSegment` | `string` | non-slash path segment |
| `UuidSegment` | `Uuid` | UUID validation |
| `UlidSegment` | `Ulid` | symfony/uid Ulid |
| `Remaining` | `string` | greedy tail |
| `Query<T>` | `T` | typed query parameter |
| `Header` | `string` | typed header |
| `JsonBody<T>` | `T` (Valinor-mapped) | typed JSON request body |
| `FormBody<T>` | `T` | application/x-www-form-urlencoded |
| `RawBody` | `string` | raw bytes |

### `RequestCtx`

```php
namespace Monadial\Nexus\Http;

interface RequestCtx
{
    public function request(): ServerRequestInterface;
    public function param(string $name): ?string;
    public function system(): ActorSystem;
    public function node(): WorkerNode;                 // T1; T3 returns local-only stub
    public function actorFor(string $path): ?ActorRef;
    public function ask(string $path, object $msg, ?Duration $timeout = null): mixed;
    public function marshallerFor(MediaType $type): Marshaller;
    public function negotiate(): MediaType;
    public function log(): LoggerInterface;
}
```

### Marshaller

```php
interface Marshaller
{
    public function mediaType(): MediaType;
    /** @template T */
    public function unmarshal(string $body, string $targetType): mixed;
    public function marshal(mixed $value): string;
}

final class MarshallerRegistry
{
    public function register(Marshaller $m): self;
    public function negotiate(string $acceptHeader): Marshaller;   // q-value aware, cached
    public function default(): Marshaller;                          // application/json
}
```

Default registered: `JsonValinorMarshaller` (Valinor mapper for `unmarshal`, native `json_encode` for `marshal`).

### Route compilation — dispatch trie

At boot, the entire `concat(...)` tree compiles to a method-keyed prefix trie:

```
GET
├── /orders/{int}        → Route#42
├── /orders              → Route#43
└── /health              → Route#9
POST
└── /orders              → Route#44
(fallback)               → Route#0  (404)
```

- O(segments) match per request; no per-request regex unless extractor demands it.
- 405 produced when a method-mismatch hits a populated path; `Allow:` header derived from trie.
- Compiled once per boot; per thread in T1.

## DSL examples

### Hello world

```php
$routes = get(path('hello', fn() => complete(['greeting' => 'hello world'])));
```

### REST resource backed by an actor

```php
$orders = pathPrefix('orders', fn() => concat(
    get(path(IntNumber::class, fn(int $id) =>
        complete(fn(RequestCtx $ctx) =>
            $ctx->ask('orders', new GetOrder($id), Duration::seconds(2))
        )
    )),

    post(pathEnd(fn() =>
        jsonBody(CreateOrder::class, fn(CreateOrder $cmd) =>
            complete(fn(RequestCtx $ctx) => $ctx->ask('orders', $cmd), 201)
        )
    )),

    delete(path(IntNumber::class, fn(int $id) =>
        complete(fn(RequestCtx $ctx) => $ctx->ask('orders', new DeleteOrder($id)), 204)
    )),
));
```

### Versioned API with auth

```php
$api = pathPrefix('api', fn() =>
    pathPrefix('v1', fn() =>
        useMiddleware(new BearerTokenMiddleware($keys), fn() =>
            concat($orders, $payments, $users)
        )
    )
);
```

### Mixed literal + extracted segments

```php
path('tenant', UlidSegment::class, 'orders', IntNumber::class, fn(Ulid $tenantId, int $orderId) =>
    get(complete(fn(RequestCtx $ctx) =>
        $ctx->ask("orders/{$tenantId}", new GetOrder($orderId))
    ))
)
```

### Query parameters

```php
get(path('search', fn() =>
    query('q', null, fn(string $q) =>
        optionalQuery('limit', IntNumber::class, fn(?int $limit) =>
            complete(fn(RequestCtx $ctx) =>
                $ctx->ask('catalog', new Search($q, $limit ?? 20))
            )
        )
    )
))
```

### Custom rejection mapping

```php
mapRejection(
    fn(RouteRejection $r) => RouteRejection::completeWith(
        new JsonResponse(['error' => $r->code, 'message' => $r->message], $r->status)
    ),
    $api
);
```

## Runtime modes

### Unified bootstrap

```php
// T3 — dev / single-coroutine
HttpServerBootstrap::dev($routes)
    ->host('127.0.0.1')->port(8080)
    ->onSystemReady(fn(ActorSystem $sys) => $sys->spawn($orderProps, 'orders'))
    ->run();

// T1 — production / multi-thread
HttpServerBootstrap::threaded($routes)
    ->host('0.0.0.0')->port(8080)
    ->threads(8)
    ->onWorkerStart(function (WorkerNode $node, ActorSystem $sys): void {
        $sys->spawn($orderProps, 'orders');
        $sys->spawn($paymentProps, 'payments');
    })
    ->marshallers(fn(MarshallerRegistry $r) => $r->register(new MsgpackMarshaller()))
    ->run();
```

`HttpServerBootstrap` exposes only two named constructors — no string flags or booleans for topology selection.

### Config surface (final)

```php
HttpServerBootstrap::threaded($routes)
    ->host(string)
    ->port(int)
    ->threads(int)                                  // T1 only
    ->backlog(int = 511)
    ->keepAliveTimeout(Duration = seconds(60))
    ->drainTimeout(Duration = seconds(30))
    ->maxRequestSize(int = 8MB)
    ->httpVersion(HttpVersion = HTTP_1_1_AND_2)
    ->onWorkerStart(callable)                       // T1
    ->onSystemReady(callable)                       // T3
    ->onWorkerStop(callable)                        // both
    ->marshallers(callable(MarshallerRegistry))
    ->errorMapper(callable(Throwable, RequestCtx): ResponseInterface)
    ->run(): never
```

### T3 internals (dev mode)

```
HttpServerBootstrap::dev($routes)->run()
  ├─ compile($routes) → DispatchTrie
  ├─ register MarshallerRegistry
  ├─ new SwooleRuntime
  ├─ ActorSystem::create('http-dev', $runtime)
  ├─ run user's onSystemReady(...) — actors spawned
  ├─ new Swoole\Http\Server($host, $port, SWOOLE_BASE)
  ├─ $server->on('request', fn($req, $res) =>
  │     Coroutine::create(fn() => handleRequest($req, $res)))
  └─ $runtime->run()
```

- One process, one coroutine pool, one `ActorSystem`.
- `actorFor()` resolves locally; `WorkerNode` stub returns null for remote.
- No `Thread\Map`, no `Thread\Queue`.
- Cold start <50ms; ideal for `phpunit` integration tests.

### T1 internals (production)

```
master process:
  ├─ compile($routes) → serialized DispatchTrie blob
  ├─ create Thread\Map (shared actor directory)
  ├─ create Thread\Queue × N (per-thread inbox)
  ├─ create Thread\Atomic for worker-id assignment
  └─ Thread\Pool::start(N, ThreadHttpRunnable)

each thread (ThreadHttpRunnable::run):
  ├─ atomically claim workerId
  ├─ deserialize DispatchTrie (or share-by-reference where Swoole supports)
  ├─ new SwooleRuntime
  ├─ ActorSystem::create("http-thread-{$id}", $runtime)
  ├─ ThreadQueueTransport::bind($queues, $workerId)
  ├─ ThreadMapDirectory::bind($map)
  ├─ WorkerNode::create($system, $directory, $transport, $hashRing)
  ├─ run user's onWorkerStart($node, $system) — actors spawned
  ├─ new Swoole\Http\Server($host, $port, SWOOLE_THREAD)  with SO_REUSEPORT
  ├─ $server->on('request', fn($req, $res) =>
  │     Coroutine::create(fn() => handleRequest($req, $res, $node)))
  └─ $runtime->run()
```

Swoole 6.0 `SWOOLE_THREAD` mode lets every thread bind the same listening socket. Connections distributed by the kernel via `SO_REUSEPORT`. Each thread accepts independently — zero contention on the accept path.

### Hash-ring placement & locality

**Terminology**: a *domain actor* is any actor spawned via `onWorkerStart()` or `$ctx->spawn()` that represents persistent business state (orders, payments, room aggregates). A *connection actor* is the per-WebSocket actor spawned by the `webSocket()` accept factory; it is ephemeral, scoped to one TCP connection.

Reuse `ConsistentHashRing` from `nexus-worker-pool`. Always-on for **domain actors**:

```
1. hashRing.lookup('orders') → workerId
2. if workerId == self → enqueue locally (in-coroutine), zero hops
3. else → ThreadQueueTransport::send(workerId, $envelope)
```

Connection actors (Phase 3) are always local to whichever thread accepted them. Domain actors use the ring. Two layers, no conflict.

### Graceful shutdown

```
SIGTERM / Ctrl-C
  ├─ master: stop accept on all threads
  ├─ each thread:
  │    ├─ wait drainTimeout for in-flight requests
  │    ├─ ActorSystem::shutdown(Duration $timeout)
  │    ├─ ThreadQueueTransport::close()
  │    └─ SwooleRuntime::shutdown()
  └─ Thread\Pool::join() → process exit
```

In-flight responses get `Connection: close` during drain.

## WebSocket model

### Mental model

Every WebSocket connection becomes a child actor of an anonymous parent owned by the framework.

```
HTTP UPGRADE  → webSocket($accept) directive
              → framework spawns user actor via $accept(WsConnection, RequestCtx)
              → links connection lifecycle to actor lifecycle:
                  inbound frame → tell(actor, WsMessage)
                  actor stops    → conn->close()
                  conn closes    → tell(actor, WsClosed) then PoisonPill
```

### Public types

```php
namespace Monadial\Nexus\Http\WebSocket;

interface WsConnection
{
    public function id(): string;
    public function send(WsFrame $frame): void;
    public function sendText(string $text): void;
    public function sendBinary(string $bytes): void;
    public function close(int $code = 1000, string $reason = ''): void;
    public function isOpen(): bool;
    public function remoteAddress(): string;
}

final readonly class WsTextMessage   { public function __construct(public string $text) {} }
final readonly class WsBinaryMessage { public function __construct(public string $bytes) {} }
final readonly class WsClosed        { public function __construct(public int $code, public string $reason) {} }
final readonly class WsJsonMessage   { public function __construct(public object $payload) {} }
final readonly class WsBadFrame      { public function __construct(public string $reason) {} }
```

### Directive shape

```php
$routes = path('chat', UlidSegment::class, fn(Ulid $room) =>
    webSocket(
        accept: function (WsConnection $conn, RequestCtx $ctx) use ($room): ActorRef {
            return $ctx->system()->spawnAnonymous(
                Props::fromBehavior(ChatBehavior::create($room, $conn))
            );
        },
        marshalAs: ChatCommand::class,   // optional; inbound text → unmarshal → WsJsonMessage
    )
);
```

`webSocket()` is **terminal** — like `complete()`. No child route.

### Lifecycle & supervision

| Event | Effect |
|---|---|
| Frame arrives | `tell(actor, WsTextMessage|WsBinaryMessage|WsJsonMessage)` |
| Client closes / TCP drop | `tell(actor, WsClosed)` then `tell(actor, PoisonPill)` after grace period |
| Actor stops normally | framework calls `$conn->close(1000)` |
| Actor crashes | supervision strategy applies; `$conn->close(1011, 'server error')` |
| Heartbeat | framework sends ping every `pingInterval`; missed pong → close |

The actor's mailbox is the connection's inbound queue. Backpressure on the actor = TCP-level backpressure on the WebSocket.

## Error handling

### Exception → response pipeline

A user-supplied `errorMapper` decides the final response. Default mappings:

| Source exception | HTTP response |
|---|---|
| `RouteRejection` (path/method) | trie produces 404 / 405 directly |
| `BodyParseException` | 400 + structured error envelope |
| `ExtractorRejection` | 400 + which segment / param failed |
| `AskTimeoutException` | 504 + actor name (config-gated) |
| `MailboxClosedException` | 503 |
| `MaxRetriesExceededException` | 503 |
| `WriterConflictException` | 409 |
| `Throwable` (uncaught) | 500 + opaque request id; logged at error |

All default responses are JSON-shaped via the negotiated marshaller — error envelopes respect `Accept`.

### Structured error envelope (default)

```json
{
  "error": "extractor_failed",
  "message": "Path segment 'orders/abc': expected integer",
  "requestId": "01HW2X9ZPKV6Q4M9R8R5J3K7Q2"
}
```

`requestId` is the existing Nexus envelope `requestId`, propagated through response header (`X-Request-Id`), logs, and dead-letter records.

### Content negotiation

```
client Accept: application/json;q=0.9, application/msgpack;q=1.0
  ├─ MarshallerRegistry::negotiate($accept)
  │     ├─ parse q-values once, cache by header string
  │     ├─ intersect client preference with registered marshallers
  │     └─ return highest-q match
  ├─ no match → 406 Not Acceptable (lists supported types)
  └─ Accept absent → registry default (application/json)
```

Cache: `array<string, Marshaller>` keyed by raw `Accept` header, FIFO-bounded to 64 entries.

`Content-Type` of inbound bodies validated by body extractors; unsupported → 415.

### Body extraction safety

- `maxRequestSize` config (default 8 MB); larger requests rejected at TCP layer (`package_max_length`).
- PSR-7 body wrapped in `LazyStream`; bytes only buffered when an extractor demands them.
- Extractors cache parsed value on `RequestCtx`; double-read parses once.

### Logging integration

PSR-3 logger via `RequestCtx::log()`:

- Structured fields: `requestId`, `method`, `path`, `route`, `status`, `durationMs`, `actor`.
- Levels: success → info, 4xx → notice, 5xx → error.
- Exceptions logged with stack at the error-mapper boundary.

### Shipped middlewares

`nexus-http` ships only:

- `BearerTokenMiddleware` — minimal header validation, no JWT lib lock-in.
- `RequestIdMiddleware` — stamps + propagates `X-Request-Id` ↔ Nexus envelope ID.
- `LoggingMiddleware` — structured access log.

Everything else (CORS, gzip, security headers) is sourced from the existing PSR-15 community ecosystem (`middlewares/cors`, `middlewares/encoder`, etc.) via `useMiddleware()`.

## Testing approach

Three layers, each with its own tool:

```
Layer 1 — Route logic  (no Swoole, no actors)         → nexus-http-testkit
Layer 2 — Route + actors (no Swoole)                  → nexus-http-testkit + StepRuntime
Layer 3 — Full server (Swoole, T3 default; T1 gated)  → integration tests
```

### Layer 1 — `RouteTestKit`

```php
$result = RouteTestKit::route($routes)
    ->get('/orders/42')
    ->withHeader('Accept', 'application/json')
    ->run();

self::assertSame(200, $result->status());
self::assertSame(['id' => 42], $result->jsonBody());
```

Builds synthetic PSR-7 requests; runs `$routes->run($ctx)` directly; returns a `RouteResult` wrapper. No Swoole, no socket, no actor system. For routes that `ask()`:

```php
$result = RouteTestKit::route($routes)
    ->withActorStub('orders', fn(object $msg) => match ($msg::class) {
        GetOrder::class => new Order($msg->id, 'pending'),
    })
    ->get('/orders/42')
    ->run();
```

### Layer 2 — Route + actors via `StepRuntime`

```php
$runtime = new StepRuntime();
$system = ActorSystem::create('test', $runtime);
$system->spawn(Props::fromBehavior($orderBehavior), 'orders');

$result = RouteTestKit::route($routes)
    ->withSystem($system)
    ->post('/orders')
    ->withJsonBody(['sku' => 'X', 'qty' => 2])
    ->run();

$runtime->drain();
self::assertSame(201, $result->status());
```

### Layer 3 — Integration tests against running Swoole

```php
$bootstrap = HttpServerBootstrap::dev($routes)
    ->host('127.0.0.1')->port(0)
    ->onSystemReady(fn($sys) => $sys->spawn($orderProps, 'orders'));

TestServer::run($bootstrap, function (string $baseUrl): void {
    $client = new SwooleHttpClient();
    $response = $client->get("{$baseUrl}/orders/42");
    self::assertSame(200, $response->getStatusCode());
});
```

`TestServer::run()` lives in `tests/Integration/Http/` (not in `nexus-http-testkit` — it requires Swoole). T3 mode is default for integration tests; T1 tests gated behind `make test-http-threaded`.

### What gets tested where

| Concern | Layer | Why |
|---|---|---|
| Path/method matching | 1 | Pure trie logic |
| Extractor success/failure | 1 | Pure parsing |
| Marshaller selection | 1 | Pure registry |
| `useMiddleware()` order | 1 | Synthetic request |
| Error mapper output | 1 | Throw + assert |
| Actor `ask()` happy path | 2 | Real behavior, deterministic step |
| `ask()` timeout → 504 | 2 | StepRuntime + manual clock advance |
| WebSocket lifecycle | 3 | Needs upgrade negotiation, frame I/O |
| Cross-thread `ask` via hash ring | 3 (T1) | Needs real ZTS threads |
| Graceful shutdown / drain | 3 | Needs server lifecycle |

### WebSocket testing (Phase 3)

```php
WebSocketTestKit::connect($routes, '/chat/01HW...UID')
    ->expectOpen()
    ->sendText('hello')
    ->expectFrameMatching(fn($f) => $f->isText() && str_contains($f->text(), 'hi'))
    ->close();
```

### Coverage strategy

- `nexus-http`: 90%+ method coverage (matches project policy).
- `nexus-http-swoole`: 60–70% target — extension-coupled glue is hard to mock; rely on Layer 3 tests for the rest.
- `nexus-http-testkit`: 90%+ — testkit must be reliable.

Mutation testing: `nexus-http` and `nexus-http-testkit` included; `nexus-http-swoole` excluded (extension-coupled code is mutation-unfriendly).

## Phasing

Each phase ships a working, mergeable slice with a clear done gate.

### Phase 1 — `nexus-http` core + T3 dev server

**Scope**

- New package `nexus-http`: `Route`, directives, extractors, `RequestCtx`, `MarshallerRegistry`, `JsonValinorMarshaller`, default error mapper, three baseline middlewares, `RouteRejection`/`ExtractorRejection`/etc.
- New package `nexus-http-swoole` with **only** `HttpServerBootstrap::dev()` (T3, single-coroutine, `SWOOLE_BASE`).
- New package `nexus-http-testkit`: Layer 1 + Layer 2.
- Psalm plugin hook: `path()` extractor-arity / closure-param-type validator.
- Deptrac rule: `nexus-http` must not depend on Swoole/runtime packages.

**Done gate**

- Hello world + a CRUD example run end-to-end via T3.
- 90%+ method coverage on `nexus-http` and `nexus-http-testkit`.
- Reference example app under `examples/http-orders/` demonstrating the full DSL surface.

### Phase 2 — T1 (SWOOLE_THREAD) + WorkerPool integration

**Scope**

- `HttpServerBootstrap::threaded()` constructor.
- `ThreadHttpRunnable` (Swoole `Thread\Runnable`).
- `Thread\Pool` lifecycle: master → N threads claim IDs atomically → ActorSystem + WorkerNode per thread → shared `Thread\Map` directory + per-thread `Thread\Queue`.
- Hash-ring placement always-on (deterministic by name, reusing existing `ConsistentHashRing`).
- `actorFor()` resolves local-or-remote transparently via existing `WorkerActorRef`.
- DispatchTrie compiled once in master, shared across threads.
- Graceful drain on `SIGTERM`.

**Done gate**

- 4-thread example app with cross-thread `ask()`.
- Integration tests (`make test-http-threaded`): kernel-distributed accept, hash-ring placement, cross-thread `ask` round-trip latency, thread-crash supervision.
- Bench: `wrk` HTTP benchmark recorded as CI artifact; regression > 15% blocks merge.

### Phase 3 — WebSocket actor-per-connection

**Scope**

- `nexus-http`: `webSocket()` directive, `WsConnection` interface, `Ws*Message` types, optional `marshalAs` hint.
- `nexus-http-swoole`: `SwooleWsConnection` impl wired to `Swoole\WebSocket\Server`, upgrade handshake, frame routing into actor mailbox, ping/pong, graceful close.
- T1 + T3 both supported. Connection actors local; domain actors via hash ring.
- `nexus-http-testkit`: `WebSocketTestKit`.

**Done gate**

- Chat example: `examples/http-chat/` with multiple rooms, reconnect, broadcast.
- Frame-roundtrip integration test in T3 + T1.
- Backpressure test: slow consumer → mailbox bounded → TCP flow control.

### Phase 4 — out of v1 hard scope

Placeholders for future planning:

- HTTP client (`nexus-http-client`).
- SSE directive (`sse(...)`); can fold into `nexus-http`.
- Symfony / Laravel adapter packages.
- multipart/form-data + streaming uploads.
- WebSocket subprotocols + `permessage-deflate`.
- `MsgpackMarshaller`, `IgbinaryMarshaller` opt-in packages.

## Cross-phase invariants

1. `nexus-http` has zero Swoole dependency. Enforced by Deptrac.
2. `nexus-http-testkit` has zero Swoole dependency. Enforced by Deptrac.
3. Same routes file works in T1, T3, and unit tests — no per-mode branches in user code.
4. Single `errorMapper` at the bootstrap boundary; per-route customization via `mapRejection()`.
5. PSR-7/15/17 are the data-plane types. Extractors hide them in normal use; PSR-15 middleware composes via `useMiddleware()`.
6. Hash-ring placement always-on for domain actors (Phase 2+); connection actors always local (Phase 3+).
7. Drain on shutdown — every phase respects `drainTimeout`; no abrupt teardown.

## Risks & mitigations

| Risk | Phase | Mitigation |
|---|---|---|
| `SWOOLE_THREAD` mode behavior across distros | 2 | CI matrix: Alpine + Debian images, both ZTS |
| Closure-nested DSL deep-nesting unreadable | 1 | Encourage flat composition with `concat()`; document patterns |
| Psalm plugin can't infer variadic `path()` types | 1 | Plugin hook + good error messages; fallback `@param` annotations |
| Cross-thread serialization cost dominates `ask()` | 2 | igbinary for inter-thread envelopes; bench-gated |
| WS connection storms exhaust accept threads | 3 | Document `SO_REUSEPORT` + thread sizing; max-connections cap config |

## Failure modes per topology

| Failure | T3 behavior | T1 behavior |
|---|---|---|
| Handler exception | 500 (mapped) | same, in-thread |
| `ask` timeout | 504 | 504 |
| Actor on dead thread | n/a | 503 + supervision restarts on owning thread |
| Thread crash | n/a | `Thread\Pool` restarts thread; actors respawn via `onWorkerStart` |
| Slow handler | blocks one coroutine; others continue | same; isolated per thread |
| Marshalling fails | 500 (server) or 400 (client body) | same |

## Dependency graph

```
nexus-http
  ├─ psr/http-message ^2.0
  ├─ psr/http-server-handler ^1.0
  ├─ psr/http-server-middleware ^1.0
  ├─ psr/http-factory ^1.0
  ├─ nyholm/psr7 ^1.8
  ├─ monadial/nexus-core
  └─ monadial/nexus-serialization

nexus-http-swoole
  ├─ monadial/nexus-http
  ├─ monadial/nexus-runtime-swoole
  └─ monadial/nexus-worker-pool-swoole

nexus-http-testkit
  └─ monadial/nexus-http
```
