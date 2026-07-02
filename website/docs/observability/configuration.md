---
sidebar_position: 3
title: Configuration
---

# Configuration

Nexus observability is configured via `ObservabilityConfig`, which reads `OTEL_*` environment variables by convention or accepts programmatic overrides.

## Environment variables

`ObservabilityConfig::fromEnv($_SERVER)` reads the following variables:

| Variable | Description | Default |
|---|---|---|
| `OTEL_SERVICE_NAME` | Service name reported to the backend | NexusApp name |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | OTLP endpoint base URL | `http://localhost:4318` |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | Transport protocol | `http/protobuf` |
| `OTEL_EXPORTER_OTLP_HEADERS` | Comma-separated `key=value` auth headers | _(none)_ |
| `OTEL_TRACES_SAMPLER` | Sampler algorithm (see table below) | `parentbased_always_on` |
| `OTEL_TRACES_SAMPLER_ARG` | Argument for ratio-based samplers, e.g. `0.1` | _(none)_ |
| `OTEL_RESOURCE_ATTRIBUTES` | Comma-separated `key=value` resource tags | _(none)_ |
| `OTEL_SDK_DISABLED` | Set to `true` to disable the SDK entirely | `false` |

### Sampler options

| Value | Description |
|---|---|
| `always_on` | Sample every trace (high volume — development only) |
| `always_off` | Drop every trace |
| `traceidratio` | Sample a fixed ratio of traces; set ratio in `OTEL_TRACES_SAMPLER_ARG` (e.g. `0.1` = 10%) |
| `parentbased_always_on` | Follow parent sampling decision; sample root spans (default) |
| `parentbased_always_off` | Follow parent sampling decision; drop root spans |
| `parentbased_traceidratio` | Follow parent sampling decision; apply ratio to root spans |

:::tip
In production, `parentbased_traceidratio` with `OTEL_TRACES_SAMPLER_ARG=0.05` (5%) is a good starting point. The `parentbased_*` samplers ensure that once a trace is sampled at the HTTP ingress, all downstream actor spans in the same trace are also sampled.
:::

## Programmatic configuration

When environment variables are not convenient — for example, in tests or when your configuration comes from a secret manager — use the fluent factory methods directly:

```php title="observability-config.php" verify:lint-only
use Monadial\Nexus\App\NexusApp;
use Monadial\Nexus\Observability\Config\ObservabilityConfig;
use Monadial\Nexus\Observability\Otel\ObservabilityFactory;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

$config = ObservabilityConfig::enabled('my-app')
    ->withExporterEndpoint('https://otel.example.com:4318')
    ->withSampler('parentbased_traceidratio', 0.1);

NexusApp::create('my-app')
    ->withObservability(ObservabilityFactory::fromConfig($config))
    ->run(new FiberRuntime());
```

## Disabled by default

Without a `withObservability()` call, Nexus does not load the OTel SDK at all. All calls to `$ctx->tracer()` and `$ctx->meter()` inside actor handlers return the OTel SDK's built-in no-op objects — they accept calls and discard data with no memory allocations.

This means you can write instrumented actor code (calls to `$ctx->tracer()`, `$ctx->currentSpan()`, etc.) before observability is wired up, and those calls cost nothing until the provider is installed.

:::info
Setting `OTEL_SDK_DISABLED=true` is different from omitting `withObservability()`. When `fromEnv()` is called with `OTEL_SDK_DISABLED=true`, a disabled-SDK provider is installed — the OTel SDK is loaded but all operations are no-ops. This is useful when you want the code path to be exercised (e.g., in integration tests) without exporting data.
:::
