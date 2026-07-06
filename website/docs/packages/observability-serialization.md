---
title: nexus-observability-serialization
related:
  - observability/tracing
  - observability/metrics
  - packages/observability
  - packages/serialization
  - packages/serialization-msgpack
---

# nexus-observability-serialization

Serialization observability — a tracing decorator for any Nexus `MessageSerializer`. Each serialize/deserialize operation opens an Internal span and records operation, byte-size, and duration metrics. Serializer errors propagate unmodified (recorded on the span first); telemetry errors never break serialization.

## Install

```bash title="terminal"
composer require nexus-actors/observability-serialization
```

## What's in this package

| Class | Purpose |
|---|---|
| `TracingMessageSerializer` | Decorator for `MessageSerializer`: spans `serialization.serialize` / `serialization.deserialize`; counters, byte-size and duration histograms; optional PSR-3 warning on failure |

## Quick example

```php title="src/Serialization/TracedSerializerSetup.php" verify:lint-only
use Monadial\Nexus\Observability\Serialization\TracingMessageSerializer;
use Monadial\Nexus\Serialization\Msgpack\MessagePackMessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;

$registry = new TypeRegistry();
$registry->registerFromAttribute(OrderPlaced::class);

$serializer = new TracingMessageSerializer(
    new MessagePackMessageSerializer($registry),
    $observability,          // Monadial\Nexus\Observability\Observability
    $logger,                 // optional PSR-3 — warns on failures
);

// Drop-in replacement anywhere a MessageSerializer is accepted,
// e.g. new NexusMessengerSerializer($serializer, $registry)
```

The decorator wraps any `MessageSerializer` — msgpack, Valinor JSON, or PHP-native. When `$observability->isEnabled()` returns `false` and no logger is given, it delegates directly to the inner serializer with zero overhead.

## Emitted spans

| Span | Kind | Attributes |
|---|---|---|
| `serialization.serialize` | Internal | `nexus.message.type` (message FQCN), `nexus.serializer` (inner serializer short class name) |
| `serialization.deserialize` | Internal | `nexus.message.type` (the `$type` argument — wire type name or FQCN), `nexus.serializer` |

On failure the span records the exception, gets `Error` status, and the error is rethrown to the caller.

## Emitted metrics

| Metric | Instrument | Unit | Attributes |
|---|---|---|---|
| `nexus.serialization.operations` | Counter | `{operation}` | `nexus.message.type`, `nexus.serializer`, `operation` (`serialize` \| `deserialize`) |
| `nexus.serialization.failures` | Counter | `{operation}` | `nexus.message.type`, `nexus.serializer`, `operation` |
| `nexus.serialization.bytes` | Histogram | `By` | `operation` — payload size after serialize / before deserialize |
| `nexus.serialization.duration` | Histogram | `ms` | `operation` |

Duration is always recorded, on success and on failure.

## Logging

Pass an optional PSR-3 logger to get a `warning` on every failed operation with `message_type`, `serializer`, and the exception in context. Logging works even with a disabled (Noop) observability backend.

## See also

- [nexus-serialization-msgpack](./serialization-msgpack.md) — binary MessagePack serializer worth measuring
- [nexus-observability](./observability.md) — vendor-neutral contracts
- [Observability tracing guide](../observability/tracing.md) — span lifecycle
