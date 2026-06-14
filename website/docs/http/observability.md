---
sidebar_position: 11
title: Observability
---

# Observability

The HTTP layer ships with the same observability primitives the rest of
Nexus uses: PSR-3 logging, PSR-14 event dispatch, and PSR-20 clock. This
page focuses on logging — what to log, how to add per-request context via
MDC, and how to do it without slowing down hot paths.

## What the Framework Logs

When you configure a `LoggerInterface` on the application via
`$app->withLogger($logger)`, the framework emits debug/info-level events
at well-defined points:

| Event | Level | Where |
|---|---|---|
| `worker start` / `thread start` | `info` | Adapter |
| `request received` | `debug` | `RouterMiddleware` |
| `route matched` | `debug` | `RouterMiddleware` (also dispatches `RouteMatched` PSR-14 event) |
| `route not found` | `info` | `RouterMiddleware` |
| `handler error` | `error` | `ExceptionHandlerMiddleware` |
| `response sent` | `debug` | Adapter |
| `worker stop` / `thread stop` | `info` | Adapter |
| `WebSocket open` / `message` / `close` | `debug` | `WebSocketDispatcher` |
| `WebSocket channel actor spawn` | `debug` | `ChannelActorRegistry` |

Most of these are debug-level — silent in production unless you bump
`minLevel(Level::Debug)`.

## Logging in Handlers

Inject a `LoggerInterface` like any other service:

```php
final class CreateOrderHandler
{
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,
        #[FromService(LoggerInterface::class)] private readonly LoggerInterface $log,
    ) {}

    public function __invoke(ServerRequestInterface $req, #[FromBody] CreateOrderDto $dto): ResponseInterface
    {
        $this->log->info('placing order', ['sku' => $dto->sku, 'qty' => $dto->quantity]);

        try {
            $orderId = $this->orders->ask(
                static fn(ActorRef $r) => new Place($dto, $r),
                Duration::seconds(2),
            );
        } catch (AskTimeoutException $e) {
            $this->log->warning('order placement timed out', ['sku' => $dto->sku]);
            throw $e;
        }

        $this->log->info('order placed', ['id' => $orderId]);

        return JsonResponse::ok(['id' => $orderId])->withStatus(201);
    }
}
```

For the actor-backed PSR-3 logger that runs formatting and I/O off the
request path, see [nexus-logger](../packages/logger.md).

## MDC (Mapped Diagnostic Context)

MDC is the ambient metadata bucket every record automatically picks up —
no need to thread it through every log call. Two tiers:

- **`Mdc::putStatic(key, value)`** — thread-wide. Survives across
  coroutines. Set once at thread boot.
- **`Mdc::put(key, value)`** — coroutine-scoped (Swoole) or
  fiber-scoped (Fiber). Reset between requests.

### Per-Thread Static Context

Set at boot, attached to every record from that thread:

```php
SwooleThreadServer::run($config, function (ActorSystem $system, WorkerNode $node) {
    Mdc::putStatic('host', gethostname() ?: 'unknown');
    Mdc::putStatic('threadId', $node->workerId());
    Mdc::putStatic('service', 'orders-api');

    return WsApplication::create($system)->/* … */;
});
```

Every log line from this thread now carries `host`, `threadId`,
`service` in its `extra` bucket.

### Per-Request Coroutine Context

Set per-request inside a middleware:

```php
final class RequestContextMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
    {
        Mdc::put('requestId', $req->getHeaderLine('X-Request-Id') ?: bin2hex(random_bytes(8)));
        Mdc::put('method',    $req->getMethod());
        Mdc::put('path',      $req->getUri()->getPath());

        if ($principal = $req->getAttribute('principal')) {
            Mdc::put('userId', $principal->id());
        }

        return $next->handle($req);
    }
}
```

Register globally:

```php
$app->middleware(RequestContextMiddleware::class);
```

Now every log call from that point on — whether from middleware, the
handler, or actors invoked by `ask` — picks up the request context
without the developer touching it.

### MDC Output

MDC lands in `Record.extra`, separately from the per-call `context`
argument. With the native `LineFormatter`:

```
[2026-06-14T13:50:01.234Z] orders-api.INFO: placing order {"sku":"ABC","host":"web-3","threadId":2,"requestId":"a1b2c3d4"}
```

The `extra` block (`host`, `threadId`, `requestId`) is MDC; the `sku`
came from the handler's `['sku' => $dto->sku]` call.

## Access Logging

A standard pattern — log every request as a structured line:

```php
final class AccessLogMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $log) {}

    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
    {
        $start = hrtime(true);

        try {
            $response = $next->handle($req);
            $status = $response->getStatusCode();
        } catch (Throwable $e) {
            $status = 500;
            throw $e;
        } finally {
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            $this->log->info('{method} {path} {status} ({ms}ms)', [
                'method' => $req->getMethod(),
                'path'   => $req->getUri()->getPath(),
                'status' => $status,
                'ms'     => round($elapsedMs, 2),
            ]);
        }

        return $response;
    }
}
```

Pair with `RequestContextMiddleware` so the access log line carries the
same `requestId` as every other line from that request — full
correlation, no extra effort.

## Async Logging

Calling `$log->info(...)` blocks until the formatting and write
complete. On a hot request path, this matters. Nexus has two layers of
escape:

### Actor-Backed Logger

The `nexus-actors/logger` package wraps the handlers in a `LogActor`
turn — `$log->info(...)` returns as soon as the record is enqueued, and
formatting + I/O happen on the actor's mailbox.

```php
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\{Level, NexusLogger};

$logger = NexusLogger::create($system, 'app')
    ->minLevel(Level::Info)
    ->handler(new ConsoleHandler(STDOUT, new LineFormatter()))
    ->build();
```

See [nexus-logger#architecture](../packages/logger.md#architecture) for
the full picture.

### `Thread\Queue` Sink (Thread Mode Only)

For multi-thread servers, push formatted lines onto a shared
`Swoole\Thread\Queue` that a dedicated writer thread drains:

```php
use Monadial\Nexus\Logger\Swoole\ThreadQueueHandler;
use Swoole\Thread;
use Swoole\Thread\{Atomic, Queue};

$logQueue = new Queue();
$shutdown = new Atomic(0);

// Spawn the writer thread alongside the server.
$writer = new Thread(__DIR__ . '/logger-writer.php', $logQueue, '/var/log/app.log', $shutdown);

SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)
        ->threads(8)
        ->withLogQueue($logQueue),
    static function (ActorSystem $system, WorkerNode $node) use ($logQueue) {
        $logger = NexusLogger::create($system, "thread-{$node->workerId()}")
            ->handler(new ThreadQueueHandler($logQueue, new LineFormatter()))
            ->build();

        return WsApplication::create($system)
            ->withLogger($logger)
            // …
            ->compile();
    },
);
```

One writer thread, one file descriptor, no locks. The writer-thread
script is at `examples/logger-writer.php`.

This pattern brings logging overhead down to ~3% RPS at sustained load
(vs ~7% with synchronous Monolog handlers in the same configuration).
See [Performance in the logger doc](../packages/logger.md#performance)
for the full benchmark.

## Capturing the Call Site

For debug and error logs you usually want to know **where** the log call
came from. The `CallerInfoProcessor` walks the backtrace at log-time and
stamps `class`, `function`, `file`, `line` into `Record.extra`:

```php
use Monadial\Nexus\Logger\Processor\CallerInfoProcessor;

$logger = NexusLogger::create($system, 'app')
    ->processor(CallerInfoProcessor::onlyFor(Level::Debug, Level::Error, Level::Critical))
    ->handler(new ConsoleHandler(STDOUT, new LineFormatter()))
    ->build();
```

`onlyFor` restricts the backtrace walk to the listed levels. Hot
info-level traffic skips it entirely — `debug_backtrace()` is the most
expensive thing in the pipeline, and almost always unnecessary for
routine info logs.

The captured frame is the actual `$logger->...()` call site, not the
PSR-3 adapter frame — see [the package
docs](../packages/logger.md#callerinfoprocessor) for the resolution
logic.

## Event Dispatch

The router optionally dispatches PSR-14 events for integration with
external observability tools (OpenTelemetry, custom metrics):

| Event class | Fired when |
|---|---|
| `RouteMatched` | After successful match, before middleware runs |
| `ResponseSent` | After the response is written to the socket |
| `WebSocketOpened` | After upgrade succeeds |
| `WebSocketClosed` | After close frame is sent |

Wire a PSR-14 dispatcher via the `HttpApp::create` constructor (see
[nexus-http](../packages/http.md)) and add your subscribers there.

## Composition

```
HTTP request
    │
    ├─→ RequestContextMiddleware (Mdc::put requestId, method, path)
    │
    ├─→ AccessLogMiddleware (stamps start time, logs on exit)
    │
    ├─→ Handler::__invoke()
    │     │
    │     ├─→ $log->info(...) — picks up MDC, runs processors, tells LogActor
    │     │
    │     └─→ $actor->ask(...) — log lines inside the actor still see MDC
    │                            (via coroutine context propagation)
    │
    ▼
LogActor turn (off the request path)
    │
    ├─→ Handler::handle($record) — formats and writes
    │
    └─→ ThreadQueueHandler → Swoole\Thread\Queue → writer thread → file
```

Next: [Production](./production.md) for caching, OPcache, and
deployment-time concerns.
