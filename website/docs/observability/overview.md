---
sidebar_position: 1
title: Overview
---

# Overview

Nexus observability is built on the [OpenTelemetry SDK for PHP](https://opentelemetry.io/docs/languages/php/), providing traces, metrics, and logs in a unified signal. Observability is **disabled by default** — if you do not call `withObservability()`, no tracer or meter is created and there is zero overhead.

## Package model

Nexus observability ships as a set of focused packages:

| Package | Purpose |
|---|---|
| `nexus-observability` | Core contracts: `Observability` interface, no-op implementations, config value object |
| `nexus-observability-otel` | OTel SDK wiring: `OtelObservability`, `ObservabilityFactory`, OTLP exporter, samplers, propagators |
| `nexus-observability-actor` | `ActorSystemMetrics` — live-actor, dead-letter, and running-state gauges |
| `nexus-observability-http` | `ServerSpanMiddleware` (server spans) and `HttpMetricsListener` (request duration and active count) |
| `nexus-observability-persistence` | Tracing decorators for event stores, snapshot stores, and durable-state stores |
| `nexus-observability-serialization` | `TracingMessageSerializer` — spans and `nexus.serialization.*` metrics for any `MessageSerializer` |
| `nexus-observability-worker-pool` | `TracingWorkerTransport` — context propagation and metrics across worker threads |
| `nexus-observability-logger` | `TraceCorrelationProcessor` — stamps trace/span IDs onto log records |
| `nexus-observability-doctrine` | `DbalPoolMetricsListener`, `OrmPoolMetricsListener`, and `Sql\TracingDriverMiddleware` |
| `nexus-observability-swoole` | `SwooleContextRegistrar` and `SwooleAdminMetrics` — Swoole coroutine context and server gauges |

Start with `nexus-observability-otel` and add satellites as your application grows.

## Three guarantees

### 1. Disabled by default

If you do not call `withObservability()` on `NexusApp`, no tracer or meter is created. The actor runtime does not reference any OTel SDK classes. There is no overhead.

### 2. Zero actor-code change

Inside actor handlers, `$ctx->tracer()` and `$ctx->meter()` always return valid objects. When observability is disabled they return the built-in no-op implementations from `nexus-observability` (`NoopTracer`, `NoopMeter`) — no OTel SDK classes are involved. Your actor code does not need `if ($observabilityEnabled)` guards — the same handler works with or without observability wired in.

### 3. Fail isolation

OTel export errors — network timeouts, collector unavailability, serialization failures — are swallowed by the OTel SDK's `RetryingExporter` and never surface as actor failures. An actor that cannot export a span continues processing normally.

## End-to-end trace picture

When all satellites are wired, a single HTTP request produces a trace that spans the entire call chain:

```text
HTTP POST /orders  [http.server]
└── orders actor: Handle(PlaceOrder)  [nexus.actor.message]
    ├── persistence: persist event  [nexus.persistence.event]
    │   └── SQL INSERT  [db.query]
    └── payments actor: tell(ChargeCard)  [nexus.actor.message]
```

Trace context is propagated via `Envelope::metadata` using [W3C Trace Context](https://www.w3.org/TR/trace-context/) (`traceparent` and `tracestate` headers). The receiving actor extracts the context and creates a child span automatically. This works transparently across:

- **Local actors** — same process, same thread
- **Worker-pool actors** — cross-thread via Swoole threads, using `TracingWorkerTransport`
- **Future cluster actors** — remote nodes, using the cluster transport layer

The entire call chain appears as one trace in Jaeger, Grafana Tempo, or any OTel-compatible backend.

## Next steps

- [Quick Start](./quick-start.md) — add observability in five minutes
- [Configuration](./configuration.md) — `OTEL_*` environment variables and programmatic builder
- [Tracing](./tracing.md) — how actor spans work and context propagation
- [Metrics](./metrics.md) — full catalog of built-in metrics
- [Custom Instrumentation](./custom-instrumentation.md) — `$ctx->tracer()`, `$ctx->meter()`, `$ctx->currentSpan()`
- [Logs & Trace Correlation](./logs.md) — stamping trace IDs onto log records
- [Full Wiring Guide](./wiring.md) — production wiring for all satellites
