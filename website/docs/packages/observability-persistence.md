---
title: nexus-observability-persistence
related:
  - observability/tracing
  - observability/metrics
  - packages/observability
  - packages/persistence
---

# nexus-observability-persistence

Persistence observability — tracing decorators for event, snapshot, and durable-state stores. Each decorator wraps the real store, opens an Internal span per operation, records duration metrics, and propagates store errors unmodified. Telemetry errors never break persistence.

## Install

```bash title="terminal"
composer require nexus-actors/observability-persistence
```

## What's in this package

| Class | Purpose |
|---|---|
| `TracingEventStore` | Decorator for `EventStore`: spans `persist`, `load`, `deleteUpTo`, `highestSequenceNr`; counter `nexus.persistence.events.persisted` |
| `TracingSnapshotStore` | Decorator for `SnapshotStore`: spans `save`, `load`, `delete`; counter `nexus.persistence.snapshots.saved` |
| `TracingDurableStateStore` | Decorator for `DurableStateStore`: spans `get`, `upsert`, `delete` |

All three decorators share the `nexus.persistence.operation.duration` histogram (seconds) with `nexus.persistence.entity.type` and `operation` dimensions.

## Quick example

```php title="src/Persistence/PersistenceSetup.php" verify:lint-only
use Monadial\Nexus\Observability\Persistence\TracingDurableStateStore;
use Monadial\Nexus\Observability\Persistence\TracingEventStore;
use Monadial\Nexus\Observability\Persistence\TracingSnapshotStore;

// Wrap the real stores with tracing decorators
$eventStore = new TracingEventStore($innerEventStore, $obs);
$snapshotStore = new TracingSnapshotStore($innerSnapshotStore, $obs);
$durableStateStore = new TracingDurableStateStore($innerDurableStateStore, $obs);
```

When `$obs->isEnabled()` returns `false`, each decorator calls the inner store directly with zero overhead.

## Emitted spans and metrics

| Name | Kind | Dimensions |
|---|---|---|
| `EventStore.persist` | Internal span | `nexus.persistence.entity.type`, `nexus.persistence.id`, `nexus.persistence.event.count` |
| `EventStore.load` | Internal span | `nexus.persistence.entity.type`, `nexus.persistence.from_sequence_nr` |
| `SnapshotStore.save` | Internal span | `nexus.persistence.entity.type`, `nexus.persistence.id` |
| `DurableStateStore.get` | Internal span | `nexus.persistence.entity.type`, `nexus.persistence.id` |
| `nexus.persistence.events.persisted` | Counter | `nexus.persistence.entity.type` |
| `nexus.persistence.snapshots.saved` | Counter | `nexus.persistence.entity.type` |
| `nexus.persistence.operation.duration` | Histogram (s) | `nexus.persistence.entity.type`, `operation` |

## See also

- [Observability tracing guide](../observability/tracing.md) — span lifecycle
- [nexus-observability](./observability.md) — vendor-neutral contracts
- [nexus-persistence package](./persistence.md) — event sourcing and durable state
