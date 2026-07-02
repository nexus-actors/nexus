---
title: nexus-observability
related:
  - observability/overview
  - packages/observability-otel
  - packages/core
---

# nexus-observability

Vendor-neutral telemetry contracts — tracing, metrics, and context-propagation interfaces with zero-cost no-op defaults. Every satellite package depends on this foundation; application code can reference it without committing to an SDK.

## Install

```bash title="terminal"
composer require nexus-actors/observability
```

## What's in this package

| Class / Interface | Purpose |
|---|---|
| `Observability` | Entry-point interface: `tracer()`, `meter()`, `propagator()`, `currentContext()`, `shutdown()` |
| `NoopObservability` | Zero-overhead default; `isEnabled()` returns `false` so call sites skip work |
| `ObservabilityConfig` | Immutable config value object; `fromEnv()` reads standard OTEL env vars |
| `Tracer` | Interface: `startSpan(name, kind, attributes, parent): Span` |
| `Span` | Interface: `setAttribute()`, `recordException()`, `setStatus()`, `end()` |
| `SpanKind` | Enum: `Server`, `Client`, `Producer`, `Consumer`, `Internal` |
| `StatusCode` | Enum: `Unset`, `Ok`, `Error` |
| `Meter` | Interface: `counter()`, `histogram()`, `upDownCounter()`, `observableGauge()` |
| `ContextPropagator` | Interface: `inject(context, carrier)` / `extract(carrier): Context` |
| `Context` | Immutable ambient span context passed to `startSpan` as `$parent` |

## Quick example

```php title="src/Bootstrap/ObservabilitySetup.php" verify:lint-only
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;

// Default: zero-overhead no-op — safe to wire anywhere without an SDK
$obs = new NoopObservability();

// Type-hint the interface so callers are SDK-agnostic
function instrumentedWork(Observability $obs): void
{
    if (!$obs->isEnabled()) {
        return;
    }

    $span = $obs->tracer()->startSpan('my.operation');
    $span->end();
}
```

Use `ObservabilityConfig::fromEnv($_SERVER)` to read `OTEL_SDK_DISABLED`, `OTEL_SERVICE_NAME`, and related env vars, then pass the config to `ObservabilityFactory::fromConfig()` from `nexus-actors/observability-otel`.

## See also

- [Observability overview](../observability/overview.md) — end-to-end wiring guide
- [nexus-observability-otel](./observability-otel.md) — SDK-backed OTEL provider
