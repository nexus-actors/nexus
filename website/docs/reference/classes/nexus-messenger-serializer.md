---
title: NexusMessengerSerializer
sidebar_position: 30
related:
  - packages/messenger
  - packages/serialization
---

# NexusMessengerSerializer

Symfony Messenger `SerializerInterface` backed by a Nexus `MessageSerializer`; encodes and decodes envelopes for broker transports with full round-trip support for Nexus bridge stamps.

## What it does

`NexusMessengerSerializer` implements Messenger's `SerializerInterface` so the bridge can use any Nexus `MessageSerializer` (Valinor, PHP-native, or custom) as the wire format for broker transports instead of Symfony's default PHP serializer.

**Message bodies** are serialized by the injected `MessageSerializer`. The message type travels in the `type` header. Encode and decode are asymmetric: encode uses the `#[MessageType]`-registered name when available and falls back to the FQCN; decode requires the header value to be registered in the `TypeRegistry` and throws `MessageDecodingFailedException` otherwise. To deliberately accept a FQCN header on decode, register the class as its own type name: `$registry->register(Foo::class, Foo::class)`.

**Bridge stamps** round-trip as plain string headers and are fully restored on `decode()`. Non-bridge stamps are **not** preserved in v1 — if you need full Symfony stamp fidelity or interoperability with non-Nexus producers, swap in any other `SerializerInterface`.

## Constructor

```php
use Monadial\Nexus\Messenger\Serialization\NexusMessengerSerializer;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;

new NexusMessengerSerializer(
    messages: MessageSerializer $messages,
    types: TypeRegistry $types,
);
```

| Parameter | Type | Description |
|---|---|---|
| `$messages` | `MessageSerializer` | Nexus serializer for message bodies. |
| `$types` | `TypeRegistry` | Registry mapping `#[MessageType]` names to PHP class names and back. |

## Methods

| Method | Signature | Description |
|---|---|---|
| `encode` | `encode(Envelope $envelope): array{body: string, headers: array<string, string>}` | Serialize the message body and collect bridge stamp headers. |
| `decode` | `decode(array $encodedEnvelope): Envelope` | Deserialize body and restore bridge stamps from headers. Throws `MessageDecodingFailedException` on missing or unknown type. |

## Wire headers

| Header | Value | Notes |
|---|---|---|
| `type` | `#[MessageType]` name (encode: FQCN fallback) | Required. Encode falls back to FQCN when unregistered; decode throws `MessageDecodingFailedException` if the value is not in the `TypeRegistry`. |
| `X-Nexus-Source-Path` | Actor path string | Present when a `SourceActorPathStamp` is on the envelope. |
| `X-Nexus-Target-Path` | Actor path string | Present when a `TargetActorPathStamp` is on the envelope. |
| `X-Nexus-Trace-Context` | JSON object `{"traceparent":"…", …}` | Present when a `TraceContextStamp` is on the envelope. Malformed JSON or non-string-map values are silently skipped on decode. |

## Example

```php title="config/messenger.php"
use Monadial\Nexus\Messenger\Serialization\NexusMessengerSerializer;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;

// Register the serializer with a Messenger transport
$serializer = new NexusMessengerSerializer($messageSerializer, $typeRegistry);

// Pass to transport factory — exact API depends on the transport implementation
$transport = new RedisTransport($connection, $serializer);
```

:::note Non-bridge stamps are not preserved
Only `SourceActorPathStamp`, `TargetActorPathStamp`, and `TraceContextStamp` round-trip through the wire headers. All other Symfony stamps are dropped on encode and not reconstructed on decode. This is intentional in v1 — use a Symfony `Serializer`-backed serializer if you need full stamp fidelity.
:::

## Full API reference

[Full class and method signatures](https://api.nexusactors.com/classes/Monadial-Nexus-Messenger-Serialization-NexusMessengerSerializer.html)

## See also

- [nexus-messenger package](../../packages/messenger) — bridge overview and full wiring guide
- [nexus-serialization package](../../packages/serialization) — `MessageSerializer` and `TypeRegistry`
- [Attributes — #[MessageType]](../attributes.md#messagetype) — type name registered in `TypeRegistry`
- [Messenger bridge guide](../../guides/messenger-bridge) — serializer wiring section
