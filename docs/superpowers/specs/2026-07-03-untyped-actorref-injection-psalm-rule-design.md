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
   properties), class properties (via `@var`), and all method/function/closure
   parameters. The function-like hook fires for closures at no extra cost, so they
   are included. Return types are out of scope (candidate for a follow-up rule —
   a factory returning bare `ActorRef` reintroduces untyped refs at call sites).
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
5. **Container types are recursed.** `array<string, ActorRef>`,
   `list<ActorRef<object>>`, `iterable<ActorRef>` etc. are checked by recursing
   into generic type parameters — otherwise the rule is trivially bypassed by
   wrapping refs in a collection.
6. **Internal `ActorRef<object>` uses are fixed first, exempted last.** The
   monorepo has 44 `ActorRef<object>` positions in 19 src files. Before any
   exemption, a generify pass reduces them:

   - **Method-level generics** where a ref only flows through an API by identity
     (never a typed send): `ActorContext::watch()/unwatch()/stop()`,
     `ActorSystem::stop()`, `ActorCell` watch/unwatch/child helpers gain
     `@template W of object` + `@param ActorRef<W> $target`.
   - **Concrete types** where the message type is provably known:
     `ReceiverActor::$processedListener` / `MessengerBridge::$processedListener`
     only receive `MessagesProcessed` → `ActorRef<MessagesProcessed>`; the
     `NexusLogger` sink only receives `Record` messages.
   - **`ActorRef<object>` remains only where the type is erased by design**:
     heterogeneous children/registry maps (`ActorCell`, `ActorSystem`,
     `ResolvedActorTable`, `PerRequestActorScope`, `WorkerNode`, messenger
     router registries), parent/sender refs, lifecycle plumbing
     (`Watch`/`Unwatch`/`Terminated`/`ChildFailed`/`DeadLetter`), and
     dead-letter sinks — the same positions Akka Typed models as
     `ActorRef[Nothing]`.

   Only the remaining by-design files are exempted in the monorepo `psalm.xml`
   via Psalm's built-in mechanism (no code suppressions), each entry carrying an
   XML comment stating why the type is erased:

   ```xml
   <issueHandlers>
       <UntypedActorRefInjection>
           <errorLevel type="suppress">
               <!-- watch/lifecycle plumbing: refs carried by identity, type erased by design -->
               <file name="packages/nexus-core/src/Message/Watch.php"/>
               <!-- … the other by-design heterogeneous internal files -->
           </errorLevel>
       </UntypedActorRefInjection>
   </issueHandlers>
   ```

   App code stays fully strict. This pattern is documented for users who have
   their own legitimately heterogeneous positions.

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
atomic type in the union. Generic containers (`array`, `list`, `iterable`, and any
`TGenericObject`) are recursed: each type parameter's union is checked with the
same logic, so `array<string, ActorRef>` is flagged like a direct `ActorRef`.
"Is an ActorRef subtype" is resolved via Psalm's codebase class/interface
hierarchy checks against `Monadial\Nexus\Core\Actor\ActorRef`, not string
comparison, so indirect subtypes are covered.

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
- Closure param `fn(ActorRef $ref) => …` with no generic
- Explicit `@param ActorRef<object>`
- Container bypass: `@param array<string, ActorRef>` and `@param list<ActorRef<object>>`
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
- `@param array<class-string, ActorRef<OrderCommand>>` (typed container)
- File listed under `<issueHandlers><UntypedActorRefInjection>` suppress in psalm.xml

## Documentation and examples

The typed-injection pattern must be reflected everywhere the docs show DI of an
ActorRef. Deliverables:

1. **`website/docs/reference/psalm-rules.md`** — new `UntypedActorRefInjection`
   section: what it flags, fail/pass examples, the `<excludeRef>` plugin config,
   and the `issueHandlers` pattern for legitimately heterogeneous positions.
2. **`website/docs/packages/psalm.md`** — add the rule to the rules list and show
   the plugin config block.
3. **`packages/nexus-psalm/README.md`** — same additions as the docs page.
4. **Docs code examples** — every bare `#[FromActor(…)] ActorRef $x` /
   `ActorRef $replyTo` example gains a `@param ActorRef<ConcreteMessage>`
   (or `@var`) annotation. Known pages: `http/handlers.md`,
   `http/actors-in-http.md`, `http/overview.md`, `http/websockets.md`,
   `packages/http.md`, `guides/saga.md`, `operations/observability.md`,
   `operations/troubleshooting.md`, `tutorials/tictactoe.md` (a full sweep is
   part of the plan, this list is the starting point).
5. **Example apps** — `examples/nexus-wallet-app` and `examples/nexus-tictactoe`
   bare `ActorRef` params/properties annotated the same way (examples are not
   Psalm-analyzed, but they are the reference users copy).
6. **CLAUDE.md** — Psalm plugin section: add the new rule to the hook list.
7. **Internal generify pass** — apply the Bucket A method-level generics and
   Bucket B concrete types from Decision 6 across nexus-core, nexus-messenger,
   and nexus-logger; unit tests and Psalm must stay green.
8. **Monorepo `psalm.xml`** — plugin config with defaults plus the
   `issueHandlers` block for the remaining by-design files (with per-entry
   rationale comments); `make psalm` must be green after the sweep.

Known staleness (out of scope, noted for a future pass):
`website/docs/reference/psalm-rules.md` documents only 7 of the 16 existing
hooks.

## Out of scope

- Return types (follow-up rule candidate)
- Local variables
- Enforcing that the message type itself is `readonly` (already covered by
  `ReadonlyMessageRule`)
- Refreshing the psalm-rules reference page for the 9 pre-existing undocumented
  hooks
