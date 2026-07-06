---
title: nexus-serialization-msgpack
related:
  - packages/serialization
  - packages/observability-serialization
  - packages/messenger
  - guides/messenger-bridge
---

# nexus-serialization-msgpack

Binary MessagePack serialization for Nexus messages — a `MessageSerializer` producing compact binary payloads, with a dual-backend codec that uses the native `ext-msgpack` extension when available and falls back to the pure-PHP `rybakit/msgpack` library otherwise.

## What's in this package

| Class | Purpose |
|---|---|
| `MessagePackMessageSerializer` | `MessageSerializer` implementation: MessagePack encoding, Valinor type-safe decoding, `TypeRegistry`-backed type names |
| `MsgpackCodec` | Internal dual-backend pack/unpack codec (native `ext-msgpack` or pure-PHP `rybakit/msgpack`) |

## Install

```bash title="terminal"
composer require nexus-actors/serialization-msgpack
```

The pure-PHP `rybakit/msgpack` backend is a hard dependency, so the package works on any PHP 8.5+ install. The native extension is optional:

```bash title="terminal (optional, faster packing)"
pecl install msgpack
```

When `ext-msgpack` is loaded, `MsgpackCodec` selects it automatically — no configuration needed. Both backends produce wire-compatible bytes, so producers and consumers may run different backends.

## Quick example

```php title="src/Serialization/MsgpackSetup.php" verify:lint-only
use Monadial\Nexus\Serialization\Msgpack\MessagePackMessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;

$registry = new TypeRegistry();
$registry->registerFromAttribute(OrderPlaced::class);

$serializer = new MessagePackMessageSerializer($registry);

$bytes = $serializer->serialize(new OrderPlaced('ord-1', 99.99)); // binary string
$msg = $serializer->deserialize($bytes, 'order.placed');
```

Serialization requires the message class to be registered in the `TypeRegistry` (via `#[MessageType]` + `registerFromAttribute()` or explicit `register()`) and throws `MessageSerializationException` otherwise. Deserialization resolves `$type` through the registry first and falls back to treating it as a literal class name; malformed bytes or structurally wrong payloads throw `MessageDeserializationException`.

Objects are normalized to arrays via a JSON round-trip before packing — the same shape Valinor maps back — so anything `json_encode()` can represent (including `JsonSerializable` value objects with a matching Valinor constructor) works.

## Codec fallback and trailing-byte safety

`new MsgpackCodec()` auto-detects the backend; pass `new MsgpackCodec(false)` to force the pure-PHP path or `new MsgpackCodec(true)` to force the extension. Two behavioral notes:

- **Trailing bytes**: the pure-PHP path rejects payloads with unexpected bytes after the MessagePack value (`UnexpectedValueException`). The native extension does not perform this check — a truncation/concatenation bug can go unnoticed with `ext-msgpack` where the pure path would fail loudly.
- **Non-array payloads**: both paths reject decoded values that are not arrays.

## Choosing a serialization format

| | `PhpNativeSerializer` | `ValinorMessageSerializer` (JSON) | `MessagePackMessageSerializer` |
|---|---|---|---|
| Wire format | PHP `serialize()` | JSON text | MessagePack binary |
| Payload size | Verbose | Baseline | Smallest — no field quoting, braces, or whitespace |
| Interoperable (non-PHP consumers) | No | Yes | Yes (msgpack libs exist for every major language) |
| Human-readable on the wire | No | Yes | No |
| Deserialization safety | Allow-list required (CWE-502) | Valinor strict mapping | Valinor strict mapping |
| `TypeRegistry` required | No | Yes | Yes |
| Best for | Same codebase both ends, trusted data | Debugging, cross-language, text-only transports | High-volume queues over binary-safe transports |

## Transport caveat: binary-safe brokers only

MessagePack bodies are raw binary. Redis Streams, AMQP/RabbitMQ, and database transports with a BLOB column carry them untouched. Text-oriented transports — **Amazon SQS** most prominently, which rejects message bodies outside a limited character set — will refuse or corrupt binary payloads. On such transports, use the JSON serializer or base64-encode the body at the transport boundary.

## Observing serialization

Wrap the serializer in [`TracingMessageSerializer`](./observability-serialization.md) to record a span per operation and the `nexus.serialization.*` metrics — the bytes histogram makes the msgpack payload-size drop directly measurable.

## See also

- [nexus-serialization](./serialization.md) — `MessageSerializer` contract, `TypeRegistry`, JSON and PHP-native implementations
- [nexus-observability-serialization](./observability-serialization.md) — tracing decorator for any `MessageSerializer`
- [Messenger bridge guide](../guides/messenger-bridge.md) — using a custom serializer with Symfony Messenger transports
