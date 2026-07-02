---
title: nexus-observability-swoole
related:
  - observability/overview
  - packages/observability
  - packages/runtime-swoole
---

# nexus-observability-swoole

Swoole observability — coroutine-aware OTEL context storage so active spans are isolated per coroutine, and Swoole server/coroutine statistics as observable gauges.

## Install

```bash title="terminal"
composer require nexus-actors/observability-swoole
```

## What's in this package

| Class | Purpose |
|---|---|
| `SwooleContextRegistrar` | Installs `SwooleContextStorage` as the OTEL context backend; call once per worker at startup; idempotent |
| `SwooleAdminMetrics` | Registers Swoole server/coroutine statistics as OTEL observable gauges; `registerCoroutineGauges()` and `registerServerGauges(Server $server)` |

## Quick example

```php title="src/WorkerPool/Bootstrap.php" verify:lint-only
use Monadial\Nexus\Observability\Swoole\SwooleAdminMetrics;
use Monadial\Nexus\Observability\Swoole\SwooleContextRegistrar;

// 1. Install coroutine-isolated context storage (must run inside a worker coroutine)
SwooleContextRegistrar::install();

// 2. Register system gauges — $server is a Swoole\Server instance
$metrics = new SwooleAdminMetrics($obs);
$metrics->registerCoroutineGauges();
$metrics->registerServerGauges($server);
```

Without `SwooleContextRegistrar::install()`, the active span leaks across coroutines because the default OTEL context storage is not coroutine-aware. Call `install()` before starting any telemetry work on each worker thread.

## Emitted gauges

| Metric | Description |
|---|---|
| `swoole.coroutine.count` | Number of running coroutines |
| `swoole.coroutine.peak` | Peak concurrent coroutines |
| `swoole.server.connections` | Active server connections |
| `swoole.server.requests` | Total requests handled |
| `swoole.server.workers.idle` | Idle worker processes |

Gauges are collected on demand by the OTEL metric reader — no polling loop required. All are server-wide with no high-cardinality dimensions.

## See also

- [Observability overview](../observability/overview.md) — end-to-end wiring guide
- [nexus-observability](./observability.md) — vendor-neutral contracts
- [nexus-runtime-swoole package](./runtime-swoole.md) — Swoole coroutine runtime
