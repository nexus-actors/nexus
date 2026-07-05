# Expert Review — Nexus Framework DSL / API Surface

**Reviewer lens:** SDK/DSL design & DX (Akka Typed API-redesign caliber).
**Evidence corpus:** the `nexus-fulfillment` example (three milestones) as built against the framework tree in this worktree (`/.claude/worktrees/fulfillment/packages/`). This is a review **of the framework**, not the example. Every friction below is verified in framework source with file:line.

> Tree note: the example builds against the *worktree* `packages/` (branch `feat/fulfillment-example`), which carries two graduated seams — `EventSourcedBehavior::withSignalHandler` and `withEventPublisher` — that do **not** exist on `main`/`feat/nexus-messenger`. Core (`ActorCell`, `Behavior`, `Props`, `Supervision`, `Mailbox`) is byte-identical across both trees, so those findings are branch-independent. Line numbers below are the worktree tree unless a file is unchanged across trees.

---

## Verdict

Nexus has an unusually *principled* core — location transparency, immutable behaviors, a Psalm plugin that enforces actor discipline. But the DSL is **1-2 milestones short of production-grade**, and the example proves it empirically: to ship one bounded-context CRUD-plus-saga app, the author had to hand-build an entity-sharding layer (three near-identical `*RefFactory` classes), an in-process pub/sub (`ContextBus`), a pool-aware + wire-name-translating store pair (`PooledDoctrineEventStore`), a reflection-based aggregate router (`AggregateBehavior`), and a VO param resolver — and force **two changes into the framework itself** (`withSignalHandler`, `withEventPublisher`). The single loudest signal is a *philosophy inversion*: Nexus advertises "fail-fast," yet the DSL's defaults are pervasively **fail-silent** — `Effect::none()->thenRun()` is a no-op the docblock literally teaches, `tell()` discards its `EnqueueResult`, `Directive::Escalate` degrades to `Stop`, closure routes bypass authorization, and the FQCN event-type trap is invisible in tests but breaks production replay. The second theme is a **type model that assumes one message type per actor** in a world where every real actor handles a union — collapsing typed refs to `ActorRef<object>` plus a suppression tax. None of these are fatal; all are fixable BC-safely. But the DSL should not call itself 1.0 until the "silent success" defaults are inverted and the last-mile production seams (sharding, pub/sub, pooled + wire-named stores, dead-letter query) ship in-framework rather than being re-invented per app.

**Verified weaknesses: 28** — Critical 3, High 11, Medium 11, Low 3.

---

## Weakness Catalog

### W1 — `Effect::none()->thenRun()/thenReply()` is a silent no-op the docblock teaches — CRITICAL
- **Verify:** `Effect::thenRun()`/`thenReply()` preserve `type: $this->type` and only append to `sideEffects` (`nexus-persistence/src/EventSourced/Effect.php:127-143, 154-170`). The engine executes `sideEffects` **only** inside `handlePersist` (`PersistenceEngine.php:248-249`); the `match` sends `EffectType::None → BehaviorWithState::same()` (`PersistenceEngine.php:157`) and never touches `sideEffects`. Damningly, the `Effect` class docblock **advertises the broken form** as a usage example: `return Effect::none()->thenReply($replyTo, fn(CartState $s) => new CartSnapshot($s));` (`Effect.php:26`).
- **Class:** API trap (silently wrong) + docs actively teaching it.
- **Severity:** Critical — the author was bitten (progress ledger P2 T4: "Effect::none()->thenRun drops side-effects (use Effect::reply)"), and the framework's own example code recommends it. A read-only query that replies via `none()->thenReply` silently answers nothing.
- **Fix (BC-safe):** make `thenRun`/`thenReply` on a non-`Persist` effect a fast failure — either throw `LogicException` at construction time when `type !== Persist`, **or** (better DX) execute `sideEffects` for *all* terminal effect types in the engine `match` (run the `None`/`Reply`/`Stop` branches through a shared "runSideEffects then map" tail). Delete the misleading docblock example regardless.

### W2 — `ActorCell::resolveWrappers()` strips outer-wrapper signal handlers — HIGH
- **Verify:** `resolveWrappers()` overwrites `currentBehavior` with the factory's *inner* return, discarding the wrapper (`nexus-core/src/Actor/ActorCell.php:595-620`); `resolveSetup()` returns inner only (`ActorCell.php:623-629`). Signals dispatch solely from `currentBehavior` (`ActorCell.php:720-724`), and unwrap runs (`:171`) before `PreStart` (`:188`). Yet `SetupBehavior`/`SupervisedBehavior`/`WithTimersBehavior`/`WithStashBehavior` each expose an `onSignal()` slot (`SetupBehavior.php:28-40`) — so a user *can* attach a handler that is then silently dropped. `Behavior::setup($f)->onSignal($h)` loses `$h`.
- **Class:** API trap / leaky composition (the wrapper model does not compose).
- **Severity:** High — forced a framework change: `EventSourcedBehavior::withSignalHandler` (worktree `EventSourcedBehavior.php:133`), which re-attaches via `->onSignal()` on the *resolved inner* behavior (`PersistenceEngine.php:166-167`). The example's `AggregateBehavior` depends on it (`AggregateBehavior.php:141`). **Core is still leaky** — the workaround lives in persistence.
- **Fix:** in `resolveWrappers()`, compose the wrapper's `signalHandler` onto the resolved inner behavior (chain/merge) instead of discarding, so `setup(...)->onSignal(...)` works everywhere. Graduated seam (`withSignalHandler`) should stay but shouldn't be *required*.

### W3 — FQCN `eventType` trap: stores persist `$event::class`, break registry-strict serializers & renames — CRITICAL
- **Verify:** engine stamps `eventType: $event::class` (`PersistenceEngine.php:199`). `DoctrineEventStore` persists it verbatim (`nexus-persistence-doctrine/src/DoctrineEventStore.php:36`) and deserializes with it as the target type (`:85`); `DbalEventStore` identical (`:35, :82`). `MessageSerializer::deserialize(string,$type)` treats `$type` as the class. There is **no** `TypeRegistry`/`withTypeNaming` seam on any store (grep: zero matches in the three persistence packages).
- **Class:** API trap (silently wrong; invisible in tests — see W4).
- **Severity:** Critical — with a registry-strict serializer that keys on wire-names (`#[MessageType('orders.order_placed.v1')]`), replay from Postgres is latently broken (ledger P2 T6: "replay-from-Postgres was latently broken + snapshots would fail at event 50"). Any class rename orphans history.
- **Fix / graduate:** the example's `PooledDoctrineEventStore` translates FQCN → wire-name via `TypeRegistry::nameForClass()` before write (`Platform/Persistence/PooledDoctrineEventStore.php:51-79`). Ship a first-class `withTypeNaming(TypeRegistry)` on the Doctrine/Dbal stores (or have the engine stamp the registry name when a registry is present). This is the clearest "example workaround → framework" candidate after `withEventPublisher`.

### W4 — `InMemoryEventStore` never serializes → tests green, production replay red — HIGH
- **Verify:** backing store is `array<string, list<EventEnvelope>>`; `persist()` appends the live envelope (with the live event object) — no serializer dependency at all (`nexus-persistence/src/Event/InMemoryEventStore.php:16-28`); `load()` returns the same objects. The W3 wire-name trap therefore cannot be exercised by any test using the in-memory store.
- **Class:** API trap (test/prod parity gap).
- **Severity:** High — this is *why* W3 shipped latently broken; the integration tests passed.
- **Fix:** provide a `SerializingInMemoryEventStore` (round-trips through the configured `MessageSerializer` + `TypeRegistry`) and recommend it for tests, so wire-name mismatches surface in CI.

### W5 — No pool-aware Doctrine stores → every user writes the `Pooled*` pair — HIGH
- **Verify:** `grep "Pooled"` across `nexus-persistence-doctrine`/`-dbal` = zero. Framework ships only connection-pinning `DoctrineEventStore`/`DoctrineSnapshotStore`. The example hand-writes `PooledDoctrineEventStore` + `PooledDoctrineSnapshotStore` with a `WeakMap<EntityManagerInterface, DoctrineEventStore>` cache (`Platform/Persistence/PooledDoctrineEventStore.php:37-48, 122-132`).
- **Class:** Missing seam.
- **Severity:** High for any Swoole/coroutine deployment — pinning one EM per actor for its lifetime exhausts the pool. Every serious adopter re-derives this boilerplate (ledger: tictactoe has the identical pattern).
- **Fix / graduate:** ship `PooledDoctrineEventStore`/`SnapshotStore` (borrow-per-op + WeakMap-cached wrapper) in `nexus-persistence-doctrine`, parameterized by `EntityManagerPool`.

### W6 — `PersistenceEngine::create()` is a 12-parameter positional list — MEDIUM
- **Verify:** worktree signature has **12** positional params (`PersistenceEngine.php:60-73`) after `signalHandler` + `eventPublisher` were appended. Both callers invoke positionally (`EventSourcedBehavior::toBehavior()` at `:213-214`; `AbstractEventSourcedActor` similarly). Ledger P2 T4 records a mid-signature insertion that broke `AbstractEventSourcedActor` ("Critical mid-signature BC break").
- **Class:** Ergonomics + BC hazard (param-list-of-doom).
- **Severity:** Medium — the fluent `EventSourcedBehavior` builder masks it for users, but every new engine capability risks a positional break, and `create()` is `@api`.
- **Fix:** either make `create()` `@internal` (funnel exclusively through the builder, freeing the signature to evolve), or accept a single `EngineConfig` value object. Never append positionally again.

### W7 — Generic-type erasure across closure boundaries → suppression tax — MEDIUM
- **Verify:** `Effect::thenRun` declares `@template TState` but stores a `Closure(object)` under `@psalm-suppress InvalidArgument` + unchecked `@var TState` cast (`Effect.php:152-167`). Engine carries state as `mixed $data` / `array{state: object}`, invokes the command handler under `@psalm-suppress InvalidArgument` (`PersistenceEngine.php:124-140`), folds events under `@psalm-suppress MixedAssignment` (`:213`). The example pays for this at every aggregate helper: `@psalm-suppress InvalidArgument`/`ArgumentTypeCoercion` on `accepted`/`rejected`/`sideEffects` (`AggregateBehavior.php:100, 118, 126, 152, 206, 231`).
- **Class:** Ergonomics (honest but noisy).
- **Severity:** Medium — `TAgg`/`S`/`TState` all collapse to `object`; a mismatched state/event/reply type is never caught statically, and users learn to reflexively suppress.
- **Fix:** introduce an `AggregateRoot`/`State` marker interface the engine can bind `S` to (the example already invented `SharedKernel/AggregateRoot`; ledger flags "AggregateRoot interface would kill the Mixed suppressions — M4 candidate"). A typed `PersistedState<S>` envelope would recover the fold type.

### W8 — `$app->actor()` never hands back the spawned `ActorRef<T>` — MEDIUM
- **Verify:** `NexusApp::actor()` returns `self`, accumulating an `ActorDefinition` (`nexus-app/src/NexusApp.php:93-98`); `start()` spawns and **discards** the ref (`:165-167`); only `onStart(callable(ActorSystem))` is offered (`:113-118`). The `nexus-http` `ActorRegistration` is likewise a config object with no ref accessor.
- **Class:** Missing seam.
- **Severity:** Medium — composition roots that must wire refs together (bus → subscribers → factories) cannot use the builder. The example abandons `$app->actor()` and spawns directly on the system, with an inline apology: "Spawn ... directly on the system so we can hold the refs for wiring (ActorRegistration does not expose a ref)" (`Platform/Boot/App.php:78-81`).
- **Fix:** return a lazy `ActorRegistration` that resolves to `ActorRef<T>` post-start, or pass a `map<name, ActorRef>` into `onStart`.

### W9 — `AuthorizationMiddleware` silently no-ops on closure routes — HIGH
- **Verify:** `#[RequiresRole]` (and siblings) are `Attribute::TARGET_CLASS` only (`nexus-http-auth/src/Attribute/RequiresRole.php:16`). The middleware reflects the handler *class* (`AuthorizationMiddleware.php:200`) and, when the route handler is a closure (no `_resolvedHandlerClass`), returns `$handler->handle($request)` **unauthenticated** (`:87-93`).
- **Class:** Missing seam / **security trap** (fail-open).
- **Severity:** High — a closure route behind `->middleware(AuthorizationMiddleware::class)` enforces nothing, silently. The example is forced to make every guarded endpoint a class handler with a class-level `#[RequiresRole('ops')]` (`Orders/Infrastructure/Http/PlaceOrderHandler.php:25`; `Routes.php:37-49`), and the ledger notes 4 duplicated role guards it wanted to delete.
- **Fix:** add a route-level `->requiresRole('ops')` fluent API independent of handler kind, **and** fail-closed: if `AuthorizationMiddleware` is attached to a closure route, throw at compile/registration time rather than passing through.

### W10 — No event-bus / pub-sub / `EventStream` primitive — HIGH
- **Verify:** tree-wide grep for `EventStream`/`EventBus`/`Topic`/pub-sub in `packages/*/src` = zero. The only system-level primitive is a PSR-14 `EventDispatcherInterface` that defaults to `NullDispatcher` and is **never called by core** (`ActorSystem.php:266`; no `->dispatch(` call sites). PSR-14 is synchronous listener invocation — no topic fan-out into actor mailboxes, no `ActorRef` subscription.
- **Class:** Missing seam.
- **Severity:** High — every event-driven app must hand-roll fan-out. The example built `ContextBusActor` (`Platform/Bus/ContextBusActor.php`) and wired three `Subscribe` calls (`App.php:87, 93, 131`). The ledger flags its at-most-once/no-replay limitation as a milestone risk.
- **Fix / graduate:** ship an Akka-style `EventStream`/`Topic` actor primitive (subscribe/publish by message class, fan-out into mailboxes). `ContextBusActor` is a ready template.

### W11 — `DeadLetterRef` is a null-object with no queryable surface (and an unbounded array) — HIGH
- **Verify:** `tell()` appends to a private array; the only accessor is an `@internal captured(): list<object>` (`nexus-core/src/Actor/DeadLetterRef.php:38-42, 68-72`). `ActorSystem::deadLetters()` returns the concrete class (`ActorSystem.php:220-222`). `ActorCell` routes undeliverable/unhandled straight there with **no PSR-14 dispatch** (`ActorCell.php:750, 818`). The sole non-test consumer is an OTel gauge reading `count(captured())` (`nexus-observability-actor/src/ActorSystemMetrics.php:42-47`) — a monotone count over an ever-growing array (memory leak), with no per-message type/reason/timestamp.
- **Class:** Missing seam / observability gap + latent leak.
- **Severity:** High — the example "couldn't even test unhandled routing directly." No first-class way to alert on, inspect, or bound dead letters.
- **Fix:** dispatch a `DeadLettered` PSR-14 event (message + reason + origin), back the sink with a bounded ring buffer, and expose a public `DeadLetterQueue` interface with count/peek.

### W12 — Identifier-grammar mismatch: `ActorPath` rejects `|`, `PersistenceId` uses `|` — MEDIUM
- **Verify:** `ActorPath` name charset is `/^[a-zA-Z0-9_\-\.]+$/` and throws `InvalidActorPathException` otherwise (`nexus-core/src/Actor/ActorPath.php:20, 72-74`). `PersistenceId::toString()` joins with `|` (`nexus-persistence/src/PersistenceId.php:80-83`) and only bars `|` in the *entityType* (`:56-58`).
- **Class:** Ergonomics / cross-package inconsistency.
- **Severity:** Medium — forces a dual naming scheme per entity. The example encodes it explicitly: actor name `process-{tenant}.{orderId}` (dot) vs `PersistenceId 'FulfillmentProcess|{tenant}|{orderId}'` (pipe), with a docblock spelling out the split (`Fulfillment/Application/ProcessRefFactory.php:23-24, 45`; `Orders/Application/OrderActor.php:48` vs `OrderRefFactory.php:47`).
- **Fix:** align the grammars — allow `|` in path segments, or ship `PersistenceId::toActorName()` / a canonical `EntityId` VO both layers accept.

### W13 — `ask()` docs drift + raw `FiberError` outside a handler — MEDIUM
- **Verify:** actual signature is `ask(object $message, Duration $timeout): Future` returning `Future<R>` awaited via `->await()` (`nexus-core/src/Actor/ActorRef.php:66-67`; `LocalActorRef.php:88-111`). CLAUDE.md documents a **different** signature: `ask(callable(ActorRef<R>): T, Duration): R`. `await()` suspends the fiber unconditionally (`nexus-runtime-fiber/src/FiberFutureSlot.php:90-95`) — calling it outside a fiber throws a raw `FiberError`, **not** a `NexusException`, bypassing the exception hierarchy & supervision. (Minor adjacent: the ask-timeout timer is never cancelled on success.)
- **Class:** Docs drift + API trap.
- **Severity:** Medium — the example uses the real `->ask(...)->await()` form (`PlaceOrderHandler.php:47-48`), so users learn by counterexample to the docs.
- **Fix:** correct CLAUDE.md/reference docs; wrap out-of-fiber `await()` in a `NexusException` ("ask() must be awaited inside an actor handler / coroutine"); cancel the timeout timer on resolve.

### W14 — `#[ReplyType]` accepts only a single class → union replies inexpressible — MEDIUM
- **Verify:** `ReplyType` takes one `class-string` and is `TARGET_CLASS` (`nexus-core/src/Actor/Attribute/ReplyType.php`). Real command actors reply `Accepted | Rejected`. The example cannot express it and eats `@psalm-suppress NoValue` at the ask site with a comment: "reply type is OrderAccepted|OrderRejected (union), not expressible via #[ReplyType] which supports only a single class" (`Orders/Infrastructure/Http/PlaceOrderHandler.php:29-31`).
- **Class:** Ergonomics / type-model gap.
- **Severity:** Medium — near-universal (every accept/reject command).
- **Fix:** accept variadic `class-string ...$replyClasses` (plugin infers the union), or honor a declared union type on the message.

### W15 — One-message-type-per-actor: union actors collapse to `ActorRef<object>` + suppressions — HIGH
- **Verify:** `Behavior<T>`/`ActorRef<T>` bind a single message type `T`. Any actor handling unrelated messages degrades to `object`. Pervasive in the example: `ContextBusActor` (`Platform/Bus/ContextBusActor.php:21-22, 33` — `@psalm-suppress InvalidArgument`, `MixedArgumentTypeCoercion`, `UntypedActorRefInjection`), `FulfillmentManagerActor` (`Fulfillment/Application/FulfillmentManagerActor.php:30-33` — routes `OrderPlaced|StockReserved|StockReservationRejected`), and every `*RefFactory` returning `ActorRef<object>` (`OrderRefFactory.php:28, 44`; `ProcessRefFactory.php:28, 41`). Even the bus wiring needs a hand `/** @var ActorRef<Publish> */` cast (`App.php:95-98`).
- **Class:** Ergonomics / modeling gap (no sealed-protocol or `messageAdapter` seam).
- **Severity:** High — the framework's headline type-safety evaporates precisely where actors get interesting, and the very Psalm rule the framework ships (`UntypedActorRefInjection`) has to be suppressed by hand.
- **Fix:** support a marker *protocol interface* as `T` (all messages `implements OrderProtocol`), document it as the idiom, and add an Akka-style `ctx->messageAdapter()` seam so an actor can absorb foreign messages under its own protocol without widening to `object`.

### W16 — No entity-sharding / `EntityRef` primitive → every app hand-writes a `RefFactory` — HIGH
- **Verify:** `ActorSystem::spawn()` prunes same-named dead children (enabling respawn), but there is no first-class sharded-entity registry with passivation. The example writes **three** near-identical factories — cache map + `isAlive()` + spawn + dual-name (`OrderRefFactory.php:26-69`, `ProcessRefFactory.php:26-68`, `InventoryRefFactory`), each re-implementing passivation via `setReceiveTimeout` → `Behavior::stopped()` inside `AggregateBehavior` (`Platform/Actor/AggregateBehavior.php:141-157, 232-236`).
- **Class:** Missing seam.
- **Severity:** High — this is the single biggest boilerplate source in the example and the exact role Akka `ClusterSharding`/`EntityRef` fills.
- **Fix / graduate:** ship a `ShardedEntity`/`EntityRefFactory` primitive (keyed spawn-on-demand, passivation window, respawn-with-replay) parameterized by an entity behavior factory. The example's `RefFactory` + `AggregateBehavior` pairing is the reference design.

### W17 — `Directive::Escalate` is not wired — it silently degrades to `Stop` — HIGH
- **Verify:** the supervision handler logs a `warning` and calls `initiateStop()` for `Escalate` (`nexus-core/src/Actor/ActorCell.php:992-1011`). The enum case is public and selectable, so a user building an Akka-style escalate-to-parent tree gets a stopped child and a log line instead.
- **Class:** API trap (semantic lie — a selectable directive that doesn't do what it says).
- **Severity:** High — supervision is a headline feature; a core directive being a no-op-plus-stop undermines trust in the whole tree.
- **Fix:** either implement escalation (propagate `ChildFailed` up the parent chain) or remove `Escalate` from the public enum until it exists.

### W18 — `exponentialBackoff` retry cap is lifetime-global (no sliding window) — HIGH
- **Verify:** `SupervisionStrategy::exponentialBackoff` sets `window: Duration::zero()` and does not expose a window param (`nexus-core/src/Supervision/SupervisionStrategy.php:74-90`); the cell only prunes the restart log when `window > 0` (`ActorCell.php:1024-1032`). So `oneForOne`/`allForOne` count restarts within 60 s, but `exponentialBackoff` counts **every restart for the actor's whole life** and permanently stops after `maxRetries` (default 3).
- **Class:** API trap (surprising semantics for a durable-retry strategy).
- **Severity:** High — a long-lived actor that hiccups 3 times over days is killed forever; the strategy whose purpose is resilient retry-with-delay is the least resilient.
- **Fix:** expose `window` on `exponentialBackoff` and default it to a rolling window (or reset the counter after a healthy interval).

### W19 — `tell()` silently discards `EnqueueResult`; bounded mailboxes drop with no signal — HIGH
- **Verify:** in the worktree tree, `LocalActorRef::tell()` does `$_ = $this->mailbox->enqueue(...)` and silently drops on a closed mailbox (`nexus-core/src/Actor/LocalActorRef.php:~51-53`, "fire-and-forget: silently drop"). `enqueue()` can return `Dropped`/`Backpressured` (per `MailboxConfig::bounded(capacity, OverflowStrategy)`), but `tell()` — the primary send API — throws the result away. `OverflowStrategy::Backpressure` provides no backpressure to a `tell()` caller. (On other branches a `BackpressureCapable::offer()` seam exists, but it is not on `ActorRef` and absent from this tree.)
- **Class:** API trap (fail-silent).
- **Severity:** High — the default "just `tell`" path is lossy the moment a user adds a bounded mailbox for safety, with zero feedback.
- **Fix:** surface delivery feedback on the primary API — e.g. `trySend(): EnqueueResult` on `ActorRef`, and/or route dropped sends to dead letters (which they currently are not from the ref).

### W20 — `MailboxConfig::bounded()` default strategy throws into the *sender* — MEDIUM
- **Verify:** `bounded(capacity)` defaults `OverflowStrategy::ThrowException` (`nexus-core/src/Mailbox/MailboxConfig.php:42`). Combined with W19, a full bounded mailbox raises `MailboxOverflowException` on the enqueue path, propagating out of the *sender's* `tell()` and crashing the **sender**, not the overloaded target.
- **Class:** API trap (worst-for-safety default + wrong blast radius).
- **Severity:** Medium — surprising and hard to diagnose; couples an overloaded consumer to producer failure.
- **Fix:** default bounded mailboxes to `DropNewest` (route drop → dead letters), reserve `ThrowException` for explicit opt-in, and never surface it in the producer's stack.

### W21 — Two parallel sentinel vocabularies; mixing them looks like a handler crash — MEDIUM
- **Verify:** stateless handlers return `Behavior::*`; stateful return `BehaviorWithState::*`. A `withState` handler that returns `Behavior::same()` reaches `applyStatefulBehavior()`, which calls `$result->isStopped()` → `Error`, caught and funneled into supervision as a *failure* (`nexus-core/src/Actor/ActorCell.php:777-801, 841-865`). No shared type prevents it.
- **Class:** API trap (type system doesn't catch a common mistake; symptom masquerades as a crash).
- **Severity:** Medium — trips new users; the failure is misattributed to the handler body.
- **Fix:** have `applyStatefulBehavior` detect a plain `Behavior` and throw a precise `LogicException` ("stateful handler must return BehaviorWithState"), or unify the sentinels behind a shared marker.

### W22 — `Behavior::empty()` / wrong-sentinel returns are silent message black-holes — MEDIUM
- **Verify:** if `currentBehavior` is not a `Receive`/`WithState` behavior, every user message is dead-lettered (`ActorCell.php:748-752`). `Behavior::empty()` is public, `@psalm-api`, and named like a benign placeholder (`nexus-core/src/Actor/EmptyBehavior.php`), so returning it as a "do nothing" default silently black-holes all future messages. Separately, `match(true){ ... default => Behavior::same() }` (a natural default) *swallows* unrecognized messages, whereas `unhandled()` would dead-letter them (`ActorCell.php:806-822`) — the two differ only in drop semantics and are type-identical.
- **Class:** API trap (fail-silent sentinels).
- **Severity:** Medium.
- **Fix:** rename/guard `empty()` (or make it dead-letter-with-warning), and document `same()`-as-default as a swallow; consider a Psalm rule flagging `default => Behavior::same()` in a `match(true)` router.

### W23 — `reply()` fallback targets the actor's own mailbox (shipped "placeholder") — MEDIUM
- **Verify:** for a non-root sender path with no resolved `senderRef`, `reply()` builds a `LocalActorRef` pointing at `$this->mailbox` — the current actor's *own* mailbox, with a comment "placeholder — in full system would resolve actual mailbox" (`nexus-core/src/Actor/ActorCell.php:534-540`). In cluster/remote-sender scenarios `reply()` can loop back to the replying actor.
- **Class:** Correctness landmine hidden behind a placeholder comment in shipped code.
- **Severity:** Medium now (Low-risk locally), High once remote refs land.
- **Fix:** throw `NoSenderException` in the unresolved-sender case rather than fabricating a self-ref; resolve real refs before cluster ships.

### W24 — Default supervision decider restarts on *every* `Throwable` — MEDIUM
- **Verify:** `defaultDecider` returns `Directive::Restart` unconditionally (`nexus-core/src/Supervision/SupervisionStrategy.php:103-106`). A deterministic bug (`TypeError`, null deref) drives up to `maxRetries` identical crashes, then a silent stop.
- **Class:** Ergonomics / trap (neither fail-fast nor escalate out of the box).
- **Severity:** Medium.
- **Fix:** default to restart on domain exceptions but `Stop` (or escalate) on `Error`/`TypeError`; or ship a documented "resume transient, stop programming errors" default.

### W25 — `scheduleRepeatedly` floods an unbounded mailbox when interval < processing time — MEDIUM
- **Verify:** each tick blindly `tell()`s self (`ActorCell.php:451-463`); the default mailbox is unbounded (`Props.php:73`, `MailboxConfig.php:53-56`). Ticks accumulate faster than they drain; drops (if bounded) are silent per W19.
- **Class:** Ergonomics / latent trap.
- **Severity:** Medium.
- **Fix:** offer a "fixed-delay" (re-arm after processing) scheduling mode alongside fixed-rate; document the interval < handler-time hazard.

### W26 — `AggregateBehavior` reflection routing exists only because there is no declarative command-router — MEDIUM
- **Verify:** the example builds a 130-line reflection engine that discovers command handlers by method-signature convention (public, `: void`, single concrete-class param) with a static cache and ambiguity guard (`Platform/Actor/AggregateBehavior.php:256-318`), plus a documented caveat that any public void mutator taking a class param is silently claimed (`:239-247`).
- **Class:** Missing seam.
- **Severity:** Medium — a class-string→handler map is the single most common actor idiom; the framework offers only `match(true)` on `instanceof` or hand-rolled reflection.
- **Fix / graduate:** ship a declarative `Behavior::router([Msg::class => fn]) ` (or the signature-discovery helper) as a framework primitive; the example's `AggregateEntityBehavior` route-map is the template (ledger Amendment B/C).

### W27 — `pipeToSelf` is referenced but does not exist — LOW
- **Verify:** CLAUDE.md/mental-model reference `pipeToSelf`, but `ActorContext` exposes no such method (`nexus-core/src/Actor/ActorContext.php`); the nearest is `spawnTask()`. Users must hand-roll Future→mailbox bridging.
- **Class:** Docs drift / missing convenience.
- **Severity:** Low.
- **Fix:** add `ctx->pipeToSelf(Future, fn)` or stop referencing it.

### W28 — `deptrac` ignores bare `use` statements → context-fence probes need typed references — LOW
- **Verify:** ledger P3 T8/T6 records that deptrac fences don't catch cross-context imports via bare `use` (only typed references), and that arrow-fn `: void` returns swallow the callee's null — both discovered while building the bounded-context fences. Not a DSL defect per se, but a governance-tooling sharp edge a fresh adopter of the "bounded contexts as deptrac layers" pattern will hit.
- **Class:** Tooling / docs.
- **Severity:** Low.
- **Fix:** document the typed-reference requirement for context fences; provide a per-context collector recipe.

---

## API-Design Themes (root causes)

**Theme A — "Fail-silent" defaults contradict the stated "fail-fast" philosophy.** The single most damaging pattern. `Effect::none()->thenRun` no-ops (W1), `tell()` discards `EnqueueResult` (W19), bounded overflow crashes the sender (W20), `same()`/`empty()` swallow messages (W22), `Directive::Escalate` degrades to `Stop` (W17), closure routes bypass auth (W9), dead letters vanish into an `@internal` array (W11). Nexus's docs say "fail-fast, let it crash, dead letters capture undeliverable" — but the DSL's *defaults* prefer swallowing over surfacing. Every one of these should either fail loudly at construction/registration time or route to an observable dead-letter/diagnostic channel.

**Theme B — The type model assumes one message type per actor; reality is unions.** `Behavior<T>`/`ActorRef<T>` bind a single `T`, `#[ReplyType]` a single class, `withState` a single inferred `S`. Real actors handle protocols (unions), reply with `Accepted|Rejected`, and hold collection state — so the framework's headline generic safety collapses to `object`/`mixed` plus a suppression tax exactly where actors get interesting (W7, W14, W15, W12-adjacent). The fix is a *protocol-interface* idiom + `messageAdapter` seam + closure-boundary state binding via a marker interface.

**Theme C — The framework ships primitives but not the last-mile compositions every real app needs.** `EventStore` yes, but no pooled/wire-named store (W3, W5); `spawn` yes, but no `EntityRef`/sharding (W16); PSR-14 yes, but no `EventStream` pub-sub (W10); `DeadLetterRef` yes, but no query surface (W11); `instanceof` routing yes, but no declarative router (W26). The example had to build all five. The healthy precedent is `withEventPublisher`/`withSignalHandler` graduating mid-project — the same path should absorb `PooledDoctrineEventStore`, `ContextBus`, `EntityRefFactory`, and the route-map helper.

**Theme D — Composition and evolution surfaces are leaky and positional.** Behavior wrappers don't compose their signal handlers (W2); the engine is a 12-arg positional list that has already broken once (W6); two parallel sentinel vocabularies mix badly (W21); identifier grammars disagree across packages (W12); a shipped "placeholder" reply-ref is a correctness landmine (W23). Composition should compose; evolution surfaces should be named/config-object, not positional.

**Theme E — Docs/reality drift erodes the type-safety promise.** `ask()`'s documented callable signature doesn't exist (W13), `pipeToSelf` doesn't exist (W27), and the `Effect` docblock actively teaches the W1 no-op (W1). For a framework selling compile-time safety, the reference material must match the surface exactly.

---

## Prioritized Roadmap to a 1.0 DSL

**P0 — correctness/security traps (do before any 1.0 claim):**
1. W1 — make `none()->thenRun/thenReply` fail loudly or execute; delete the misleading docblock.
2. W3 + W4 — add `withTypeNaming(TypeRegistry)` to Doctrine/Dbal stores and a serializing in-memory store so the wire-name trap surfaces in tests.
3. W9 — route-level `requiresRole` + fail-closed on closure routes.
4. W17 — implement or remove `Directive::Escalate`.
5. W19 + W20 — give `tell()`/`ActorRef` delivery feedback; change the bounded default to drop-to-dead-letters.

**P1 — graduate the example's proven seams:**
6. W16 — `EntityRefFactory`/sharding with passivation.
7. W10 — `EventStream`/`Topic` pub-sub primitive.
8. W5 — pooled Doctrine stores.
9. W11 — dead-letter PSR-14 event + bounded queryable queue.
10. W2 — fix wrapper signal-handler composition in `ActorCell` (keep `withSignalHandler` as sugar, not a crutch).
11. W26 — declarative command-router primitive.

**P2 — type-model & ergonomics:**
12. W15 + W14 — protocol-interface idiom + `messageAdapter` + variadic `#[ReplyType]`.
13. W7 — `AggregateRoot`/state marker to kill the erasure suppressions.
14. W6 — de-positionalize `PersistenceEngine::create()` (config object or `@internal`).
15. W12 — align `ActorPath`/`PersistenceId` grammars (`EntityId` VO).
16. W18/W24/W21/W22/W25 — supervision window + saner defaults; sentinel-mismatch diagnostics.

**P3 — docs & tooling:**
17. W13/W27 — fix `ask()` docs, wrap out-of-fiber `await`, add/retire `pipeToSelf`.
18. W23 — replace the placeholder self-reply with `NoSenderException`.
19. W28 — document deptrac context-fence idioms.

---

*Evidence base: friction ledger `.superpowers/sdd/progress.md` (14 logged items, all verified) plus fresh skims of `nexus-core` Behavior/ActorCell/Supervision/Mailbox surfaces (14 additional latent traps). All 28 verified in framework source against the tree the example builds against.*
