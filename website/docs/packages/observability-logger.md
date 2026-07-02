---
title: nexus-observability-logger
related:
  - observability/logs
  - packages/observability
  - packages/logger
---

# nexus-observability-logger

Logs-traces correlation — a `RecordProcessor` that stamps the active span's `trace_id`, `span_id`, and `trace_flags` onto each log record's `extra` map. Every log line emitted inside an active span automatically carries the trace identifiers, so log search and trace viewer can cross-link.

## Install

```bash title="terminal"
composer require nexus-actors/observability-logger
```

## What's in this package

| Class | Purpose |
|---|---|
| `TraceCorrelationProcessor` | `RecordProcessor` implementation: reads `Observability::currentContext()`, adds `trace_id`, `span_id`, `trace_flags` to `Record::$extra`; no-op when disabled or no span is active |

## Quick example

```php title="src/Bootstrap/LoggerSetup.php" verify:lint-only
use Monadial\Nexus\Logger\Formatter\JsonFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\NexusLogger;
use Monadial\Nexus\Observability\Logger\TraceCorrelationProcessor;

$logger = NexusLogger::create($system, 'app')
    ->processor(new TraceCorrelationProcessor($obs))
    ->handler(new ConsoleHandler(STDOUT, new JsonFormatter()))
    ->build();
```

`TraceCorrelationProcessor` runs synchronously on the caller's thread (the actor's handler turn, or the HTTP request coroutine), so the ambient span is current. Any telemetry error is silently swallowed — it never breaks logging.

## Output shape

When a span is active, each JSON log line gains an `extra` object:

```json title="log line example"
{
  "level": "INFO",
  "message": "order created",
  "context": { "orderId": "ord-42" },
  "extra": {
    "trace_id": "4bf92f3577b34da6a3ce929d0e0e4736",
    "span_id":  "00f067aa0ba902b7",
    "trace_flags": 1
  }
}
```

## See also

- [Observability logs guide](../observability/logs.md) — log-trace correlation in depth
- [nexus-observability](./observability.md) — vendor-neutral contracts
- [nexus-logger package](./logger.md) — async actor-backed PSR-3 logger
