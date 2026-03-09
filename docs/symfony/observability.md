# Observability

## Request ID propagation

`NexusBundle` registers two event listeners that handle request identification:

### RequestIdListener

Fires on `KernelEvents::REQUEST` at priority 900 (after `CoroutineScopeListener` at 1000, so the coroutine context is already initialized).

Logic:
- If the incoming request carries an `X-Request-Id` header, that value is used as the request ID.
- If the incoming request carries an `X-Correlation-Id` header, that value is used as the correlation ID.
- If `X-Request-Id` is absent, a fresh ULID is generated.
- If `X-Correlation-Id` is absent, the correlation ID defaults to the request ID.

Both values are stored in the current coroutine context:

```
$ctx['nexus.request_id']     = '01HWXXXXXXXXXXXXXXXXXXXXXX'
$ctx['nexus.correlation_id'] = '01HWXXXXXXXXXXXXXXXXXXXXXX'
```

This allows distributed tracing: a gateway or upstream service sets `X-Request-Id` and `X-Correlation-Id`; all log records within the request carry the same values.

### ResponseIdListener

Fires on `KernelEvents::RESPONSE`. If `nexus.request_id` is set in the coroutine context, it is written to the `X-Request-Id` response header. This lets clients correlate their request with server-side log records.

## NexusMonologProcessor

`NexusMonologProcessor` is a Monolog processor (tagged `monolog.processor`) that enriches every log record with tracing fields. It reads from two sources:

### Source 1: HTTP request context

When `nexus.request_id` is present in the coroutine context (i.e., during HTTP request handling), the processor adds:

```json
{
  "extra": {
    "request_id": "01HWXXXXXXXXXXXXXXXXXXXXXXXXXX",
    "correlation_id": "01HWXXXXXXXXXXXXXXXXXXXXXXXXXX"
  }
}
```

### Source 2: Actor envelope context

When code runs inside an actor handler (outside of an HTTP coroutine), the processor reads the current `Envelope` from `EnvelopeContext` and adds:

```json
{
  "extra": {
    "request_id": "01HWXXXXXXXXXXXXXXXXXXXXXXXXXX",
    "correlation_id": "01HWXXXXXXXXXXXXXXXXXXXXXXXXXX",
    "causation_id": "01HWXXXXXXXXXXXXXXXXXXXXXXXXXX"
  }
}
```

The three IDs follow the Nexus envelope tracing convention:
- `request_id` — identifies this specific message dispatch.
- `correlation_id` — shared by all messages in the same logical request chain.
- `causation_id` — the `request_id` of the message that caused the current message.

If neither context is active (e.g., in a CLI command run outside Swoole), the processor returns the log record unchanged.

## Monolog configuration

The processor is registered automatically. No explicit Monolog configuration is required. If a specific channel needs the processor, wire it in `config/packages/monolog.yaml`:

```yaml
monolog:
    handlers:
        main:
            type: stream
            path: "%kernel.logs_dir%/%kernel.environment%.log"
            level: debug
            processors:
                - nexus.monolog_processor
```

## Connecting to external tracing systems

### OpenTelemetry

Pass the incoming `traceparent` or `X-B3-TraceId` header as `X-Correlation-Id`. `RequestIdListener` stores it in the coroutine context. The Monolog processor then propagates it to every log record. For full span-level tracing, add an OpenTelemetry SDK layer that reads `nexus.request_id` from the coroutine context:

```php
use Monadial\Nexus\Symfony\Coroutine\CoroutineContext;
use OpenTelemetry\API\Trace\TracerInterface;

final class NexusOtelMiddleware
{
    public function __construct(
        private readonly CoroutineContext $ctx,
        private readonly TracerInterface $tracer,
    ) {}

    public function startSpan(string $name): void
    {
        $span = $this->tracer->spanBuilder($name)->startSpan();
        $ctx  = $this->ctx->current();
        $span->setAttribute('nexus.request_id', (string) ($ctx['nexus.request_id'] ?? ''));
    }
}
```

### Structured log aggregation (e.g., Loki, Elasticsearch)

Configure Monolog with a JSON formatter. The `extra` fields written by `NexusMonologProcessor` appear as top-level JSON keys in the formatted record, making them directly queryable:

```yaml
monolog:
    handlers:
        main:
            type: stream
            path: php://stdout
            formatter: monolog.formatter.json
```

Query example in Loki:

```
{app="my-app"} | json | request_id="01HWXXXXXXXXXXXXXXXXXXXXXXXXXX"
```

## Actor path in logs

When logging inside an actor handler via `$ctx->log()`, the PSR-3 logger provided by the actor system automatically includes the actor path in the log context. This is separate from `NexusMonologProcessor` and requires no additional configuration.
