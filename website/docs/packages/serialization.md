---
sidebar_position: 4
title: nexus-serialization
related:
  - packages/core
  - packages/persistence
  - packages/worker-pool
---

# nexus-serialization

Message serialization and deserialization for Nexus: two serializer implementations, an envelope serializer, and a type registry for stable wire-format identifiers.

## What's in this package

- `MessageSerializer` interface — `serialize(object): string` / `deserialize(string, string $type): object`
- `EnvelopeSerializer` interface — serializes/deserializes complete `Envelope` instances including sender path, target path, and metadata
- `PhpNativeSerializer` — fastest option; uses PHP `serialize`/`unserialize`; not interoperable across different deployments
- `ValinorMessageSerializer` — JSON encoding with Valinor type-safe deserialization; interoperable; requires a `TypeRegistry`
- `DefaultEnvelopeSerializer` — wraps any `MessageSerializer`; envelope structure is JSON, inner message delegates to the wrapped serializer
- `TypeRegistry` — bidirectional mapping between class names and stable wire-format type identifiers; populated manually or via `#[MessageType]`
- `#[MessageType('order.placed')]` — attribute for declaring a stable type name on a message class

## Install

```bash
composer require nexus-actors/serialization
```

## Quick example

```php title="src/Serialization/Setup.php"
use Monadial\Nexus\Serialization\MessageType;
use Monadial\Nexus\Serialization\TypeRegistry;
use Monadial\Nexus\Serialization\ValinorMessageSerializer;

#[MessageType('order.placed')]
final readonly class OrderPlaced
{
    public function __construct(
        public string $orderId,
        public float $total,
    ) {}
}

$registry = new TypeRegistry();
$registry->registerFromAttribute(OrderPlaced::class);

$serializer = new ValinorMessageSerializer($registry);
$wire = $serializer->serialize(new OrderPlaced('ord-1', 99.99));
$msg  = $serializer->deserialize($wire, 'order.placed');
```

Use `PhpNativeSerializer` when both sender and receiver share the same codebase. Use `ValinorMessageSerializer` when messages cross process or language boundaries.

## See also

- [nexus-persistence](./persistence.md) — event stores accept a custom `MessageSerializer`
- [nexus-worker-pool](./worker-pool.md) — `WorkerActorRef::tell()` messages should carry `#[MessageType]` for forward compatibility
