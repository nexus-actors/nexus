# Nexus HTTP Server WebSocket Redesign

**Date:** 2026-06-12
**Status:** Approved for planning
**Supersedes:** `2026-06-11-nexus-http-server-swoole-design.md` (WebSocket sections only)

## Background

The current implementation ships two server packages — `nexus-http-server-swoole` (worker mode) and `nexus-http-server-swoole-threads` (thread mode) — that each duplicate the WebSocket event wiring, route registration, connection table, and channel-actor registry. End users write applications with two builders:

```php
$http = HttpApp::create($system);
$http->get('/api/users', UsersController::class);

SwooleHttpApp::wrap($http, $system)
    ->webSocket('/ws/echo', static fn(WebSocketContext $ctx) => new EchoHandler($ctx))
    ->webSocketChannel('/ws/room/{id}', $roomProps, 'id')
    ->compile();
```

Pain points identified in user feedback:

1. **Two builders.** `HttpApp` and `SwooleHttpApp::wrap()` are mandatory glue; the wrap is rote and adds a second compile pass.
2. **Two distinct WebSocket modes with split APIs.** `webSocket()` takes a closure factory; `webSocketChannel()` takes a `Props` and a string key. Different mental models, hard to discover.
3. **Channel actors use raw `Behavior::receive`.** Users hand-write `match($msg)` over three system message types (`ChannelConnectionOpened`, `ChannelMessageReceived`, `ChannelConnectionClosed`) and manage the subscriber set themselves.
4. **Worker vs threads as separate packages.** Duplicated `Open/Message/Close` wiring, channel mode unsupported in threads, parallel evolution.

This redesign reworks the public API and the package layout to eliminate all four pains.

## Goals

- One builder for users that need HTTP + WebSocket: `Application`. No wrap step.
- One typed base class per handler mode — users override `onOpen`/`onMessage`/`onClose` and never see `Behavior::receive` or `match()`.
- A single runtime-agnostic WebSocket layer (`nexus-http-ws`) shared by both runners. The runners shrink to Swoole glue.
- PSR-11 + attribute-based dependency injection in handler/actor constructors. No closure factories.
- Maintain the recently-shipped thread-mode invariants: channel routes rejected at boot; restart-loop protection at parity with worker mode.

## Non-goals

- Backwards compatibility with the current `SwooleHttpApp`/`webSocketChannel` API. No real users; pre-1.0 packages. The migration is delete-and-rewrite.
- Cross-thread channel actor support. Thread mode rejects channel routes at boot, same as the current implementation. Adding cross-thread channels is v2 (out of scope).
- New transport (TCP cluster, HTTP/2 push, etc.). Scope is rework of the Swoole HTTP+WebSocket DSL.
- Changing `nexus-http`'s public surface. `HttpApp` stays as-is.

## Architecture

### Package layout

```
nexus-http                                (unchanged)
   ▲
   │
nexus-http-ws                             (NEW — DSL + WS contracts, runtime-agnostic)
   ▲                ▲
   │                │
nexus-http-server-swoole          nexus-http-server-swoole-threads
(worker-mode runner, slim)         (thread-mode runner, slim)
```

`nexus-http-ws` is a hard dependency of both Swoole runners. Any user of `SwooleWorkerServer::run()` or `SwooleThreadServer::run()` pulls in `nexus-http-ws` transitively, because both runners accept only `CompiledApplication` (the type owned by `nexus-http-ws`). Apps that want to remain on `HttpApp` directly can still do so, but they need their own server runner or PSR-7 adapter — they cannot use the Swoole runners shipped here. The trade-off is intentional: a single runner signature is simpler than two overloads, and pure-HTTP users pay only for the `Application` wrapper, which contains no WebSocket runtime cost when no `ws()`/`channel()` routes are registered (`hasWebSocketRoutes()` returns false and the runner skips registering Swoole's `Open`/`Message`/`Close` event handlers entirely).

`nexus-http` is **not** modified. WebSocket-aware code lives in `nexus-http-ws` and decorates the HTTP DSL via composition.

### Responsibilities by package

**`nexus-http-ws`**

- `Application` — **interface** defining the HTTP surface (all `HttpApp` methods) plus `compile(): CompiledApplication`.
- `HttpApplication` — concrete `Application` impl; HTTP-only. HAS-A nexus-http `HttpApp` internally and delegates everything. `compile()` returns `CompiledHttpApplication` (covariant).
- `WsApplication` — concrete `Application` impl; **decorates** another `Application` and adds WebSocket methods. All HTTP methods delegate to the wrapped `$inner`. `compile()` returns `CompiledWsApplication` (covariant).
- `CompiledApplication` — **interface** extending `Psr\Http\Server\RequestHandlerInterface` plus `hasWebSocketRoutes(): bool`.
- `CompiledHttpApplication` — concrete `CompiledApplication`; HTTP-only. `hasWebSocketRoutes()` always returns `false`.
- `CompiledWsApplication` — concrete `CompiledApplication`; HTTP + WS. Additionally exposes `webSocketRouter(): WebSocketRouter` and `dispatcher(): WebSocketDispatcher`.
- `WebSocketHandler` — abstract base for per-connection POPO handlers.
- `WebSocketChannelActor` — abstract base for per-key channel actors. Extends `StatefulActorHandler`. Provides typed `onOpened`/`onMessage`/`onClosed` and the `broadcast()` helper.
- `WebSocketContext` — interface implemented by runners.
- `WebSocketFrame`, `WebSocketRoute`, `WebSocketRouter` — value objects + dispatcher.
- `WebSocketDispatcher` — translates runtime events into handler/actor calls.
- `ConnectionTable` interface + `InMemoryConnectionTable` default impl.
- `ChannelActorRegistry` — resolve-or-spawn by key, hidden behind the dispatcher.
- `ChannelActorNameResolver` — internal, derives stable actor names from keys.
- Channel system messages — `ChannelConnectionOpened`, `ChannelMessageReceived`, `ChannelConnectionClosed`. Internal to the base class implementation; user code never references them.
- Injection attributes — `#[FromContext]` marks a constructor parameter typed as `WebSocketContext` (the parameter MUST be of that interface type; the resolver throws `RuntimeException` at handler-instantiation time on type mismatch). `#[FromActor('name')]` reused from `nexus-http`, applies to parameters typed as `ActorRef`.
- Exception types — `UnsupportedRouteException`, `DuplicateRouteException`.

**`nexus-http-server-swoole`** (worker mode, shrunk)

- `SwooleWorkerServer` — single static `run(CompiledApplication, SwooleWorkerConfig): void`.
- `SwooleWorkerConfig` — immutable builder (host, port, workers, reactor threads, max requests, max conn, dispatch mode, shutdown timeout, signal handlers, logger, log file).
- `WorkerServerRuntime` — per-worker state (system, app, connection table, channel registry, failure bucket).
- `SwooleConnectionContext` — `WebSocketContext` implementation for worker mode (direct `$server->push`).
- `Bridge/` — `SwooleRequestTranslator`, `SwooleResponseWriter`, `SwooleStreamingDetector` (unchanged).
- `Signal/ShutdownSignalHandler` (unchanged).
- `Server/SwooleHttpServerAdapter` — `HttpServerAdapter` contract implementation (unchanged).

**`nexus-http-server-swoole-threads`** (thread mode, shrunk)

- `SwooleThreadServer` — single static `run(CompiledApplication, SwooleThreadConfig): void`.
- `SwooleThreadConfig` — immutable builder.
- `ThreadServerRuntime` — per-thread state.
- `ThreadAwareConnectionContext` — `WebSocketContext` impl with cross-thread routing via `WebSocketFramePush`.
- `Actor/WorkerNodePoolSingletonSpawner` — bridges `WorkerNode::spawn` to `nexus-http`'s `PoolSingletonSpawner` (unchanged).
- `WebSocket/Message/WebSocketFramePush` — cross-thread frame envelope (unchanged).

### Dependency graph (enforced by Deptrac)

```yaml
http-ws:
  may_depend_on: [core, http]

http-server-swoole:
  may_depend_on: [core, http, http-ws, runtime-swoole]

http-server-swoole-threads:
  may_depend_on:
    [core, http, http-ws, runtime-swoole, worker-pool, worker-pool-swoole]
```

## Public API

### `Application` interface

```php
namespace Monadial\Nexus\Http\Ws;

interface Application
{
    // HTTP surface — every method nexus-http's HttpApp exposes today,
    // identical signatures. Fluent setters return `self` (covariant per impl).
    public function get(string $path, string|Closure $handler): RouteBuilder;
    public function post(string $path, string|Closure $handler): RouteBuilder;
    public function put(string $path, string|Closure $handler): RouteBuilder;
    public function patch(string $path, string|Closure $handler): RouteBuilder;
    public function delete(string $path, string|Closure $handler): RouteBuilder;
    public function group(string $prefix, Closure $register): RouteGroup;
    public function middleware(string|MiddlewareInterface $middleware): self;
    public function actor(string $name, Props $props): ActorRegistration;
    public function perRequestActor(string $name, Props $props): ActorRegistration;
    public function discover(string $directory): self;
    public function errorMode(ErrorMode $mode): self;
    public function onException(string $exceptionClass, Closure $mapper): self;
    public function withPoolSingletonSpawner(PoolSingletonSpawner $spawner): self;
    public function withMessageSerializer(MessageSerializer $serializer): self;
    public function withRouteCache(CacheInterface $cache, ?string $key = null): self;
    public function withoutDefaultExceptionHandler(): self;
    public function clearRouteCache(): void;

    public function compile(): CompiledApplication;
}
```

### `HttpApplication` — concrete HTTP-only impl

```php
namespace Monadial\Nexus\Http\Ws;

final class HttpApplication implements Application
{
    public static function create(ActorSystem $system): self;

    // All Application methods implemented by delegation to an internal
    // nexus-http HttpApp. The constructor is private; create() is the
    // single entry point and constructs the internal HttpApp.

    public function compile(): CompiledHttpApplication;  // covariant return
}
```

### `WsApplication` — decorator over `Application`

```php
namespace Monadial\Nexus\Http\Ws;

final class WsApplication implements Application
{
    /** Decorate any existing Application — usually an HttpApplication. */
    public static function decorate(Application $inner): self;

    /** Convenience shortcut equivalent to decorate(HttpApplication::create($system)). */
    public static function create(ActorSystem $system): self;

    // All Application methods delegated to $inner, returning $this so the
    // fluent chain stays on the WsApplication type. Example:
    //   public function get(string $path, string|Closure $handler): RouteBuilder
    //   {
    //       return $this->inner->get($path, $handler);
    //   }
    //   public function middleware(string|MiddlewareInterface $m): self
    //   {
    //       $this->inner->middleware($m);
    //       return $this;
    //   }

    // WebSocket additions:
    /** @param class-string<WebSocketHandler> $handlerClass */
    public function ws(string $path, string $handlerClass): self;

    /** @param class-string<WebSocketChannelActor> $actorClass */
    public function channel(string $path, string $actorClass, string $key): self;

    /** @param Closure(Throwable, WebSocketContext): void $mapper */
    public function onWebSocketException(Closure $mapper): self;

    public function compile(): CompiledWsApplication;  // covariant return
}
```

**Why decorator, not subclass:** keeps `HttpApplication` and `WsApplication` as siblings under one interface, lets users pass any `Application` (including pre-configured / pre-decorated ones) into `WsApplication::decorate`, and avoids inheriting state that doesn't belong to the WebSocket layer.

### `WebSocketHandler`

```php
namespace Monadial\Nexus\Http\Ws;

abstract class WebSocketHandler
{
    // Constructor is user-defined. Container-resolved; supports:
    //   - normal PSR-11 services
    //   - #[FromContext] WebSocketContext (current connection)
    //   - #[FromActor('name')] ActorRef (any registered actor)
    //
    // Example:
    //   public function __construct(
    //       #[FromContext] private WebSocketContext $ctx,
    //       #[FromActor('chat-log')] private ActorRef $log,
    //       private LoggerInterface $logger,
    //   ) {}

    public function onOpen(): void {}

    abstract public function onMessage(WebSocketFrame $frame): void;

    public function onClose(int $code): void {}
}
```

Lifecycle: instantiated once per connection by the dispatcher (via PSR-11) when the upgrade is accepted. `onOpen` fires synchronously after construction, before any `onMessage`. `onClose` fires once on disconnect. The instance is unreferenced after `onClose` returns and is GC'd.

### `WebSocketChannelActor`

```php
namespace Monadial\Nexus\Http\Ws;

abstract class WebSocketChannelActor implements StatefulActorHandler
{
    abstract public function initialState(): mixed;

    public function onOpened(
        ActorContext $ctx,
        WebSocketContext $conn,
        mixed $state,
    ): BehaviorWithState {
        return BehaviorWithState::same();
    }

    abstract public function onMessage(
        ActorContext $ctx,
        WebSocketContext $conn,
        WebSocketFrame $frame,
        mixed $state,
    ): BehaviorWithState;

    public function onClosed(
        ActorContext $ctx,
        WebSocketContext $conn,
        int $code,
        mixed $state,
    ): BehaviorWithState {
        return BehaviorWithState::same();
    }

    /** @return list<WebSocketContext> connections attached to this channel actor */
    final protected function connections(): array;

    /** Send $text to all attached connections (optionally excluding one fd). */
    final protected function broadcast(string $text, ?int $exceptFd = null): void;

    // Final — translates Channel{Opened,MessageReceived,Closed} into the hooks above.
    final public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState;
}
```

Lifecycle: spawned by `ChannelActorRegistry` on first connection to a given key (path-param value resolved through `key:` argument). Receives `onOpened` for each new connection on its key, `onMessage` for each frame from any attached connection, `onClosed` for each disconnect. Stays alive after the last `onClosed` (idle eviction is v2; out of scope).

The base maintains the attached-connection set internally; users never touch it. `broadcast()` iterates the set and calls `$conn->send($text)`.

### Compiled application

```php
namespace Monadial\Nexus\Http\Ws;

interface CompiledApplication extends RequestHandlerInterface
{
    public function hasWebSocketRoutes(): bool;
}

final class CompiledHttpApplication implements CompiledApplication
{
    public function __construct(CompiledHttpApp $http);

    public function handle(ServerRequestInterface $request): ResponseInterface;
    public function hasWebSocketRoutes(): bool;          // always false
}

final class CompiledWsApplication implements CompiledApplication
{
    public function __construct(
        CompiledHttpApp $http,
        WebSocketRouter $ws,
        ContainerInterface $container,
    );

    public function handle(ServerRequestInterface $request): ResponseInterface;
    public function hasWebSocketRoutes(): bool;          // true iff any ws()/channel() registered

    public function webSocketRouter(): WebSocketRouter;
    public function dispatcher(): WebSocketDispatcher;
}
```

The interface extends PSR-15 so runners pass HTTP traffic straight through with one `handle()` call regardless of which concrete is in hand. Runners inspect `hasWebSocketRoutes()` to decide whether to register Swoole's `Open`/`Message`/`Close` events; when `true`, the concrete is statically known to be `CompiledWsApplication` and runners may `assert` and use `webSocketRouter()` / `dispatcher()`.

### `WebSocketDispatcher`

```php
namespace Monadial\Nexus\Http\Ws;

final class WebSocketDispatcher
{
    public function dispatchOpen(WebSocketContext $ctx, ServerRequestInterface $upgrade): void;
    public function dispatchMessage(WebSocketContext $ctx, WebSocketFrame $frame): void;
    public function dispatchClose(WebSocketContext $ctx, int $code): void;
}
```

The dispatcher is the **only** entry point runners use for WS events.

Internal behavior:

1. `dispatchOpen` matches `$upgrade->getUri()->getPath()` against the `WebSocketRouter`. On miss: `$ctx->close(1000, 'No WebSocket route')`. On hit:
   - **Handler route**: PSR-11 resolves the handler class. Injects `#[FromContext]` to `$ctx`, `#[FromActor]` via the actor registry. Calls `$handler->onOpen()`. Registers `(fd → handler+ctx)` in the connection table.
   - **Channel route**: extracts path-param value at the `key:` name from FastRoute match params. Computes a stable actor name (`ChannelActorNameResolver`). Resolves-or-spawns the channel actor (`ChannelActorRegistry`). Tells the actor a `ChannelConnectionOpened($fd, $ctx, $upgrade)`. Registers `(fd → channel-actor-ref+ctx)` in the connection table.
2. `dispatchMessage` looks up the entry by `$ctx->id()`. Calls the handler's `onMessage($frame)` or tells the channel actor `ChannelMessageReceived($fd, $frame)`. Unknown fd → silently drop.
3. `dispatchClose` looks up, calls `onClose($code)` or tells `ChannelConnectionClosed($fd, $code)`, removes from table.

### `WebSocketRouter`

```php
namespace Monadial\Nexus\Http\Ws;

final class WebSocketRouter
{
    /** @param list<WebSocketRoute> $routes */
    public static function build(array $routes): self;

    /** @return list<WebSocketRoute> */
    public function routes(): array;

    /** @return array{route: WebSocketRoute, params: array<string,string>}|null */
    public function match(string $path): ?array;

    /** Throws UnsupportedRouteException if any route has mode=channel. */
    public function assertNoChannelRoutes(): void;
}
```

`assertNoChannelRoutes()` is called by `SwooleThreadServer::run()` before the Swoole server boots. The check moves from `SwooleThreadHttpServer::assertNoChannelRoutes(WebSocketRouter)` to `WebSocketRouter::assertNoChannelRoutes()` — it's a router-level invariant, not a runner-level one.

### Runners

```php
namespace Monadial\Nexus\Http\Server\Swoole;

final class SwooleWorkerServer
{
    public static function run(CompiledApplication $app, SwooleWorkerConfig $config): void;
}

namespace Monadial\Nexus\Http\Server\Swoole\Threads;

final class SwooleThreadServer
{
    public static function run(CompiledApplication $app, SwooleThreadConfig $config): void;
}
```

Both static. Both block until the server shuts down. Both wire Swoole `Request/WorkerStart/WorkerStop` events to `CompiledApplication::handle(...)` for HTTP. WebSocket event wiring is conditional: if `$app->hasWebSocketRoutes()` returns `true`, the runner asserts `$app instanceof CompiledWsApplication` and wires `Open/Message/Close` to `$app->dispatcher()->dispatch...`. Otherwise the WS events are never registered on the Swoole server.

The threads runner additionally:

- Constructs a `WorkerNode` per thread using `Thread\Map` + `Thread\Queue` from `init_arguments`.
- Calls `$app->webSocketRouter()->assertNoChannelRoutes()` before `$server->start()`.
- Spawns the per-thread router actor and registers cross-thread router senders on the `ThreadAwareConnectionContext` factory.

## User-facing usage examples

### Pure HTTP (no WebSockets)

Users on the Swoole runners use `HttpApplication::create($system)` with only the HTTP methods. `compile()` returns a `CompiledHttpApplication` whose `hasWebSocketRoutes()` is `false`; the runner skips registering Swoole `Open`/`Message`/`Close` event handlers entirely, and the per-request hot path goes straight through `CompiledApplication::handle(...)` (a PSR-15 delegate to the internal `CompiledHttpApp`). Zero WebSocket runtime cost.

```php
$app = HttpApplication::create($system);
$app->get('/health', static fn() => Response::ok('ok'));

SwooleWorkerServer::run($app->compile(), SwooleWorkerConfig::bind('0.0.0.0', 8080)->workers(4));
```

### Handler-mode WebSocket

```php
final class EchoHandler extends WebSocketHandler
{
    public function __construct(
        #[FromContext] private readonly WebSocketContext $ctx,
        private readonly LoggerInterface $log,
    ) {}

    public function onMessage(WebSocketFrame $frame): void
    {
        $this->log->debug('echo', ['fd' => $this->ctx->id()]);
        $this->ctx->send('echo:' . $frame->text);
    }
}

$app = WsApplication::create($system);                 // shortcut
$app->get('/api/users', UsersController::class);       // HTTP delegated to inner HttpApplication
$app->ws('/ws/echo', EchoHandler::class);

SwooleWorkerServer::run($app->compile(), SwooleWorkerConfig::bind('0.0.0.0', 8080));
```

### Channel-mode actor with broadcast

```php
final class ChatRoomActor extends WebSocketChannelActor
{
    public function initialState(): array { return ['count' => 0]; }

    public function onOpened(
        ActorContext $ctx,
        WebSocketContext $conn,
        mixed $state,
    ): BehaviorWithState {
        $conn->send("Welcome — {$state['count']} active");
        return BehaviorWithState::next(['count' => $state['count'] + 1]);
    }

    public function onMessage(
        ActorContext $ctx,
        WebSocketContext $conn,
        WebSocketFrame $frame,
        mixed $state,
    ): BehaviorWithState {
        $this->broadcast($frame->text, exceptFd: $conn->id());
        return BehaviorWithState::same();
    }

    public function onClosed(
        ActorContext $ctx,
        WebSocketContext $conn,
        int $code,
        mixed $state,
    ): BehaviorWithState {
        return BehaviorWithState::next(['count' => $state['count'] - 1]);
    }
}

$app = WsApplication::create($system);
$app->channel('/ws/room/{roomId}', ChatRoomActor::class, key: 'roomId');

SwooleWorkerServer::run($app->compile(), SwooleWorkerConfig::bind('0.0.0.0', 8080));
```

### Explicit decoration (pre-configured Application)

```php
$http = HttpApplication::create($system)
    ->middleware(AuthMiddleware::class)
    ->onException(DomainException::class, $domainExceptionMapper);

$app = WsApplication::decorate($http);
$app->ws('/ws/echo', EchoHandler::class);

SwooleWorkerServer::run($app->compile(), $config);
```

### Thread mode (no channel routes)

```php
SwooleThreadServer::run(
    $app->compile(),
    SwooleThreadConfig::bind('0.0.0.0', 8080)->threads(8)->enableWebSocket(true),
);
```

If `$app` contains any `channel(...)` registration, `SwooleThreadServer::run()` throws `UnsupportedRouteException` before `$server->start()` is called.

## Connection lifecycle

### Handler mode

1. Upgrade request arrives, Swoole fires `Open`.
2. Runner builds a `WebSocketContext` for the fd.
3. Runner calls `$dispatcher->dispatchOpen($ctx, $upgradeRequest)`.
4. Dispatcher matches the route, resolves the handler class via PSR-11 (constructor injection), calls `$handler->onOpen()`.
5. Dispatcher registers `(fd → handler+ctx)` in `ConnectionTable`.
6. For each frame: Swoole fires `Message` → runner calls `$dispatcher->dispatchMessage(...)` → dispatcher routes to `$handler->onMessage($frame)`.
7. On disconnect: Swoole fires `Close` → runner calls `$dispatcher->dispatchClose(...)` → dispatcher calls `$handler->onClose($code)` and removes the entry.

### Channel mode

1. Same `Open` event arrives. Dispatcher matches a channel route.
2. Dispatcher extracts the path-param at the configured `key:` name. Resolves the actor by stable name (spawning if first connection to this key).
3. Dispatcher tells the actor `ChannelConnectionOpened($fd, $ctx, $upgradeRequest)`. The base class translates this into `onOpened($ctx, $conn, $state)`.
4. The base class adds `$conn` to its connection set so `broadcast()` and `connections()` are populated.
5. For each frame: `Message` → dispatcher tells `ChannelMessageReceived($fd, $frame)` → base translates to `onMessage(...)`.
6. On disconnect: `Close` → dispatcher tells `ChannelConnectionClosed($fd, $code)` → base translates to `onClosed(...)`, removes `$conn` from the connection set.

### Cross-thread frame push (threads only)

`ThreadAwareConnectionContext` checks whether the calling thread owns the fd. Same-thread: direct `$server->push`. Cross-thread: sends a `WebSocketFramePush` to the per-thread router actor on the owning thread, which performs the local push. This path is unchanged from the current implementation.

## Error handling

| Failure | Behavior | Rationale |
|---|---|---|
| Handler class PSR-11 resolution throws on Open | Disconnect 1011, log via PSR-3 | Per-connection failure — not a boot failure. |
| User code throws in `onOpen`/`onOpened` | Disconnect 1011, remove from table, log | Same as above. |
| User code throws in `onMessage` | Log; connection stays alive. Override via `WsApplication::onWebSocketException($mapper)` | One bad frame ≠ kill the socket. |
| User code throws in `onClose`/`onClosed` | Log only | Connection is already gone. |
| Boot: channel route + thread runner | Throw `UnsupportedRouteException` before `$server->start()` | Crash-loud at config time. |
| Runtime: WS frame on unknown fd | Silently drop | Race between Close and a queued Message. |
| Dispatcher receives an unrecognized route mode | Throw `RuntimeException` | Programmer error. |
| Factory throws during `WorkerStart` | Circuit breaker: 3 failures in 5s → `Server::shutdown()` | Unchanged from current behavior. |

The Application-level WebSocket exception mapper is opt-in. Default behavior: log via the runner's configured PSR-3 logger (`SwooleWorkerConfig::logger($psrLogger)` / `SwooleThreadConfig::logger($psrLogger)` — the same logger the runner uses for HTTP error logging), do not disconnect on `onMessage` failures.

## Testing strategy

### `nexus-http-ws` unit tests (runtime-agnostic, fast)

Roughly 50–60 test methods across:

- `HttpApplicationTest` — HTTP-only DSL delegation to internal `HttpApp`, `compile()` returns `CompiledHttpApplication`, `hasWebSocketRoutes()` is `false`.
- `WsApplicationTest` — `decorate()` and `create()` shortcut, HTTP method delegation to `$inner`, WS registration (`ws()`, `channel()`, `onWebSocketException()`), `compile()` returns `CompiledWsApplication`, duplicate path rejection, channel `key:` validation.
- `WebSocketRouterTest` — match, no-match, path param extraction, `routes()` accessor, `assertNoChannelRoutes()`.
- `WebSocketDispatcherTest` — dispatch open → handler resolved via container, dispatch open → channel actor spawned, message routing, close cleanup, unknown-fd drop, route-mode rejection. Uses `InMemoryWebSocketContext` + `InMemoryWebSocketRuntime` test doubles.
- `WebSocketHandlerLifecycleTest` — `onOpen` precedes first `onMessage`, `onClose` fires once, instance unreferenced after close.
- `WebSocketChannelActorTest` — system message translation, connection-set maintenance, `broadcast()` helper, `connections()` accessor.
- `InMemoryConnectionTableTest` — semantics, get/has/remove, attachHandler vs attachChannel.
- `ChannelActorNameResolverTest` — stability, URL-safety, collision resistance.

Coverage gate: 90% method coverage (project-wide standard).

### Runner integration tests (under Swoole)

Each runner ships ~6 integration tests, executed against a real `Swoole\Http\Server` via the existing `ForkedSwooleServerFixture`:

- HTTP round-trip (1)
- Handler-mode echo (1)
- Channel-mode broadcast — worker only; thread runner test asserts boot-time `UnsupportedRouteException` (1)
- Pool-singleton actor reached from HTTP handler — thread runner only (1)
- Cross-thread frame push — thread runner only (1)
- Shutdown timeout (1)

### Performance tests

Migrated by import-rename only. Same harness (`LatencyRecorder`, `PerfReport`, `ForkedSwooleServerFixture`):

- `WorkerHttpThroughputTest` — P99 < 5 ms over 1000 requests
- `WorkerWebSocketBroadcastTest` — P99 < 50 ms over 10 connections × 50 broadcasts
- `ThreadHttpThroughputTest` — P99 < 5 ms over 1000 requests
- `ThreadWebSocketHandlerEchoTest` (renamed from `ThreadWebSocketChannelBroadcastTest`) — P99 < 50 ms over 500 echoes

## Migration plan

This is a delete-and-rewrite. No compatibility shims, no deprecation warnings, no `@deprecated` annotations on the old types. The packages are pre-1.0 with no published tags on these APIs.

Sequence:

1. Create `packages/nexus-http-ws/` skeleton (composer.json, phpunit.xml, src/, tests/Unit/, README.md, CHANGELOG.md).
2. Add to root `composer.json` autoload + replace map and to `phpunit.xml` unit testsuite list.
3. Implement the `nexus-http-ws` package per Section 2–4 of this spec.
4. Shrink `nexus-http-server-swoole`:
   - Move `WebSocket/*` (except `LocalWebSocketContext.php`) → delete (now in `nexus-http-ws`).
   - Replace `App/SwooleHttpApp.php` and `App/SwooleCompiledHttpApp.php` — delete; the unified types live in `nexus-http-ws`.
   - Rename `LocalWebSocketContext` → `SwooleConnectionContext`, keep in worker package.
   - Rename `SwooleWorkerHttpServer` → `SwooleWorkerServer`. Inline closures shrink to dispatcher calls per Section 3.
   - `SwooleHttpServerAdapter`, `SwooleWorkerConfig`, `WorkerServerRuntime`, `Bridge/`, `Signal/` unchanged.
5. Shrink `nexus-http-server-swoole-threads` similarly. Rename `SwooleThreadHttpServer` → `SwooleThreadServer`. `ThreadAwareWebSocketContext` → `ThreadAwareConnectionContext`. `WorkerNodePoolSingletonSpawner` and `WebSocketFramePush` unchanged.
6. Update all integration tests (mass import + DSL rename: `SwooleHttpApp::wrap($http, $system)->...->compile()` → `WsApplication::decorate($http)->...->compile()` or `WsApplication::create($system)->...->compile()`, `webSocket` → `ws`, `webSocketChannel` → `channel`).
7. Update both runner READMEs and create a `nexus-http-ws` README with the quickstart examples from this spec.
8. Update `composer.json` graph and `deptrac.yaml` boundaries.

Estimated diff: net –500 LOC. Each runner sheds roughly 60–70% of its WS code; the gained code lives once in `nexus-http-ws`.

## Open questions

None. All design decisions in this document are settled by user approval during the brainstorming session on 2026-06-12.

## Out of scope (potential follow-ups)

- Idle eviction policy for channel actors with zero attached connections.
- Cross-thread channel-actor support (would require serialization-safe `WebSocketContext` transport).
- HTTP/2 server push integration.
- Per-route middleware on WebSocket upgrade requests.
- Backpressure controls on `broadcast()` for very-large channels.
