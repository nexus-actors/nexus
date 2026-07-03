# Psalm Rule: UntypedActorRefInjection — Design

**Date:** 2026-07-03
**Package:** `nexus-psalm`
**Status:** Approved

## Problem

`ActorRef<T>` is only type-safe when the generic message type is declared. When an
`ActorRef` is injected into a controller or service (constructor, property, or method
parameter) without a `@param ActorRef<MessageType>` / `@var ActorRef<MessageType>`
annotation, Psalm treats it as an untyped ref and `tell()` accepts any object — the
type safety the actor system is built around silently disappears at every DI boundary.

## Goal

A Psalm plugin rule that makes an untyped `ActorRef` injection a static-analysis
error. Every injected `ActorRef` (or subtype) must declare a concrete message type.

## Decisions

1. **Scope: all injection points.** Constructor parameters (including promoted
   properties), class properties (via `@var`), and all method/function parameters.
   Return types are out of scope.
2. **Strictness: `ActorRef<object>` is flagged too.** A concrete message type is
   required; `ActorRef<object>` is treated the same as a bare `ActorRef`. The escape
   hatch is the standard `@psalm-suppress UntypedActorRefInjection`.
3. **Subtypes covered.** Any type extending/implementing
   `Monadial\Nexus\Core\Actor\ActorRef` is checked — `LocalActorRef`,
   `WorkerActorRef`, `MessengerActorRef`, and future refs. The rule cannot be
   bypassed by hinting a concrete class.
4. **Excluded refs via plugin config, not suppressions.** Internal accept-anything
   refs are exempt through an exclude list rather than scattering
   `@psalm-suppress` across the codebase. `DeadLetterRef` (the only non-generic
   internal ref, declared `@implements ActorRef<object>`) is excluded by default.
   Users can exclude additional ref classes in `psalm.xml`.

## Design

### New issue

`packages/nexus-psalm/src/Issue/UntypedActorRefInjection.php`

Follows the existing issue pattern (`MutableActorState`, `NonReadonlyMessage`).
Message shape:

> ActorRef injection for `$name` in `ClassName` must declare a concrete message
> type, e.g. `ActorRef<MyCommand>` — bare `ActorRef` and `ActorRef<object>` are
> not allowed.

### New hook

`packages/nexus-psalm/src/Hook/UntypedActorRefInjectionRule.php` implementing:

- `AfterClassLikeAnalysisInterface` — walks `ClassLikeStorage::$properties`
  (covers regular properties and promoted constructor params).
- `AfterFunctionLikeAnalysisInterface` — walks function-like parameter storage
  (covers constructor and method/function parameters).

Detection per declared type union, per atomic type:

| Atomic type | Verdict |
|---|---|
| `TNamedObject` of ActorRef subtype (no generic) | **Flag** |
| `TGenericObject` with type param exactly `object` | **Flag** |
| `TGenericObject` with concrete class type param | Pass |
| `TGenericObject` with `TTemplateParam` (e.g. `ActorRef<T>` in nexus-core) | Pass |
| Non-ActorRef types | Ignore |

Nullable (`?ActorRef`) and union positions are handled naturally by checking each
atomic type in the union. "Is an ActorRef subtype" is resolved via Psalm's codebase
class/interface hierarchy checks against `Monadial\Nexus\Core\Actor\ActorRef`, not
string comparison, so indirect subtypes are covered.

De-duplication: promoted constructor params appear in both class-like property
storage and constructor parameter storage; the rule must not report them twice.
Canonical source: the class-like hook reports all properties (promoted or not);
the function-like hook skips promoted parameters.

### Excluded refs (plugin config)

The plugin entry point (`Plugin::__invoke`) already receives an optional
`SimpleXMLElement $config` from `psalm.xml` — currently unused. It gains parsing
for an exclude list:

```xml
<plugins>
    <pluginClass class="Monadial\Nexus\Psalm\Plugin">
        <untypedActorRefInjection>
            <excludeRef class="App\Infra\AuditSinkRef"/>
        </untypedActorRefInjection>
    </pluginClass>
</plugins>
```

Semantics:

- **Default exclude list** (always active, no config needed):
  `Monadial\Nexus\Core\Actor\DeadLetterRef`. User config *extends* the default
  list; it does not replace it.
- An excluded class is exempt as the **declared type** — a bare `DeadLetterRef`
  hint passes. Exclusion matches the declared class exactly plus its subtypes
  (checked via the same codebase hierarchy lookup).
- A bare `ActorRef` hint is still flagged even if the runtime value happens to be
  an excluded ref — exclusion is by declared type only.
- Since hooks are registered as static classes, `Plugin::__invoke` passes the
  merged exclude list to the rule via a static setter on
  `UntypedActorRefInjectionRule` (e.g. `UntypedActorRefInjectionRule::setExcludedRefs()`)
  before registering it.

### Registration

Add `UntypedActorRefInjectionRule::class` to the `$hooks` array in
`packages/nexus-psalm/src/Plugin.php`, after configuring the exclude list from
`$config`.

### Tests

Follow the existing `packages/nexus-psalm/tests` pattern with fixture classes:

**Fail cases**
- Constructor param `ActorRef $ref` with no docblock
- Promoted property `private ActorRef $ref` with no docblock
- Property with `@var ActorRef` (no generic)
- Method param `ActorRef $ref` with no `@param` generic
- Explicit `@param ActorRef<object>`
- Bypass attempt via concrete subtype: `LocalActorRef $ref` with no generic

**Pass cases**
- `@param ActorRef<MyCommand> $ref` on constructor
- `@var ActorRef<MyCommand>` property
- `ActorRef<T>` where `T` is a class template param
- Nullable `?ActorRef` with `@param ActorRef<MyCommand>|null`
- Non-ActorRef parameters (ignored)
- `@psalm-suppress UntypedActorRefInjection` silences the issue
- Bare `DeadLetterRef` hint (default exclude list)
- Bare hint of a class listed in `<untypedActorRefInjection><excludeRef/>` config

## Out of scope

- Return types
- Local variables / closures
- Enforcing that the message type itself is `readonly` (already covered by
  `ReadonlyMessageRule`)
