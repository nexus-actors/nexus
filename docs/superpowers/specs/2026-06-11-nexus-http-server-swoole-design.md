# nexus-http-server-swoole + nexus-http-server-swoole-threads — design

**Date:** 2026-06-11
**Status:** Approved for implementation planning
**Scope:** Two new packages that implement `HttpServerAdapter` from `nexus-http` and add WebSocket support over Swoole, in two runtime modes: process-based workers and Swoole 6 threads. Also: performance test harness for both modes.

Out of scope: PSR-18 HTTP client, async DBAL, TLS termination, HTTP/2, hot reload of compiled apps.

---

## 1. Goal

Two server packages that consume `Monadial\Nexus\Http\App\CompiledHttpApp` and serve HTTP + WebSocket traffic via Swoole:

1. **`nexus-http-server-swoole`** — worker-mode runner (Swoole's native multi-process worker model) + PSR-7 ↔ Swoole bridge + WebSocket primitives.
2. **`nexus-http-server-swoole-threads`** — thread-mode runner (uses `nexus-worker-pool-swoole`'s Swoole 6 Thread\Pool via `SO_REUSEPORT`) + `WorkerNodePoolSingletonSpawner` adapter. Reuses everything from the worker-mode package.

Pool-singleton actors are available only in thread mode; worker mode is for stateless apps + worker-local/per-request actor lifecycles. WebSocket channel-actor mode shares the same constraint: worker-local in worker mode, pool-singleton in thread mode.

## 2. Decisions locked

| # | Decision |
|---|---|
| 1 | Two distinct runners (worker, thread) — no unified `SwooleHttpApp` base class |
| 2 | Two packages: `nexus-http-server-swoole` (worker + bridge + WebSocket primitives), `nexus-http-server-swoole-threads` (thread runner + `PoolSingletonSpawner` bridge) |
| 3 | Closure factory bootstrap: `Closure(ActorSystem): CompiledHttpApp` (worker), `Closure(ActorSystem, WorkerNode): CompiledHttpApp` (thread) |
| 4 | PSR-7 ↔ Swoole bridge lives in the worker-mode package; the threads package reuses it |
| 5 | Streaming detection: `StreamInterface::getSize() === null` triggers per-chunk Swoole `write()`; known-size bodies use single `end()` |
| 6 | WebSocket: TWO modes per route: per-connection handler factory (default) OR channel-keyed actor (path param defines actor identity) |
| 7 | Worker mode + channel-actor route: actor is `WorkerLocal` — no cross-worker state sharing. Documented limitation. |
| 8 | Thread mode + channel-actor route: actor is `PoolSingleton` — single actor per channel across all threads, broadcast routed via `WorkerTransport` |
| 9 | Channel actor name: `ws-channel-{xxh3(rawKey)}` — stable hash avoids URL-character pitfalls |
| 10 | Signal handling: worker-mode installs SIGTERM/SIGINT by default; thread mode delegates to `WorkerPoolApp` |
| 11 | Performance test harness in `tests/Performance/HttpSwoole/` and `tests/Performance/HttpSwooleThreads/` — assertions are regression guards, not absolute targets |

## 3. Package layout

### `nexus-http-server-swoole/`

```
packages/nexus-http-server-swoole/
├── composer.json
├── README.md
└── src/
    ├── Server/
    │   ├── SwooleWorkerHttpServer.php       # Static run() entry
    │   ├── SwooleWorkerConfig.php           # Immutable config
    │   └── SwooleHttpServerAdapter.php      # HttpServerAdapter impl
    ├── Bridge/
    │   ├── SwooleRequestTranslator.php
    │   ├── SwooleResponseWriter.php
    │   └── SwooleStreamingDetector.php
    ├── App/
    │   ├── SwooleHttpApp.php                # Wraps HttpApp; adds webSocket(), webSocketChannel()
    │   └── SwooleCompiledHttpApp.php        # Extends CompiledHttpApp; exposes WebSocketRouter
    ├── WebSocket/
    │   ├── WebSocketRoute.php
    │   ├── WebSocketHandler.php             # public interface (onMessage, onClose)
    │   ├── WebSocketContext.php             # public interface (send, close, id, request, ...)
    │   ├── LocalWebSocketContext.php        # worker-mode impl
    │   ├── WebSocketFrame.php               # text|binary|ping|pong|close value object
    │   ├── WebSocketRegistry.php            # path → handler/actor wiring
    │   ├── WebSocketRouter.php              # FastRoute over upgrade requests
    │   ├── ConnectionTable.php              # fd → (handler|channelKey) + ctx
    │   ├── ChannelActorRegistry.php         # channelKey → ActorRef cache
    │   └── Message/                         # Channel actor messages
    │       ├── ChannelConnectionOpened.php
    │       ├── ChannelMessageReceived.php
    │       └── ChannelConnectionClosed.php
    ├── Signal/
    │   └── ShutdownSignalHandler.php
    └── Exception/
        ├── SwooleHttpServerException.php
        └── WebSocketUpgradeFailedException.php
```

### `nexus-http-server-swoole-threads/`

```
packages/nexus-http-server-swoole-threads/
├── composer.json
├── README.md
└── src/
    ├── Server/
    │   ├── SwooleThreadHttpServer.php       # onThread() entry
    │   └── SwooleThreadConfig.php
    ├── Actor/
    │   └── WorkerNodePoolSingletonSpawner.php
    ├── WebSocket/
    │   ├── ThreadAwareWebSocketContext.php  # cross-thread send via WorkerTransport
    │   └── Message/
    │       └── WebSocketFramePush.php       # system message for cross-thread frame push
    └── Exception/
        └── SwooleThreadHttpServerException.php
```

### Dependencies

**`nexus-http-server-swoole`:**
```json
{
  "require": {
    "php": ">=8.5",
    "ext-swoole": "^6.0",
    "nexus-actors/core": "dev-main",
    "nexus-actors/http": "dev-main",
    "nexus-actors/runtime": "dev-main",
    "nexus-actors/runtime-swoole": "dev-main",
    "nyholm/psr7": "^1.8",
    "psr/http-message": "^2.0",
    "psr/http-server-handler": "^1.0",
    "psr/log": "^3.0"
  }
}
```

**`nexus-http-server-swoole-threads`:**
```json
{
  "require": {
    "php": ">=8.5",
    "ext-swoole": "^6.0",
    "nexus-actors/http": "dev-main",
    "nexus-actors/http-server-swoole": "dev-main",
    "nexus-actors/worker-pool": "dev-main",
    "nexus-actors/worker-pool-swoole": "dev-main"
  }
}
```

### Deptrac layers

Add `HttpServerSwoole` and `HttpServerSwooleThreads`:

```yaml
- name: HttpServerSwoole
  collectors:
    - type: directory
      value: packages/nexus-http-server-swoole/src/.*
- name: HttpServerSwooleThreads
  collectors:
    - type: directory
      value: packages/nexus-http-server-swoole-threads/src/.*

ruleset:
  HttpServerSwoole:
    - Core
    - Runtime
    - Http
    - RuntimeSwoole
  HttpServerSwooleThreads:
    - Core
    - Runtime
    - Http
    - HttpServerSwoole
    - WorkerPool
    - WorkerPoolSwoole
```

## 4. Worker mode runner

### User-facing API

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerHttpServer;

SwooleWorkerHttpServer::run(
    config: SwooleWorkerConfig::bind('0.0.0.0:8080')
        ->workers(4)
        ->reactorThreads(2)
        ->maxRequest(10_000)
        ->shutdownTimeout(Duration::seconds(10))
        ->installSignalHandlers(true),
    factory: static function (ActorSystem $system): CompiledHttpApp {
        return HttpApp::create($system)
            ->get('/hello', static fn() => Response::ok())
            ->compile();
    },
);
```

### `SwooleWorkerConfig` — immutable value object

```php
final readonly class SwooleWorkerConfig
{
    public static function bind(string $host, int $port = 8080): self;
    public function workers(int $n): self;
    public function reactorThreads(int $n): self;
    public function maxRequest(int $n): self;            // 0 = unlimited
    public function maxConn(int $n): self;
    public function dispatchMode(int $mode): self;
    public function shutdownTimeout(Duration $d): self;
    public function installSignalHandlers(bool $b): self;
    public function logger(LoggerInterface $log): self;
    public function logFile(string $path): self;
}
```

### Flow

```
1. SwooleWorkerHttpServer::run(config, factory)
2. ext-swoole creates the master process and N worker processes
3. PER WORKER PROCESS:
   a. WorkerStart event:
      → new SwooleRuntime
      → ActorSystem::create("http-worker-{$id}", $runtime)
      → $app = $factory($system) — typically a SwooleCompiledHttpApp if WebSocket routes are present
      → cache app in per-process slot keyed by spl_object_id($server)
      → register Request, Open, Message, Close event handlers
   b. Request events drive PSR-7 pipeline (compiled, hot path)
   c. WebSocket events route through WebSocketRegistry
   d. WorkerStop event:
      → reject new HTTP requests + new WebSocket handshakes
      → send code 1001 close to all WebSocket connections
      → wait up to shutdownTimeout for actors to drain
      → $system->shutdown(Duration::seconds(5))
4. Master exits when all workers exit
```

### Restart-loop protection

If `$factory($system)` throws at `WorkerStart`, the runner records the failure in a per-server atomic counter. After three failures within 5 seconds, the runner calls `$server->shutdown()` on the master to avoid infinite restart loops. Clear log:

> *"HTTP factory failed during worker boot N times in 5s — shutting down master to avoid restart loop."*

### `SwooleHttpServerAdapter` — `HttpServerAdapter` impl

Implements the contract from `nexus-http`. Used internally by `SwooleWorkerHttpServer::run()` and directly by tests (the contract test from nexus-http Phase 14 wires this).

```php
final class SwooleHttpServerAdapter implements HttpServerAdapter
{
    public function __construct(private readonly \Swoole\Http\Server $server) {}
    public function serve(RequestHandlerInterface $app): void;
    public function shutdown(Duration $timeout): void;
}
```

## 5. Thread mode runner

### User-facing API

Thread mode reuses the existing `WorkerPoolApp` pattern from `nexus-worker-pool-swoole`. The HTTP runner attaches to each thread via `onThread()` called from `configure()`:

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadConfig;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadHttpServer;
use Monadial\Nexus\Http\Server\Swoole\Threads\Actor\WorkerNodePoolSingletonSpawner;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolApp;

final class ChatApp extends WorkerPoolApp
{
    protected function configure(WorkerNode $node): void
    {
        SwooleThreadHttpServer::onThread(
            node: $node,
            config: SwooleThreadConfig::bind('0.0.0.0:8080'),
            factory: static function (ActorSystem $system, WorkerNode $node): CompiledHttpApp {
                return SwooleHttpApp::wrap(HttpApp::create($system))
                    ->withPoolSingletonSpawner(new WorkerNodePoolSingletonSpawner($node))
                    ->webSocketChannel(
                        path: '/ws/channel/{channelId}',
                        props: ChannelBehavior::props(),
                        keyFrom: 'channelId',
                    )
                    ->compile();
            },
        );
    }
}

ChatApp::run(WorkerPoolConfig::withThreads(8));
```

### `SwooleThreadConfig`

```php
final readonly class SwooleThreadConfig
{
    public static function bind(string $host, int $port = 8080): self;
    public function maxRequest(int $n): self;
    public function shutdownTimeout(Duration $d): self;
    public function logger(LoggerInterface $log): self;
}
```

Fewer knobs than worker mode — workers/signals owned by `WorkerPoolApp`.

### Flow

```
1. MyApp extends WorkerPoolApp; calls MyApp::run(WorkerPoolConfig::withThreads(N))
2. nexus-worker-pool-swoole boots Swoole\Thread\Pool with N threads
3. PER THREAD:
   a. WorkerRunnable claims a thread id atomically
   b. ActorSystem + SwooleRuntime built (existing behavior)
   c. configure(WorkerNode) → SwooleThreadHttpServer::onThread(node, config, factory)
      → $app = $factory($system, $node) — typically wraps SwooleHttpApp with PoolSingletonSpawner
      → new Swoole\Http\Server(host, port, SWOOLE_BASE, SWOOLE_SOCK_TCP)
      → $server->set(['enable_reuse_port' => true, 'worker_num' => 0])
      → register Request, Open, Message, Close handlers
      → also register a WorkerTransport listener for WebSocketFramePush system messages
      → $server->start() runs the loop on this thread's scheduler
   d. Thread shutdown:
      → Thread\Pool signals shutdown
      → each thread's $server->shutdown()
      → existing connection drain + actor shutdown (WorkerPoolApp behavior)
```

### `WorkerNodePoolSingletonSpawner`

The entire point of the threads package. Bridges `WorkerNode::spawn` to nexus-http's `PoolSingletonSpawner`:

```php
namespace Monadial\Nexus\Http\Server\Swoole\Threads\Actor;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Actor\PoolSingletonSpawner;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Override;

final readonly class WorkerNodePoolSingletonSpawner implements PoolSingletonSpawner
{
    public function __construct(private WorkerNode $node) {}

    #[Override]
    public function spawn(Props $props, string $name): ActorRef
    {
        return $this->node->spawn($props, $name);
    }
}
```

## 6. PSR-7 ↔ Swoole bridge

### `SwooleRequestTranslator`

Maps `Swoole\Http\Request` to `nyholm/psr7\ServerRequest`. Body is read into memory once via `rawContent()` (Swoole pre-buffers large bodies to disk). Headers come pre-lowercased from Swoole; PSR-7 impls normalize. Server params (`REMOTE_ADDR`, `REQUEST_TIME_FLOAT`, etc.) pass through unchanged.

### `SwooleResponseWriter`

Translates a PSR-7 response to a Swoole response. Status + headers sent first, then body.

```php
public static function write(ResponseInterface $psr7, \Swoole\Http\Response $swoole): void
{
    $swoole->status($psr7->getStatusCode(), $psr7->getReasonPhrase());

    foreach ($psr7->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            $swoole->header($name, $value);
        }
    }

    $statusCode = $psr7->getStatusCode();
    if ($statusCode === 204 || $statusCode === 304) {
        $swoole->end();
        return;
    }

    $body = $psr7->getBody();
    if (SwooleStreamingDetector::isStreaming($body)) {
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                break;
            }
            $swoole->write($chunk);
        }
        $swoole->end();
        return;
    }

    $swoole->end((string) $body);
}
```

### `SwooleStreamingDetector`

```php
final class SwooleStreamingDetector
{
    public static function isStreaming(StreamInterface $body): bool
    {
        return $body->getSize() === null;
    }
}
```

`IteratorStream` (from `nexus-http`) returns `null` because chunks are pulled on demand. Concrete file/string streams return the actual size.

## 7. WebSocket support

### Handler mode

```php
SwooleHttpApp::wrap($app)
    ->webSocket('/echo', static fn(WebSocketContext $ctx) => new EchoHandler($ctx))
    ->compile();

final class EchoHandler implements WebSocketHandler
{
    public function __construct(private WebSocketContext $ctx) {}

    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send($frame->text);
    }

    public function onClose(int $code): void {}
}
```

Factory called once per connection on `Open`. Handler instance lives in `ConnectionTable` keyed by `$fd`. `onMessage` dispatched per frame. `onClose` on disconnect. Per-connection state lives on the handler.

### Channel-actor mode

```php
SwooleHttpApp::wrap($app)
    ->webSocketChannel(
        path: '/ws/channel/{channelId}',
        props: ChannelBehavior::props(),
        keyFrom: 'channelId',
    )
    ->compile();
```

The framework spawns one actor per `channelId` value. Multiple connections to the same channel share that actor.

**Actor name format:** `ws-channel-{xxh3(rawKey)}` — stable hash avoids URL-character pitfalls.

**Worker mode:** spawned as `WorkerLocal` → per-worker actor.
**Thread mode:** spawned as `PoolSingleton` via `PoolSingletonSpawner` → hash-routed across all threads.

**Channel actor messages** (`Monadial\Nexus\Http\Server\Swoole\WebSocket\Message\`):

```php
final readonly class ChannelConnectionOpened
{
    public function __construct(
        public int $fd,
        public WebSocketContext $ctx,
        public ServerRequestInterface $request,
    ) {}
}

final readonly class ChannelMessageReceived
{
    public function __construct(public int $fd, public WebSocketFrame $frame) {}
}

final readonly class ChannelConnectionClosed
{
    public function __construct(public int $fd, public int $closeCode) {}
}
```

**Sample channel actor** (user-supplied):

```php
final class ChannelBehavior
{
    public static function props(): Props
    {
        return Props::fromBehavior(Behavior::withState(
            initialState: ['ctxByFd' => []],
            receive: static fn(ActorContext $ctx, object $msg, array $state) => match (true) {
                $msg instanceof ChannelConnectionOpened =>
                    BehaviorWithState::next(['ctxByFd' => [...$state['ctxByFd'], $msg->fd => $msg->ctx]]),
                $msg instanceof ChannelMessageReceived =>
                    self::broadcast($state, $msg),
                $msg instanceof ChannelConnectionClosed =>
                    self::removeAndMaybeStop($state, $msg->fd),
                default => BehaviorWithState::same(),
            },
        ));
    }

    private static function broadcast(array $state, ChannelMessageReceived $msg): BehaviorWithState
    {
        foreach ($state['ctxByFd'] as $fd => $ctx) {
            if ($fd !== $msg->fd) {
                $ctx->send($msg->frame->text);
            }
        }
        return BehaviorWithState::same();
    }

    private static function removeAndMaybeStop(array $state, int $fd): BehaviorWithState
    {
        unset($state['ctxByFd'][$fd]);
        if ($state['ctxByFd'] === []) {
            return BehaviorWithState::stopped();
        }
        return BehaviorWithState::next($state);
    }
}
```

### `WebSocketContext`

```php
interface WebSocketContext
{
    public function id(): int;                            // fd
    public function request(): ServerRequestInterface;    // original upgrade request
    public function send(string $text): void;             // text frame
    public function sendBinary(string $data): void;
    public function sendPing(): void;
    public function close(int $code = 1000, string $reason = ''): void;
}
```

Two impls:
- `LocalWebSocketContext` (worker mode + same-thread thread mode): pushes via local `Swoole\WebSocket\Server::push($fd, $payload)`
- `ThreadAwareWebSocketContext` (thread mode): if current thread === owning thread → local push; else → send `WebSocketFramePush($threadId, $fd, $payload)` via `WorkerTransport`

### `WebSocketFramePush` (threads package)

A system message handled by each thread's `WebSocketRouter`:

```php
final readonly class WebSocketFramePush
{
    public function __construct(
        public int $threadId,
        public int $fd,
        public string $payload,
        public bool $binary = false,
    ) {}
}
```

On receive, the thread's `WebSocketRouter` looks up `$fd` in its `ConnectionTable` and pushes via local Swoole server. If `$fd` isn't found (race: connection closed between send and receive), the frame is silently dropped and a debug log is emitted.

### Cross-thread broadcast cost

A `ctx->send(...)` to a remote-thread fd:
1. Allocate `WebSocketFramePush` value object
2. `WorkerTransport::send` (existing nexus-worker-pool primitive)
3. Receiving thread's queue → message handler → `Swoole\WebSocket\Server::push`

Roughly one queue allocation + one channel push + one Swoole syscall. Sub-millisecond per frame.

## 8. Lifecycle, signals, errors

### Worker mode

| Event | Behavior |
|---|---|
| `WorkerStart` | Build `ActorSystem` + `SwooleRuntime`, invoke factory, cache `CompiledHttpApp`, register event handlers |
| `Request` | PSR-7 translate → `$app->handle()` → write response |
| `Open` (WebSocket) | Match path against `WebSocketRouter`; handler-mode → invoke factory + store in `ConnectionTable`; channel-mode → look up/spawn channel actor, send `ChannelConnectionOpened` |
| `Message` (WebSocket) | Look up by `$fd`; handler-mode → `onMessage`; channel-mode → send `ChannelMessageReceived` to channel actor |
| `Close` (WebSocket) | Handler-mode → `onClose`; channel-mode → send `ChannelConnectionClosed`; remove from `ConnectionTable` |
| `WorkerStop` | Reject new requests + handshakes; send code 1001 close to all WebSocket connections; wait for actors to drain (up to `shutdownTimeout`); `$system->shutdown(5s)` |
| `SIGTERM`/`SIGINT` | `$server->shutdown()` (if `installSignalHandlers` true) |

### Thread mode

| Event | Behavior |
|---|---|
| `WorkerStart` (per thread) | `WorkerNode` already booted by `WorkerPoolApp`; `configure(node)` → `SwooleThreadHttpServer::onThread` builds the local `Swoole\Http\Server` and attaches the HTTP+WebSocket event handlers |
| Same HTTP/WebSocket events as worker mode | Handled per-thread |
| `WebSocketFramePush` (incoming) | Router pushes to local fd via local Swoole server |
| Thread shutdown | `$server->shutdown()`; actor lifecycle owned by `WorkerPoolApp` |
| `SIGTERM`/`SIGINT` | Owned by `WorkerPoolApp` — thread runner doesn't install its own |

### Request loop error handling

```php
$server->on('Request', static function ($req, $res) use ($app, $logger): void {
    try {
        $psr7 = SwooleRequestTranslator::toPsr7($req);
        SwooleResponseWriter::write($app->handle($psr7), $res);
    } catch (Throwable $e) {
        $logger->error('HTTP request translation/write failed', ['exception' => $e]);
        if (!$res->isWritable()) {
            return;
        }
        $res->status(500);
        $res->end('Internal Server Error');
    }
});
```

Handler-level exceptions are caught by `ExceptionHandlerMiddleware` inside `CompiledHttpApp` (Phase 9 of nexus-http core). The Swoole-level catch above only fires for unrecoverable cases (malformed Swoole request, write to closed connection).

### WebSocket error handling

| Failure | Behavior |
|---|---|
| Handshake rejected (user-installed `UpgradeGate`) | `Handshake` event returns 401; no `Open` fires |
| Factory throws in handler mode | Log error; send code 1011 (internal error) close; remove from `ConnectionTable` |
| Channel actor's `ChannelConnectionOpened` throws | Log error; close connection with 1011; do not retain in `ConnectionTable` |
| `WebSocketFramePush` arrives for closed fd | Drop silently; debug log |
| Cross-thread broadcast for non-existent fd | Drop silently; debug log |

### Restart loop protection

Worker mode: atomic counter; 3 failures in 5s → master shutdown.
Thread mode: thread-local counter; 3 failures in 5s → pool-level shutdown.

## 9. Performance testing

Four perf tests, two per mode:

### Locations

```
tests/Performance/HttpSwoole/
├── WorkerHttpThroughputTest.php
└── WorkerWebSocketBroadcastTest.php

tests/Performance/HttpSwooleThreads/
├── ThreadHttpThroughputTest.php
└── ThreadWebSocketChannelBroadcastTest.php
```

### Methodology

Each test boots a real server in the `php-swoole` container, drives load from inside the same process (Swoole client coroutines), records latency/throughput samples, asserts a regression threshold.

### Assertions

| Test | Workload | Assertion |
|---|---|---|
| `WorkerHttpThroughputTest` | 4 workers, 10k requests, 100 concurrent | P99 < 5ms, throughput > 5000 rps |
| `WorkerWebSocketBroadcastTest` | 1 worker, 100 connections to same channel, broadcast 1000 messages | P99 broadcast fanout < 50ms |
| `ThreadHttpThroughputTest` | 4 threads, 10k requests, 100 concurrent | P99 < 5ms, throughput > 5000 rps |
| `ThreadWebSocketChannelBroadcastTest` | 4 threads, 100 connections distributed across threads, channel actor hash-routed to one thread | P99 cross-thread broadcast < 50ms |

Thresholds are regression guards — should pass ~10× under typical dev-machine load; tighten over time.

### Reporting

Each test emits a JSON summary to `tests/Performance/HttpSwoole/.results/{testName}.{timestamp}.json` for trend tracking. CI integration (PR delta comments) is out of scope for v1.

### CI

Both perf testsuites added to `phpunit.xml` as standalone testsuites:

```xml
<testsuite name="performance-http-swoole">
    <directory>tests/Performance/HttpSwoole</directory>
</testsuite>
<testsuite name="performance-http-swoole-threads">
    <directory>tests/Performance/HttpSwooleThreads</directory>
</testsuite>
```

Run on demand via `make perf-http-swoole`; not on every PR (perf tests are noisy).

## 10. Testing strategy

### Layers per package

| Layer | Location | Container | Purpose |
|---|---|---|---|
| Unit (bridge) | `tests/Unit/Bridge/` | none | Translator + writer + detector |
| Unit (config) | `tests/Unit/Server/` | none | Immutable config invariants |
| Unit (signal) | `tests/Unit/Signal/` | none | Shutdown handler against fake server |
| Unit (WebSocket) | `tests/Unit/WebSocket/` | none | Handler factory; channel actor wiring; connection table |
| Contract | `tests/Integration/Swoole/SwooleHttpServerAdapterContractTest.php` | php-swoole | Extends abstract `HttpServerAdapterContractTest` from nexus-http; real round-trip |
| Integration (worker) | `tests/Integration/Swoole/` | php-swoole | Real Swoole server end-to-end |
| Integration (thread) | `tests/Integration/Swoole/` (threads package) | php-swoole + ZTS | WorkerPoolApp + thread runner end-to-end |

### Coverage

| Test | Asserts |
|---|---|
| `worker_mode_serves_compiled_http_app` | HTTP request returns expected response |
| `worker_mode_per_request_actor_lifecycle` | Per-request actor spawned + disposed across multiple requests |
| `worker_mode_streaming_response_delivers_chunks_incrementally` | SSE/NDJSON endpoint chunks before `end()` |
| `worker_mode_shutdown_drains_in_flight_requests` | Long-running request completes during shutdown |
| `worker_mode_websocket_handler_echoes_message` | Handler-mode WebSocket round-trip |
| `worker_mode_websocket_channel_broadcasts_within_worker` | Two connections to same channel; broadcast reaches both |
| `thread_mode_serves_compiled_http_app` | Thread-mode HTTP request answered |
| `thread_mode_pool_singleton_routes_via_ring` | Singleton actor accessed identically from any thread |
| `thread_mode_so_reuseport_load_balances` | 100 requests distributed across threads |
| `thread_mode_websocket_channel_actor_is_pool_singleton` | Two connections on different threads share the same channel actor instance |
| `thread_mode_websocket_cross_thread_broadcast` | Channel actor on thread X broadcasts to fds on threads Y, Z |

### Mocks

Swoole `Http\Request`/`Response` and `WebSocket\Server` are PHP classes with public properties. Anonymous-class inline mocks for one-off tests; `tests/Support/Fake*` classes when reused.

## 11. PSR compliance

| PSR | Compliance |
|---|---|
| PSR-3 (Logger) | Consumes via injected `LoggerInterface`; default `NullLogger` |
| PSR-7 (HTTP messages) | Consumes via `nyholm/psr7` |
| PSR-15 (Middleware + Handler) | Consumes — `CompiledHttpApp` is `RequestHandlerInterface` |

## 12. Out of scope for v1

| Item | Disposition |
|---|---|
| TLS / HTTP/2 / HTTP/3 | Use reverse proxy (nginx/Caddy/HAProxy) |
| Process supervision (daemonize, PID files) | systemd / supervisord / docker |
| Hot reload of `CompiledHttpApp` without worker restart | Use `max_request` recycle for now |
| Per-request execution timeout | Pass through Swoole's `request_timeout` server option |
| Cross-worker WebSocket broadcast (worker mode) | Future: Swoole Table or pub/sub adapter |
| WebSocket compression (permessage-deflate) | v2 |
| WebSocket subprotocol negotiation beyond basic header echo | v2 |
| Long-lived HTTP/2 streams as channel-actor messages | Out — orthogonal protocol concern |
| Performance test result delta on PRs (CI integration) | Future tooling |

## 13. Open questions

None blocking. Implementation-time clarifications expected:

- Whether `xxh3` lives in PHP core or needs a polyfill (likely needs polyfill via `bin2hex(substr(hash('xxh3', $key, true), 0, 8))` or similar)
- Exact Swoole client API for the perf tests' Swoole-side load driver (Swoole's coroutine HTTP client is the natural choice)
- Whether `WebSocketContext::sendPing()` should return a `Future<Pong>` for ping/pong RTT measurement (defer — handler can do it manually)
