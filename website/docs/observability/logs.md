---
sidebar_position: 7
title: Logs & Trace Correlation
---

# Logs & Trace Correlation

`TraceCorrelationProcessor` stamps the current OTel span's `trace_id` and `span_id` onto every Nexus log record's `extra` field. Log lines emitted from inside an actor handler carry the same trace ID as the OTel trace, enabling log-trace correlation in tools like Grafana Loki + Tempo.

## How to register

Add `TraceCorrelationProcessor` when building your logger:

```php title="logger-setup.php" verify:lint-only
use Monadial\Nexus\Logger\Formatter\JsonFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\NexusLogger;
use Monadial\Nexus\Observability\Logger\TraceCorrelationProcessor;

$logger = NexusLogger::create($system, 'app')
    ->minLevel(Level::Info)
    ->processor(new TraceCorrelationProcessor($observability))
    ->handler(new ConsoleHandler(STDOUT, new JsonFormatter()))
    ->build();
```

The processor must be registered before any handlers so the `extra` fields are present when the handler formats the record.

## What it adds

Each log record produced inside a traced actor handler receives three extra fields:

| Field | Type | Example | Description |
|---|---|---|---|
| `extra.trace_id` | string (hex) | `4bf92f3577b34da6a3ce929d0e0e4736` | OTel trace ID |
| `extra.span_id` | string (hex) | `00f067aa0ba902b7` | OTel span ID for the current actor message span |
| `extra.trace_flags` | int | `1` | OTel trace flags (1 = sampled) |

## Behaviour when observability is disabled

When `withObservability()` has not been called, `TraceCorrelationProcessor` checks `Observability::isEnabled()` and whether the current span context is valid. With observability disabled, `isEnabled()` returns `false`, so the processor skips stamping and the log record is emitted without trace fields. There is no error.

## Relationship to `ServerSpanMiddleware`

In the HTTP layer, `ServerSpanMiddleware` (from `nexus-observability-http`) extracts W3C Trace Context headers from the incoming request and starts a server span as the active context before passing control to the route handler. `TraceCorrelationProcessor` reads this active span when log records are created.

Result: every log line emitted anywhere in the HTTP request lifecycle — inside the route handler, inside actor handlers, inside repositories — carries the HTTP server span's `trace_id`. A single trace ID links all related log lines and all OTel spans.

## Grafana integration

To make `trace_id` clickable in Grafana Loki:

1. Configure your Monolog JSON formatter to include the `extra` map in its output.
2. In Grafana, open the Loki datasource settings and add a **Derived field**:
   - **Name**: `TraceID`
   - **Regex**: `"trace_id":"([a-f0-9]{32})"`
   - **URL**: `http://tempo:3200/trace/$${__value.raw}` (adjust for your Tempo URL)
3. Grafana will render a **Tempo** link next to each log line that contains a `trace_id`.

:::tip
If you use structured JSON logging, output `trace_id` as a top-level field rather than inside `extra` to simplify the Grafana regex and make the field available for Loki label extraction.
:::

## Combining with PSR-14 log events

If your application dispatches PSR-14 events that result in log output (e.g., domain events emitted via `EventDispatcherInterface`), the processor still works correctly provided those events are dispatched from within an active span — that is, from inside an actor handler or from within the HTTP request lifecycle under `ServerSpanMiddleware`.
