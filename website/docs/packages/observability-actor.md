---
title: nexus-observability-actor
related:
  - observability/metrics
  - packages/observability
  - packages/core
---

# nexus-observability-actor

Actor-system observability — internal actor-system state as OTEL observable gauges: live actor count, dead-letter total, and system running status. Collected on demand by the metric reader with zero polling overhead.

## Install

```bash title="terminal"
composer require nexus-actors/observability-actor
```

## What's in this package

| Class | Purpose |
|---|---|
| `ActorSystemMetrics` | Registers `nexus.actor_system.*` gauges from `ActorSystem` state; call `register()` once at startup |

## Quick example

```php title="src/Bootstrap/MetricsSetup.php" verify:lint-only
use Monadial\Nexus\Observability\Actor\ActorSystemMetrics;

$metrics = new ActorSystemMetrics($obs, $system);
$metrics->register();
// Gauges are now collected on demand by the OTEL metric reader
```

Call `register()` once per system at startup. Calling it more than once registers duplicate instruments. The method is a no-op when `$obs->isEnabled()` returns `false`.

## Emitted gauges

| Metric | Description |
|---|---|
| `nexus.actor_system.live_actors` | Number of live root actors in the system |
| `nexus.actor_system.dead_letters` | Total dead-lettered messages captured |
| `nexus.actor_system.running` | `1` while the system is running, `0` otherwise |

All gauges are dimensionless — there is no per-actor or per-message cardinality.

## See also

- [Observability metrics guide](../observability/metrics.md) — metric collection and dashboards
- [nexus-observability](./observability.md) — vendor-neutral contracts
- [nexus-core package](./core.md) — `ActorSystem` and the actor model
