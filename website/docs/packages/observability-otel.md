---
title: nexus-observability-otel
related:
  - observability/overview
  - packages/observability
  - observability/configuration
---

# nexus-observability-otel

OpenTelemetry SDK bridge — turns an `ObservabilityConfig` into a real `Observability` provider backed by OTLP exporters. Returns `NoopObservability` when disabled, so the same wiring works in every environment.

## Install

```bash title="terminal"
composer require nexus-actors/observability-otel
```

## What's in this package

| Class | Purpose |
|---|---|
| `ObservabilityFactory` | Static factory: `fromConfig(ObservabilityConfig): Observability` — the primary entry point |
| `OtelObservability` | SDK-backed `Observability` implementation; owns `TracerProvider` + `MeterProvider` |
| `OtelTracer` | Bridges Nexus `Tracer` to OTEL `TracerProviderInterface` |
| `OtelMeter` | Bridges Nexus `Meter` to OTEL `MeterProviderInterface` |
| `OtelSpan` | Bridges Nexus `Span` to OTEL `SpanInterface` |

## Quick example

```php title="src/Bootstrap/ObservabilitySetup.php" verify:lint-only
use Monadial\Nexus\Observability\Config\ObservabilityConfig;
use Monadial\Nexus\Observability\Otel\ObservabilityFactory;

$config = ObservabilityConfig::fromEnv($_SERVER);
// or: ObservabilityConfig::enabled('my-service')->withExporterEndpoint('http://otel-collector:4318')

$obs = ObservabilityFactory::fromConfig($config);
// $obs is OtelObservability when enabled, NoopObservability when OTEL_SDK_DISABLED=true

// Call shutdown() once during graceful shutdown to flush pending spans/metrics
$obs->shutdown();
```

`ObservabilityFactory::fromConfig()` reads `ObservabilityConfig::$sampler` to wire the OTEL sampler (`always_on`, `always_off`, `parentbased_always_on`, `traceidratio`, etc.) and `$exporterEndpoint` to set the OTLP HTTP endpoint.

## Sampler mapping

| Config value | OTEL sampler |
|---|---|
| `always_on` | `AlwaysOnSampler` |
| `always_off` | `AlwaysOffSampler` |
| `traceidratio` | `TraceIdRatioBasedSampler($samplerArg)` |
| `parentbased_traceidratio` | `ParentBased(TraceIdRatioBasedSampler($samplerArg))` |
| anything else (default) | `ParentBased(AlwaysOnSampler)` |

## Swoole coroutine hooks and OTLP export

The OTel PHP SDK's HTTP (OTLP) exporter uses ext-curl with `CURLOPT_SHARE`. Under
`SWOOLE_HOOK_ALL`, Swoole's curl hook does **not** support `CURLOPT_SHARE`, which breaks
OTLP export entirely (spans/metrics silently fail to leave the process) unless Swoole was
compiled with native swoole-curl support. When running observability inside a Swoole
runtime, either:

- exclude the curl hooks from coroutine hooking
  (`SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_NATIVE_CURL & ~SWOOLE_HOOK_CURL`), or
- build Swoole with native curl support (`--enable-swoole-curl`).

Also set a bounded exporter timeout (e.g. `OTEL_EXPORTER_OTLP_TIMEOUT`): a stalled OTLP
collector can otherwise block the exporting call on a single-reactor process and stall the
event loop at flush time.

## See also

- [Observability overview](../observability/overview.md) — end-to-end wiring guide
- [Observability configuration](../observability/configuration.md) — env vars reference
- [nexus-observability](./observability.md) — vendor-neutral contracts
