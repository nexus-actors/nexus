# S1 — MessagePack Serializer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** New package `nexus-serialization-msgpack` — a binary `MessageSerializer` with an ext-msgpack fast path and pure-PHP fallback, per spec `docs/superpowers/specs/2026-07-05-cluster-receptionist-design.md` §2/S1.

**Architecture:** `MessagePackMessageSerializer` mirrors `ValinorMessageSerializer` exactly, swapping the JSON codec for msgpack: serialize = registry name check + object→array→msgpack bytes; deserialize = msgpack bytes→array→`TreeMapper::map($class, $array)`. A tiny internal codec pair dispatches to `msgpack_pack/unpack` when ext-msgpack is loaded, else `rybakit/msgpack` `Packer`/`BufferUnpacker`.

**Tech Stack:** rybakit/msgpack ^0.9 (hard dep), ext-msgpack (optional fast path — NOT in our Docker images, so CI exercises the pure path; ext path covered by skipped-unless-loaded tests), cuyz/valinor (already a nexus-serialization dep), PHPUnit 13, Psalm level 1.

## Global Constraints

- Branch: NEW branch `feat/msgpack-serializer` off current `main` after PR #50 merges — OR, if PR #50 is still open when this executes, stack on `feat/nexus-messenger` (controller decides at dispatch; record in ledger).
- All commands through Docker; NEVER add Claude attribution; GrumPHP gates every commit; repo style gates (strict_types, final/readonly, `#[Override]`, sorted string-keyed literals, blank line before control structures, trailing commas, `@psalm-api` docblocks with usage examples).
- Grounded facts (verified): `ValinorMessageSerializer` hydration = `(new MapperBuilder())->allowPermissiveTypes()->mapper()` then `$mapper->map($className, $decodedArray)`; `TypeRegistry::nameForClass/classForName`; `MessageSerializer` contract throws `MessageSerializationException`/`MessageDeserializationException`; ext-msgpack absent from both containers; rybakit/msgpack not yet installed anywhere.
- Object→array before packing: `json_decode(json_encode($message), true)` is the cheap v1 normalizer (matches what Valinor accepts back); do NOT hand-roll reflection extraction.

### Task S1.1: Package scaffold + monorepo wiring + codec

**Files:** `packages/nexus-serialization-msgpack/composer.json` (name `nexus-actors/serialization-msgpack`; requires php >=8.5.7, `nexus-actors/serialization: dev-main`, `cuyz/valinor: ^2.3`, `rybakit/msgpack: ^0.9`; `suggest: ext-msgpack`), root composer.json (autoload both maps + root require `rybakit/msgpack: ^0.9` + `composer update rybakit/msgpack`), phpunit.xml (unit suite dir + coverage source), deptrac.yaml (layer `SerializationMsgpack` → `Serialization`), split.yml + `gh repo create nexus-actors/serialization-msgpack`, CHANGELOG, CLAUDE.md graph line, README (subtree template).
Create `src/MsgpackCodec.php` — `@internal final readonly class` with `pack(array): string` / `unpack(string): array` dispatching on `extension_loaded('msgpack')` (constructor-cached bool, overridable ctor param `?bool $useExtension = null` for tests); rybakit path uses `Packer()->pack()` / `(new BufferUnpacker($bytes))->unpack()`; non-array unpack result → `UnexpectedValueException` (wrapped by the serializer later). TDD: pure-path round-trip (nested arrays, ints, floats, strings, bools, nulls), non-array payload throws; ext-path tests `#[RequiresPhpExtension('msgpack')]`.
Commit: `feat(serialization-msgpack): package scaffold with dual-backend msgpack codec`

### Task S1.2: `MessagePackMessageSerializer`

**Files:** `src/MessagePackMessageSerializer.php`, tests `tests/Unit/MessagePackMessageSerializerTest.php`.
Contract — mirror `ValinorMessageSerializer` verbatim except the codec:
```php
final readonly class MessagePackMessageSerializer implements MessageSerializer
{
    public function __construct(
        private TypeRegistry $registry,
        ?MapperBuilder $mapperBuilder = null,
        private MsgpackCodec $codec = new MsgpackCodec(),
    ) { /* mapper built as Valinor twin */ }
    // serialize(): nameForClass() ?? throw MessageSerializationException; object→array via json round-trip; codec->pack()
    // deserialize(): classForName($type) ?? $type (SAME resolution as ValinorMessageSerializer incl. class-name fallback);
    //   codec->unpack() (Throwable → MessageDeserializationException); mapper->map($className, $array)
    //   (MappingError → MessageDeserializationException)
}
```
TDD tests mirror `ValinorMessageSerializerTest`'s cases: round-trip registered type; class-name fallback; unregistered serialize throws; garbage bytes → `MessageDeserializationException`; mapping mismatch → `MessageDeserializationException`; output is genuinely binary (`assertNotSame` vs JSON, contains non-UTF8-printable bytes for an int-heavy fixture); parity test — pack with rybakit, unpack with rybakit (ext parity test `#[RequiresPhpExtension('msgpack')]`: ext-packed bytes unpack identically via rybakit and vice versa).
Commit: `feat(serialization-msgpack): MessagePack MessageSerializer with Valinor hydration`

### Task S1.3: Bridge integration proof + docs

**Files:** `tests/Integration/Serialization/MsgpackBridgeTest.php` (suite `integration-serialization`): `NexusMessengerSerializer(new MessagePackMessageSerializer($registry), $registry)` over InMemoryTransport — full encode→decode envelope round-trip incl. bridge stamps (headers stay strings; body is binary); note in docs that text-only transports (SQS) need base64 — add `Base64MessageSerializer` decorator? NO (YAGNI) — document only.
Docs: `website/docs/packages/serialization-msgpack.md` (+ sidebar, related links from packages/serialization.md and packages/messenger.md), serialization guide section update, CHANGELOG already done in S1.1. Site build gate.
Commits: `test(serialization-msgpack): bridge envelope round-trip over msgpack bodies`, `docs(serialization-msgpack): package page and guidance`

### Task S1.4: Final verification + PR

Full battery (unit, integration-serialization, psalm, deptrac, cs/phpcs, composer validate/audit, website build). Push branch, `gh pr create` titled `feat: nexus-serialization-msgpack — binary message bodies` with summary + test plan.
