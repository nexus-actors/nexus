# ADR 0004: Valinor-Based Message Serialization

## Status

Accepted

## Context

When actors communicate across process boundaries (clustering), messages must be serialized. PHP's native `serialize()` is fast but tightly coupled to class structure — any refactoring breaks deserialization. JSON is human-readable but lacks type information. We need a serialization layer that:

1. Supports evolving message schemas (rename fields, add defaults).
2. Preserves type safety during deserialization.
3. Works with `readonly class` messages.
4. Allows custom transformers for complex types.

## Decision

Use CuyZ/Valinor as the serialization backbone:

- **`TypeRegistry`**: Maps message class names to string type identifiers for wire format stability.
- **`ValinorSerializer`**: Converts messages to/from associative arrays using Valinor's normalizer and mapper.
- **`SerializationEnvelope`**: Wraps serialized payload with type identifier and metadata for transport.
- **Custom transformers**: Register Valinor normalizers/mappers for types like `ActorRef`, `Duration`, etc.

The `nexus-serialization` package is optional — only needed for clustering.

## Consequences

- Message classes must be compatible with Valinor (constructor-based hydration).
- Type identifiers decouple wire format from PHP class names.
- Schema evolution is handled by Valinor's flexible mapping (extra fields ignored, defaults applied).
- Serialization overhead is acceptable for cross-process messaging (not used for local actors).
