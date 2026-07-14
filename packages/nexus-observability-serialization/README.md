# nexus-observability-serialization

Tracing decorator for [`nexus-actors/serialization`](https://github.com/nexus-actors/serialization) message serializers.

Part of the [nexus-actors/nexus](https://github.com/nexus-actors/nexus) monorepo — read-only subtree split.

## Installation

```bash
composer require nexus-actors/observability-serialization
```

## Usage

Wrap any `MessageSerializer` with `TracingMessageSerializer` to get automatic
spans, metrics, and optional failure logging:

```php
use Monadial\Nexus\Observability\Serialization\TracingMessageSerializer;

$serializer = new TracingMessageSerializer(
    inner: new PhpNativeSerializer(),
    observability: $observability,
    logger: $logger,     // optional — PSR-3 LoggerInterface
);
```

### What gets instrumented

| Signal | Name | Unit | Notes |
|--------|------|------|-------|
| Span | `serialization.serialize` / `serialization.deserialize` | — | `SpanKind::Internal`; attrs: `nexus.message.type`, `nexus.serializer` |
| Counter | `nexus.serialization.operations` | `{operation}` | Incremented on success; attrs: op + type + serializer |
| Histogram | `nexus.serialization.bytes` | `By` | Payload size; attr: op |
| Histogram | `nexus.serialization.duration` | `ms` | Wall time per call; attr: op |
| Counter | `nexus.serialization.failures` | `{operation}` | Incremented on error; same attrs |

When `observability->isEnabled()` is `false` **and** no logger is wired, the call
passes straight through to the inner serializer with zero telemetry overhead.
