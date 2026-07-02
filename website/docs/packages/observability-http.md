---
title: nexus-observability-http
related:
  - observability/tracing
  - observability/metrics
  - packages/observability
  - packages/http
---

# nexus-observability-http

HTTP observability — a PSR-15 middleware that opens a server span per request and a PSR-14 listener that records RED metrics from the HTTP request lifecycle.

## Install

```bash title="terminal"
composer require nexus-actors/observability-http
```

## What's in this package

| Class | Purpose |
|---|---|
| `ServerSpanMiddleware` | PSR-15 middleware: extracts inbound trace context, opens an OTEL Server span, records `http.*` attributes + status, and records handler exceptions |
| `HttpMetricsListener` | PSR-14 listener: records `http.server.active_requests` (UpDownCounter) and `http.server.request.duration` (Histogram) from `RequestStarted`/`RequestCompleted` events |

## Quick example

```php title="src/Http/Bootstrap.php" verify:lint-only
use Monadial\Nexus\Observability\Http\HttpMetricsListener;
use Monadial\Nexus\Observability\Http\ServerSpanMiddleware;

// $obs is an Observability instance (e.g. from ObservabilityFactory::fromConfig())
// $app is your HttpApplication

$app->middleware(new ServerSpanMiddleware($obs));

// Register the listener with the PSR-14 event dispatcher
$dispatcher->addListener(
    \Monadial\Nexus\Http\Event\RequestStarted::class,
    [new HttpMetricsListener($obs), 'onRequestStarted'],
);
$dispatcher->addListener(
    \Monadial\Nexus\Http\Event\RequestCompleted::class,
    [new HttpMetricsListener($obs), 'onRequestCompleted'],
);
```

`ServerSpanMiddleware` is fail-isolated: telemetry errors never break the request. When `$obs->isEnabled()` returns `false`, the middleware is a transparent pass-through. Register it as the outermost layer so the span covers the entire request including other middleware.

## Emitted metrics

| Metric | Type | Description |
|---|---|---|
| `http.server.active_requests` | UpDownCounter | In-flight requests; dimension: `http.request.method` |
| `http.server.request.duration` | Histogram (seconds) | Request duration; dimensions: `http.request.method`, `http.response.status_code` |

## See also

- [Observability tracing guide](../observability/tracing.md) — span lifecycle and context propagation
- [Observability metrics guide](../observability/metrics.md) — metric names and dimensions
- [nexus-observability](./observability.md) — vendor-neutral contracts
- [nexus-http package](./http.md) — HTTP application and routing
