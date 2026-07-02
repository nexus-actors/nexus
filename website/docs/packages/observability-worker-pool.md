---
title: nexus-observability-worker-pool
related:
  - observability/tracing
  - observability/metrics
  - packages/observability
  - packages/worker-pool
---

# nexus-observability-worker-pool

Worker-pool observability — a tracing decorator for `WorkerTransport` that propagates trace context across worker-thread boundaries and records transport metrics. The receiving actor can open a Consumer span from the injected metadata, completing the distributed trace.

## Install

```bash title="terminal"
composer require nexus-actors/observability-worker-pool
```

## What's in this package

| Class | Purpose |
|---|---|
| `TracingWorkerTransport` | Decorator for `WorkerTransport`: opens a Producer span on `send()`, injects the span context into the envelope metadata, and records `nexus.worker_pool.messages.sent` and `nexus.worker_pool.send.duration` |

## Quick example

```php title="src/WorkerPool/Bootstrap.php" verify:lint-only
use Monadial\Nexus\Observability\WorkerPool\TracingWorkerTransport;

// $innerTransport is a WorkerTransport (e.g. ThreadQueueTransport)
$transport = new TracingWorkerTransport($innerTransport, $obs);
```

The decorator calls the inner transport directly when `$obs->isEnabled()` returns `false`. Transport errors always propagate; telemetry errors are silently ignored.

## Emitted spans and metrics

| Name | Kind | Dimensions |
|---|---|---|
| `worker.send` | Producer span | `messaging.operation`, `messaging.system`, `nexus.actor.path`, `nexus.worker.target` |
| `nexus.worker_pool.messages.sent` | Counter | `nexus.worker.target` |
| `nexus.worker_pool.send.duration` | Histogram (s) | `nexus.worker.target` |

Trace context is injected into `Envelope::$metadata` as W3C `traceparent`/`tracestate` headers so the receiving worker can extract the parent span and open a Consumer span, linking the cross-thread hops in a single trace.

## See also

- [Observability tracing guide](../observability/tracing.md) — context propagation across boundaries
- [nexus-observability](./observability.md) — vendor-neutral contracts
- [nexus-worker-pool package](./worker-pool.md) — multi-worker actor pools
