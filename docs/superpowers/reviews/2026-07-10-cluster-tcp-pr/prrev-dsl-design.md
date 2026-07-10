# DSL / Design Review — feat/cluster-tcp (nexus-cluster-tcp)

**Reviewer scope:** public API ergonomics, type safety, framework-philosophy consistency, internal seams, error surface.
**Branch:** feat/cluster-tcp @ 6c343f2b vs main.

## Verdict: **Approve with nits**

The public surface (`ClusterNode`, `ClusterTopology`, `ClusterRef`, `NodeEndpoint`, `TlsConfig`, exceptions) is coherent, well-documented, and follows the framework's immutability-first / functional-composition conventions closely. Internals show a genuinely clean pure-core/imperative-shell split (`MembershipService` / `MembershipActor` / `TcpMembershipEffectInterpreter`). Findings below are polish items, not blockers — one (`isAlive()` always-true) is a pre-acknowledged, tracked gap (C1.6b) rather than an oversight.

---

## Findings

### Medium

1. **`ClusterRef::isAlive()` is dead code — always returns `true`.**
   `packages/nexus-cluster-tcp/src/Messaging/ClusterRefFactory.php:41-56` — `refFor()` accepts `?Closure $aliveChecker = null` but defaults to `static fn(): bool => true`. No caller anywhere (not `ClusterNode::refFor()` at `ClusterNode.php:366-369`, not the example app, not tests beyond asserting the stub) ever wires a real liveness probe from `ClusterView`. The factory's own docblock says *"C1.6b wires this to the cluster ClusterView"* — that wiring doesn't exist yet. Until it does, `ActorRef::isAlive()` — a documented core contract — silently lies for every `ClusterRef`, including refs to genuinely `Down`/`Suspect` nodes. Since this is user-facing (part of the `ActorRef<T>` contract every ref must honor per the location-transparency promise), consider either wiring it before merge or making the gap explicit in `ClusterRef`'s own docblock/`@todo` (currently only `ClusterRefFactory` mentions it) so a caller relying on `isAlive()` for routing decisions isn't misled.

2. **`ClusterTopology::create()` doesn't expose `minimumMembers`.**
   `packages/nexus-cluster-tcp/src/ClusterTopology.php:68-127` — every other timing/limit knob (`heartbeatInterval`, `phiThreshold`, `maxInboundLinks`, etc.) is either a `create()` param or has a matching wither with validation; `minimumMembers` only has `withMinimumMembers()` (line 186) and is hardcoded to `0` inside `create()` (line 122). That's a defensible "off by default, opt-in via wither" design (matches `withTls`, `withAuthSecret`), but it's the *only* validated int knob not mentioned in `create()`'s own doc-comment enumeration of parameters — a new user configuring split-brain protection has to already know `withMinimumMembers` exists. Minor discoverability gap, not a design flaw.

3. **Two different "auto-select vs. override" idioms for the same concept.**
   `ClusterNode::boot()`'s `$transport` param (`ClusterNode.php:187-193`) and `ClusterTopology`'s `$tls`/`$authSecret` are both "pass null for automatic/insecure default," but transport auto-selection lives in `ClusterNode` (extension/runtime detection) while topology's are plain nullable value withers. Not wrong, just worth a one-line note in `boot()`'s docblock cross-referencing why transport selection needs runtime introspection but topology knobs don't (the current docblock already explains the *what*, not *why it's shaped differently* from topology withers). Nit, no action required.

### Low / Nits

4. **`ClusterNode::receptionist()` is a documented `never`-return stub that throws `BadMethodCallException`** (`ClusterNode.php:541-544`). Reasonable as a forward-declared seam for C2, but it's a public method on the primary bootstrap class that exists purely to throw — a `@psalm-api`-tagged class ends up advertising an API surface member that cannot be called. Consider `@internal`-marking or omitting until C2 lands, since its presence in the class outline is slightly confusing for a first-time reader of `ClusterNode`.

5. **`ClusterTopology::create()` parameter list is long (14 params) and only partially named-arg-friendly** — acceptable given PHP 8+ named arguments are the intended call style (confirmed by the `@example` block using them), but a couple of parameters (`maxInboundLinks`, `singleNode`, `tls`) are interleaved with timing `Duration`s in a way that doesn't group logically (identity → network → seeds → timing → limits → security would read better than the current order). Not urgent; withers already let most callers skip most params.

6. **`ClusterNode` remains a god-class by line count (~1200 loc)** but internally it *is* organized into clear labeled sections (frame pump wiring, frame parsing, span helpers, static factory helpers) with `// ----` banner comments. For a class explicitly flagged elsewhere as "refactor deferred," this is about as coherent as a monolith can be — most methods are single-responsibility private helpers; the size comes from wiring 10+ collaborators in `boot()`, not from tangled logic. No action needed now, but if/when it's split, `boot()`'s 12 numbered steps are already a natural extraction map.

---

## Strengths

- **Location transparency honored on the happy path.** `ClusterRef::tell()`/`ask()` (`Messaging/ClusterRef.php`) short-circuit local sends before touching the wire, matching `ActorRef<T>` semantics exactly (`tell()` fire-and-forget, `ask()` returns `Future` and throws `AskTimeoutException`/`AskCapacityExceededException`) — a user genuinely cannot tell from the call site whether a ref is local or remote.
- **Immutability discipline is exemplary.** `ClusterTopology`, `NodeEndpoint`, `TlsConfig`, `Handshake`, and all Membership event/message VOs are `final readonly`; every mutator is a wither returning a new instance via `clone($this, [...])`, exactly matching `Duration`/`Props`/`Behavior` conventions elsewhere in the framework.
- **`#[MessageType]` enforcement is real and typed, matching `nexus-messenger`'s pattern.** `ClusterMessageCodec::encode()`/`decode()` throw `MessageSerializationException`/`MessageDeserializationException` with clear messages when a class lacks a registered wire type; `decode()` explicitly treats the wire-supplied type string as untrusted input and never falls back to instantiating an arbitrary class name — a real security property, well-commented.
- **Boot-time validation is thorough and fail-fast.** `ClusterTopology::create()` and every wither (`withAuthSecret`, `withMinimumMembers`, `withMaxFrameSize`, `withInboundLimits`) validate eagerly and throw `InvalidArgumentException` with actionable messages rather than deferring to a confusing runtime failure.
- **`MembershipService` (pure) / `MembershipActor` (shell) / `TcpMembershipEffectInterpreter` (I/O) is a clean, textbook separation.** The service is a pure state-transition function returning `MembershipTransition` (new state + events + effects); the actor's only jobs are dispatch, event publishing, and effect interpretation; the interpreter is the only place that touches sockets/serialization. This is easy for a maintainer to reason about and test in isolation.
- **Defensive engineering throughout**, consistently commented with *why*: bounded FIFO caps on `MutableEndpointRegistry` and `ClusterNode`'s Leave-dedup set (DoS-resistant), HMAC handshake auth with nonce-replay protection and constant-time comparison, swallow-safe telemetry (`safely()` helpers) so a broken tracer/meter never disrupts cluster operation, slowloris guard on unauthenticated inbound links.
- **Exception surface is clear and typed**: `AskTimeoutException`, `AskCapacityExceededException`, `PeerUnreachableException` (implements `FutureException` so it composes with `FutureSlot::fail()`), `ProtocolException` (extends the framework's `NexusException` base) — each documented with when it's thrown and why, not generic `RuntimeException` soup.
