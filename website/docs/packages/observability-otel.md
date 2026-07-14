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
| `parentbased_always_off` | `ParentBased(AlwaysOffSampler)` |
| `traceidratio` | `TraceIdRatioBasedSampler($samplerArg)` |
| `parentbased_traceidratio` | `ParentBased(TraceIdRatioBasedSampler($samplerArg))` |
| anything else (default) | `ParentBased(AlwaysOnSampler)` |

## Swoole coroutine hooks and OTLP export

Under `SWOOLE_HOOK_ALL`, **both** generic PHP HTTP client paths are broken inside
coroutines (verified on Swoole 6.2): the userland curl hook rejects `CURLOPT_SHARE`
(which symfony's curl client always sets), and the hooked `http://` stream wrapper fails
outright with `Failed to parse address` — every in-coroutine export then dies after the
retry limit, *silently* if SDK error logging is muted (`OTEL_PHP_LOG_DESTINATION=none`).

This package handles it automatically: when ext-swoole is loaded, `ObservabilityFactory`
wires the OTLP transports through a coroutine-native PSR-18 client
(`SwooleCoroutinePsr18Client`, backed by `Swoole\Coroutine\Http\Client` — no hooks
involved, yields to the scheduler during I/O). Outside a coroutine (boot, post-reactor
shutdown flush) it delegates to the plain stream client, which works unhooked. No hook
configuration is required.

Still set a bounded exporter timeout (`OTEL_EXPORTER_OTLP_TIMEOUT`): it caps how long any
single flush can hold its coroutine when the collector stalls. If you suspect telemetry
loss, do NOT run with `OTEL_PHP_LOG_DESTINATION=none` while debugging — it hides export
failures completely.

## Async export (actor)

Opt-in: route all OTLP flush I/O (spans, metrics, logs) through a dedicated
`OtlpExportActor` with a bounded mailbox, so a slow or stalled collector can never block
application coroutines. The SDK's batching (`BatchSpanProcessor` / `ExportingReader` /
`BatchLogRecordProcessor`) is unchanged — only the terminal exporters are swapped for
forwarding twins whose `export()` is a mailbox enqueue.

Enable with `OTEL_NEXUS_ASYNC_EXPORT=1` (or `ObservabilityConfig::withAsyncExport(true)`;
default **off** — the sync bounded-timeout path is unchanged), then attach the actor once
after the system exists:

```php
$config = ObservabilityConfig::fromEnv($_ENV);           // OTEL_NEXUS_ASYNC_EXPORT=1
$observability = ObservabilityFactory::fromConfig($config);
$system = ActorSystem::create('app', $runtime, observability: $observability);

$observability->attachExportActor($system);              // spawns 'otlp-export'
$system->run();
```

Telemetry created **before** `attachExportActor()` buffers locally (up to 64 batches per
signal, oldest dropped beyond that) and drains to the actor on attach. Never attaching is
safe: shutdown flushes the buffers synchronously. Calling it again is a no-op while the
actor is alive; if the actor died, the call respawns and re-attaches it.

Semantics worth knowing:

- **Loss model.** The actor's mailbox is `bounded(256, DropOldest)`: under sustained
  collector outage the oldest queued batches are evicted in favor of fresh telemetry.
  The `nexus.observability.export.dropped` counter (attributes `signal`, `reason`) counts
  `buffer_full` (pre-attach overflow) and `export_failed` (a batch the collector rejected
  or that threw); mailbox evictions themselves are silent by design — loss is bounded,
  not fully itemized.
- **Shutdown order.** Call `$system->shutdown(...)` first (the actor's `PostStop`
  force-flushes the real exporters), then the provider shutdown. Reversed order degrades
  gracefully: forwarders detect the dead actor and export synchronously.
- **Failure containment.** A throwing exporter drops only that batch (counted + logged at
  debug via a non-OTel logger — the export path emits no telemetry about itself; its
  messages are `UntracedMessage`). The actor restarts under exponential-backoff
  supervision.
- **Runtime honesty.** True stall isolation requires Swoole (coroutine-hooked I/O, proven
  by `tests/Integration/Swoole/AsyncOtlpExportStallTest.php`). On the Fiber runtime an
  in-flight flush still blocks the process up to the transport timeout; the actor still
  provides bounded queues, batching isolation, and identical semantics.
- **Load characteristics** (indicative, Docker-on-macOS, single reactor —
  `tests/Performance/AsyncOtlpExportPerformanceTest.php`): the producer-side `export()`
  call sustains ~750K batch enqueues/sec (~1.3 µs each — it is a mailbox enqueue, no
  I/O), and application actors held ~270K msg/sec while a deliberately slow collector
  (50 ms per flush) drained concurrently. System-level: the 16-node cluster round-trip
  demo passes 16/16 with async export enabled
  (`OTEL_NEXUS_ASYNC_EXPORT=1 ./run-roundtrip.sh`), with slightly better RTT p50 than
  inline export. Run the benchmarks:
  `docker compose exec php-swoole vendor/bin/phpunit --testsuite=performance --filter=AsyncOtlp`.

## See also

- [Observability overview](../observability/overview.md) — end-to-end wiring guide
- [Observability configuration](../observability/configuration.md) — env vars reference
- [nexus-observability](./observability.md) — vendor-neutral contracts
