---
sidebar_position: 3
title: Observability
related:
  - operations/deployment
  - operations/performance-tuning
  - packages/logger
  - http/overview
---

# Observability

:::info OpenTelemetry traces, metrics, and logs
Looking for distributed tracing, OTel metrics export, or trace/log correlation? The [Observability guide](/docs/observability/overview) covers the full OTEL integration — spans, metrics catalog, custom instrumentation, and production wiring.
:::

The HTTP layer uses PSR-3 logging, PSR-14 event dispatch, and PSR-20 clock throughout. This page covers what the framework logs, how to add per-request context via MDC, how to keep logging off the hot path, and how to wire PSR-14 events for external observability tools.

## What the framework logs

When you configure a `LoggerInterface` via `$app->withLogger($logger)`, the framework emits events at well-defined points:

| Event | Level | Source |
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

Most events are `debug`-level — silent in production unless you set `minLevel(Level::Debug)`.

## Logging in handlers

Inject a `LoggerInterface` like any other service:

```php title="src/Http/Handlers/CreateOrderHandler.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Handler\Attribute\FromBody;
use Monadial\Nexus\Http\Handler\Attribute\FromService;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Runtime\Duration;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Log\LoggerInterface;

final class CreateOrderHandler
{
    /** @param ActorRef<Place> $orders */
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,
        #[FromService(LoggerInterface::class)] private readonly LoggerInterface $log,
    ) {}

    public function __invoke(
        ServerRequestInterface $req,
        #[FromBody] CreateOrderDto $dto,
    ): ResponseInterface {
        $this->log->info('placing order', ['sku' => $dto->sku]);
        $orderId = $this->orders->ask(new Place($dto), Duration::seconds(2))->await();
        $this->log->info('order placed', ['id' => $orderId]);

        return JsonResponse::ok(['id' => $orderId])->withStatus(201);
    }
}
```

## MDC (mapped diagnostic context)

MDC is the ambient metadata bucket that every log record picks up automatically — no need to thread it through every log call. Two tiers:

- **`Mdc::putStatic(key, value)`** — Thread-wide. Survives across coroutines. Set once at thread boot.
- **`Mdc::put(key, value)`** — Coroutine-scoped (Swoole) or fiber-scoped (Fiber). Resets between requests automatically.

### Per-thread static context

Set at boot; attached to every record from that thread:

```php title="src/bootstrap.php"
SwooleThreadServer::run($config, function (ActorSystem $system, WorkerNode $node) {
    Mdc::putStatic('host', gethostname() ?: 'unknown');
    Mdc::putStatic('threadId', $node->workerId());
    Mdc::putStatic('service', 'orders-api');

    return WsApplication::create($system)->/* … */;
});
```

### Per-request coroutine context

Set per request inside a middleware:

```php title="src/Http/Middleware/RequestContextMiddleware.php"
<?php

declare(strict_types=1);

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

final class RequestContextMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $req,
        RequestHandlerInterface $next,
    ): ResponseInterface {
        Mdc::put('requestId', $req->getHeaderLine('X-Request-Id') ?: bin2hex(random_bytes(8)));
        Mdc::put('method', $req->getMethod());
        Mdc::put('path', $req->getUri()->getPath());

        return $next->handle($req);
    }
}
```

Register globally:

```php title="src/bootstrap.php"
$app->middleware(RequestContextMiddleware::class);
```

Every log call from that point — whether from middleware, the handler, or actors invoked via `ask()` — picks up the request context without the developer touching it.

## Access logging

A standard pattern — log every request as a structured line:

```php title="src/Http/Middleware/AccessLogMiddleware.php"
<?php

declare(strict_types=1);

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Psr\Log\LoggerInterface;

final class AccessLogMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $log) {}

    public function process(
        ServerRequestInterface $req,
        RequestHandlerInterface $next,
    ): ResponseInterface {
        $start = hrtime(true);

        try {
            $response = $next->handle($req);
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            $status = 500;
            throw $e;
        } finally {
            $ms = (hrtime(true) - $start) / 1_000_000;
            $this->log->info('{method} {path} {status} ({ms}ms)', [
                'method' => $req->getMethod(),
                'path'   => $req->getUri()->getPath(),
                'status' => $status,
                'ms'     => round($ms, 2),
            ]);
        }

        return $response;
    }
}
```

Pair with `RequestContextMiddleware` so the access log line carries the same `requestId` as every other line from that request.

## Async logging

Calling `$log->info(...)` blocks until the formatting and write complete. On a hot request path, this matters. Two escape paths:

### Actor-backed logger

The `nexus-logger` package wraps handlers in a `LogActor` turn — `$log->info(...)` returns as soon as the record is enqueued; formatting and I/O happen on the actor's mailbox:

```php title="src/bootstrap.php"
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\{Level, NexusLogger};

$logger = NexusLogger::create($system, 'app')
    ->minLevel(Level::Info)
    ->handler(new ConsoleHandler(STDOUT, new LineFormatter()))
    ->build();
```

See [nexus-logger architecture](../packages/logger.md#architecture) for the full picture.

### Thread\Queue sink (thread mode only)

For multi-thread servers, push formatted lines onto a shared `Swoole\Thread\Queue` that a dedicated writer thread drains:

```php title="src/bootstrap.php"
use Monadial\Nexus\Logger\Swoole\ThreadQueueHandler;
use Swoole\Thread\Queue;

$logQueue = new Queue();

SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)
        ->threads(8)
        ->withLogQueue($logQueue),
    static function (ActorSystem $system, WorkerNode $node) use ($logQueue) {
        $logger = NexusLogger::create($system, "thread-{$node->workerId()}")
            ->handler(new ThreadQueueHandler($logQueue, new LineFormatter()))
            ->build();

        return WsApplication::create($system)->withLogger($logger)->compile();
    },
);
```

One writer thread, one file descriptor, no locks. This pattern brings logging overhead to approximately 3% RPS at sustained load (vs approximately 7% with synchronous handlers in the same configuration).

## Capturing the call site

For debug and error logs, `CallerInfoProcessor` walks the backtrace at log time and stamps `class`, `function`, `file`, and `line` into `Record.extra`:

```php title="src/bootstrap.php"
use Monadial\Nexus\Logger\Processor\CallerInfoProcessor;

$logger = NexusLogger::create($system, 'app')
    ->processor(CallerInfoProcessor::onlyFor(Level::Debug, Level::Error, Level::Critical))
    ->handler(new ConsoleHandler(STDOUT, new LineFormatter()))
    ->build();
```

`onlyFor()` restricts the backtrace walk to the listed levels. Info-level traffic skips it — `debug_backtrace()` is the most expensive step in the pipeline and is unnecessary for routine info logs.

## PSR-14 event dispatch

The router dispatches PSR-14 events for integration with OpenTelemetry, custom metrics, or external observability tools:

| Event class | Fired when |
|---|---|
| `RouteMatched` | After successful match, before middleware runs |
| `ResponseSent` | After the response is written to the socket |
| `WebSocketOpened` | After upgrade succeeds |
| `WebSocketClosed` | After close frame is sent |

Wire a PSR-14 dispatcher via the `HttpApp::create` constructor (see [nexus-http](../packages/http.md)) and add your listeners there.

## See also

- [Deployment](./deployment.md) — production configuration and pre-flight checklist
- [Performance tuning](./performance-tuning.md) — measuring and reducing per-request overhead
- [nexus-logger package](../packages/logger.md) — `NexusLogger`, handlers, formatters, and processors
