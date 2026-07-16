# Audit Verification & Remediation Plan

**Verifies:** `docs/audits/2026-07-16-nexus-independent-audit.md`
**Verification date:** 2026-07-16
**Verified against:** branch `chore/psalm-zero-suppressions` (current working tree, PR #63), cross-checked against the audit's BASE `8970faa2`
**Method:** six independent read-only review agents, one per finding family, each instructed to be adversarial and refute where possible; every verdict backed by a fresh file:line trace of actual control flow, not the audit's citations taken on faith.

---

## 1. Opinion on the audit

**It is a high-quality, honest, technically-grounded audit, and it is substantially correct.** I tried to break it. I could not, in any material way.

Of 68 findings, **62 verified CONFIRMED** against current code with independent evidence, **2 are already fixed on this branch**, and **4 are PARTIAL** (true in substance but with an overreach or a narrowing the audit didn't make). **Zero findings were outright FALSE.** That is an unusually high hit rate; most audits of this breadth carry a tail of misreadings. This one earns its conclusions.

What makes it credible beyond the hit rate:

- **It separates immutable-source findings (BASE) from dirty-worktree verification (my in-progress branch).** That discipline is why its one "wrong" headline — "21 Psalm errors" (QA-005) — is explicitly caveated as *not* attributable to clean main. It was literally observing my Psalm cleanup mid-flight; that work is now complete (0 errors).
- **Its severity calibration is defensible.** It declined to claim an unconditional Critical RCE despite the native-deserialization finding, correctly gating it on "attacker can influence persisted rows." It labels conditional findings with their preconditions rather than inflating severity.
- **It preserves positive findings separately** so "incomplete" is not conflated with "badly engineered." The strengths list (dependency inversion, immutable behavior model, StepRuntime, transactional appends, HMAC handshake hygiene) all verified true.

The core thesis is correct: **Nexus is a strong pre-1.0 foundation whose public promise (README/website) currently outruns its implemented semantics.** The recurring failure mode is *complete-looking APIs with partial interpreters* — `Effect` chains that silently no-op, supervision strategies that don't branch, an "unbounded" mailbox that is a fixed 65 k channel returning `Accepted` on a dropped push, WebSocket upgrades that skip the auth pipeline. None of these are subtle once you trace them; they are simply gaps between the docs and the code.

Where I'd push back — minor:
- **QA-003 / "broken performance suite"** is overstated as blanket. Only `ClusterPerformanceTest.php` is broken (it imports a `Monadial\Nexus\Cluster\ConsistentHashRing` that never existed — the class lives in `WorkerPool\`). `WorkerPoolPerformanceTest` and `thread_worker_script.php` import the real class and are fine.
- **DOC-002** calls `Effect::none()->thenReply()` and "side effects after `none()`" documentation *errors*. Two different things are tangled here: the *docs* are wrong about handler argument order and about a nonexistent `RecoveryCompleted` signal (both real bugs), but `thenReply` chaining off `none()` is a legitimate API shape — the bug is that the **interpreter drops those hooks** (DDD-001), not that the docs show the chain.
- **ARCH-003** (Doctrine packages requiring HTTP) is framed as a defect; it's by-design — those packages ship real `src/Http/` scope-middleware that legitimately needs PSR-HTTP.
- **REL-002** conflates two claims: "death-watch `Terminated` is never emitted" is fully true, but "names can't be reused" is false at the `ActorSystem` root (spawn prunes dead children exactly as `CLAUDE.md` documents) — true only for nested `ActorCell` children.
- **SEC-014** — the splitsh binary *is* version-pinned (`v1.0.1`); the real gap is no checksum verification, and the mutable-tag instance is `actions/checkout@v4`.

These are refinements, not rebuttals. My bottom line: **treat the audit as accurate and act on it.** Its "experimental, trusted, single-process or carefully-constrained single-host" positioning is the honest current product position.

---

## 2. What's true / what's not — full verdict table

Legend: **CONFIRMED** = claim true against current code · **FIXED** = was true at BASE, resolved on this branch · **PARTIAL** = true in substance, with a stated narrowing/overreach · **FALSE** = claim wrong.

### Persistence, event sourcing, DDD

| ID | Audit claim (short) | Verdict | Verified evidence |
|---|---|---|---|
| DDD-001 | `none()->thenReply/thenRun` hooks silently dropped | CONFIRMED | `PersistenceEngine.php:177-178` maps None/Unhandled→`sameState()`; only `handlePersist` (269-271) iterates `sideEffects`. Same in `DurableStateEngine.php:115-116`. The reply path also ignores `sideEffects`. |
| DDD-002 / DSL-005 / REL-010 | Recovery folds events only; no `thenRun` replay; no `RecoveryCompleted`; synchronous | CONFIRMED | `PersistenceEngine.php:92-131` recovery only folds `eventHandler`; `RecoveryCompleted` exists nowhere in `packages/`; runs inside `Behavior::setup`. |
| DDD-003 | `ActorSystem::writerId()` not passed to persistence; behaviors mint own ULIDs; ReplayFilter defaults off | CONFIRMED | `writerId()` exists but has zero call sites; `EventSourcedBehavior` defaults `new Ulid()`; `PersistenceEngine::create` defaults `ReplayFilter::off()`. **Bonus bug:** `withReplayFilter` docblock claims `fail()` is the default — direct doc-vs-impl contradiction. |
| DDD-004 | Wallet commands: no command ID/dedup/cached outcome; persist precedes reply | CONFIRMED | `WalletActor.php` Deposit/Withdraw have no idempotency; events persist before the `thenRun` reply → timed-out retry double-applies. |
| DDD-005 | `eventType = $event::class`; no schema version; no upcasters | CONFIRMED | `PersistenceEngine.php:222`; `EventEnvelope` has no version field; zero `upcast`/`schemaVersion` in persistence src. |
| DSL-001 | Persistent `Effect::unhandled()` → unchanged state despite promising dead letters | CONFIRMED | Docblock promises dead-letter routing; engine maps Unhandled→`sameState()`, no routing. |
| DSL-004 | Docs show `(ctx, cmd, state)`; engine calls `(state, ctx, cmd)` | CONFIRMED | Engine invokes `$commandHandler($state, $ctx, $msg)` (`PersistenceEngine.php:162`). Wrong order in docs **and** in the shipped class docblocks; the type declaration and WalletActor are correct. |
| OPS-002 | `keepSnapshots` never applied; events deleted through newest snapshot | CONFIRMED | `keepSnapshots` read only in RetentionPolicy's constructor; engine checks only `deleteEventsToSnapshot`; `SnapshotStore::delete` never called. |
| SCALE-003 | Recovery synchronous, materializes full history; ReplayFilter buffers all | CONFIRMED | `ReplayFilter.php:51-98` buffers every event into `$allEvents` even in Off mode. |
| DOC-004 | `RepairByDiscardOld` docs say permanent drop; impl only filters in-memory list | CONFIRMED | `array_filter` on `$allEvents`; never mutates the store. |
| — (positive) | Transactional DB appends; sequence-uniqueness conflict detection | CONFIRMED | `DbalEventStore.php` wraps inserts in `transactional()`; catches `UniqueConstraintViolationException`→`ConcurrentModificationException`. |

### Core runtime, reliability, lifecycle

| ID | Audit claim (short) | Verdict | Verified evidence |
|---|---|---|---|
| REL-001 | Swoole "unbounded" is a 65 536 channel; failed `push` returned as `Accepted` | CONFIRMED | `SwooleMailbox.php:37,74,89-97` — unbounded path never checks length; push result ignored; always returns `Accepted`. |
| REL-002 | Watchers recorded but `Terminated` never sent; names can't be reused | PARTIAL | No `Terminated`/`ChildFailed` emitted anywhere in core (death-watch is a no-op sink) — TRUE. But `ActorSystem::spawn` prunes dead roots and reuses names (per `CLAUDE.md`) — name-reuse claim FALSE at root, true only for nested `ActorCell` children. |
| REL-003 | all-for-one never branches; Escalate→stop; backoff ignored; restart immediate | CONFIRMED | No `StrategyType` branch in ActorCell; `Escalate→escalateAsStop()`; backoff params never read; `restart()` immediate. |
| REL-004 | Suspended actors drop every envelope incl. Resume | CONFIRMED | `processMessage` early-returns on non-Running before system-message dispatch; **the gap is pinned by a passing test** (`ActorCellAdvancedTest` asserts Resume is dropped). |
| REL-005 | Repeating Swoole timer cancellable holds `after` id, not `tick` id | CONFIRMED | `SwooleRuntime.php:231-246` returns cancellable for the spent `after` id; live `tick` id only in `$this->timerIds`. Cancel-after-first-fire fails. |
| REL-006 | Parent PostStop fires before descendants drain; shutdown not leaf-first | CONFIRMED | `initiateStop` enqueues child PoisonPills then immediately fires parent PostStop; `ActorSystem::shutdown` waits only on roots. |
| OPS-001 | DeadLetterRef retains forever; some closed-mailbox paths bypass dead letters | CONFIRMED | `captured[] = $message` unbounded; `LocalActorRef` closed-mailbox paths never forward to `$deadLetters`. |
| DSL-008 | Public factory/reply contracts rely on `assert()` | CONFIRMED | `Props.php:92,143,163` — with `zend.assertions=-1` these vanish. |
| DSL-003 / DSL-006 | Repeated `compile()` respawns names / drops late routes | **FIXED** | `HttpApp::compile()` now has a `$compiled` one-shot guard; route-duplication bug is gone. Residual respawn-throw survives only for still-alive actors across recompile. |
| — (positive) | StepRuntime determinism; bounded overflow strategies work | CONFIRMED | StepRuntime yields/sleeps are no-ops, one-fiber `step()`, explicit `advanceTime`; FiberMailbox dispatches all four `OverflowStrategy` values correctly. |

### Delivery, scaling, cluster

| ID | Audit claim (short) | Verdict | Verified evidence |
|---|---|---|---|
| REL-007 | Messenger acks on enqueue, before handler/persist | CONFIRMED | `ReceiverActor.php:404` acks immediately after `offer()` returns `Accepted`. "At-least-once" is accurate for *delivery-to-mailbox* only; a crash before drain loses the message. |
| REL-008 | Responder pending-asks unbounded (30 s, no cap) | CONFIRMED | `$pendingAsks` is a plain array, capped only by 30 s expiry. The `PendingAskRegistry` cap (10 000) is the *producer* side — different class. |
| REL-009 | TCP silent drops while `frames.sent` increments | CONFIRMED | Three loss paths (no-route fallthrough, PeerConnection drop-newest >100, SwoolePeerLink short-write teardown) all fire after the counter increments. **The single most concrete correctness bug.** |
| SCALE-001 | Thread transport ignores missing workers; no metrics; 10 ms poll; no unregister | CONFIRMED | `ThreadQueueTransport.php:51-58` silent on missing worker; `ThreadMapDirectory` has no remove; directory grows monotonically. |
| SCALE-002 | Thread queue uses `php_serialize` despite "direct object" docs | CONFIRMED | `Queue::push` serializes on the C side; the class docblock admits it; `CLAUDE.md` overstates "no serializer involved." |
| SCALE-004 | Cluster O(N²), only 16-node result cited | CONFIRMED | README is candid: "Validated: 16-node soak," ~50 extrapolated. |
| OPS-004 | `minimumMembers` suppresses downing, not quorum/fencing | CONFIRMED | `MembershipService.php:346,383-392` holds the Suspect instead of Down; no write fencing. |
| OPS-005 / QA-003 | Perf suite fatally references removed class | PARTIAL | Only `ClusterPerformanceTest.php:8` (imports nonexistent `Cluster\ConsistentHashRing`). `WorkerPoolPerformanceTest`/`thread_worker_script` use the real `WorkerPool\ConsistentHashRing` and are fine. |
| SEC-012 | Producer stamp selects target, no origin authz | CONFIRMED | `StampMessageRouter.php:31-37` returns `registry[$stamp->path]` with no caller check. |
| SEC-008 | Admitted member can steer control events; HMAC = group secret not identity | CONFIRMED | Control frames forwarded from any admitted peer; `authSecret` is cluster-wide per README. |

### Security (HTTP / WS / auth / serialization / supply chain)

| ID | Audit claim (short) | Verdict | Verified evidence |
|---|---|---|---|
| SEC-001 | WS Open bypasses HTTP/auth middleware; no principal resolution | CONFIRMED | `SwooleServerEventBinder.php:77-96` dispatches Open directly; WS handler resolvers have no principal/auth. |
| SEC-002 | Arbitrary channel keys spawn actors; lazy prune; `remove()` unused; actors survive close; unbounded mailboxes | CONFIRMED | Every sub-claim holds; base `WebSocketChannelActor` never returns `stopped()`; spawn uses default unbounded mailbox. |
| SEC-003 | `#[RequiresAuth]`/`#[RequiresScope]` fail-open if route authz middleware omitted | CONFIRMED | Enforcement only in `AuthorizationMiddleware`, per-route. PR #48 fixed only the *global-misregistration* case, not per-route omission. |
| SEC-004 | `PhpNativeSerializer` defaults allow-all; guards run after `unserialize()`; DBAL store uses it by default | CONFIRMED | `null` allowedClasses → allow-all; `__PHP_Incomplete_Class`/type guards fire after gadget `__wakeup`/`__destruct`. |
| SEC-005 | DB connection release requeues without rollback/reset | CONFIRMED | `ConnectionPool.php:115` pushes raw connection back on happy path; poisons only on thrown exception. |
| SEC-006 | JWT ignores configured issuer/audience | CONFIRMED | Only `SignedWith` + `StrictValidAt` asserted; zero `IssuedBy`/`PermittedFor` anywhere. |
| SEC-007 | Cluster TLS + auth default null | CONFIRMED | `ClusterTopology.php:91,138` — both opt-in; docblock admits "any reachable peer joins." |
| SEC-009 | `rawContent()` buffers body before PSR limit; limit skips GET/unknown length | CONFIRMED | Swoole pre-buffers; `BodySizeLimitMiddleware` excludes GET and returns null on non-numeric length. |
| SEC-010 | No Origin validation on WS; cookie token read verbatim → CSWSH | CONFIRMED | WS Open never reads Origin; `CookieTokenExtractor` returns cookie as-is. |
| SEC-011 | Health handler leaks exception class/message; docs show public `/health` | CONFIRMED | `HealthCheckHandler.php:53` returns `$e::class`+message; wallet registers `/health` unauthenticated. |
| SEC-013 | Wallet demo `/admin/wallets` unauthenticated; demo creds published | CONFIRMED | Only `#[Transactional]`, no auth; `compose.yaml` bakes `POSTGRES_PASSWORD: wallet` + `alice-token`. |
| SEC-014 | Lockfile gitignored; no dep audits; split runs unchecked binary with PAT; mutable action tags | PARTIAL | Lockfile ignored ✓, no audits ✓ (my branch added deps-checker, not audits), PAT ✓. **Refinement:** splitsh IS version-pinned (`v1.0.1`) but not checksum-verified; the mutable tag is `actions/checkout@v4`. |
| SEC-015 / QA-004 | Root unit suite omits http-auth/http-toolkit dirs | CONFIRMED | Neither package appears in any `phpunit.xml` testsuite; 17 test files never run in CI. |
| — (strengths) | Opaque prod errors; HMAC SHA-256+nonce+`hash_equals`; parameterized queries; explicit allowlists | CONFIRMED | All accurate. |

### DSL / architecture / packaging

| ID | Audit claim (short) | Verdict | Verified evidence |
|---|---|---|---|
| DSL-002 | `'#orders'` handler shorthand unimplemented; real path is `#[FromActor]` | CONFIRMED | `HandlerResolver` only branches Closure/`Class::method`/invokable; `'#orders'` falls through and fails. |
| DSL-007 | `NexusApp` discards spawned refs; no named lookup | CONFIRMED | `start()` discards the ref; `onStart` gets only `ActorSystem`. |
| DSL-009 | `#[FromActor]` doesn't validate param accepts `ActorRef` | CONFIRMED | Reads type name, never asserts `ActorRef`. |
| DSL-010 | `ActorRef<T>` is Psalm-only; runtime accepts `object`; meta-package omits plugin; quick-start erases to `object` | CONFIRMED | `packages/nexus/composer.json` has no `nexus-actors/psalm`; quick-start uses `Behavior<object>`. |
| ARCH-001 | Split manifests retain `dev-main`; tag split doesn't rewrite | CONFIRMED | `split.yml` does zero manifest rewriting; all manifests use `dev-main`. |
| ARCH-002 | Deptrac permits both Core→Runtime and Runtime→Core | CONFIRMED | `deptrac.yaml` ruleset allows the bidirectional edge. |
| ARCH-003 | Doctrine packages require HTTP in core manifests | PARTIAL / by-design | True that they require `nexus-actors/http`+PSR-HTTP, but backed by real `src/Http/` scope-middleware. Not a defect. |
| QA-006 | Deptrac 0 violations but ~1023 uncovered tokens | CONFIRMED | Current run: 0 violations, **1034** uncovered. Green deptrac masks the Core↔Runtime bidirectional allowance. |

### Documentation & quality gates

| ID | Audit claim (short) | Verdict | Verified evidence |
|---|---|---|---|
| DOC-001 | Wallet README: removed files, `amount` vs `amountCents`, per-worker InMemory store | CONFIRMED | `RequestActor`/`HandleRequest` gone; `AmountRequest` requires `amountCents`; `WalletApp.php:132` per-worker `InMemoryEventStore`. |
| DOC-002 | Event-sourcing docs: wrong order, `RecoveryCompleted`, writer-ID claim | CONFIRMED (2 sub-items overreach) | Wrong handler order ✓, nonexistent `RecoveryCompleted` ✓, writer-ID gap ✓. The `none()->thenReply` / side-effect-after-none items are valid API shapes — the bug is the interpreter dropping them (DDD-001), not the docs. |
| DOC-003 | Saga guide: skipped from verify, wrong order, uncaptured static-closure refs, false replay guarantee | CONFIRMED | `static function` references `$paymentActor`/`$inventoryActor`/`$eventStore` with no `use()`; crash-replay-reissues-command is false; snippet is `verify:skip`. |
| DOC-005 | Swoole version disagreement 5.0 / 6.0 / 6.2.1 | CONFIRMED | Install doc 5.0+, README 6.0+, manifests `>=6.2.1`. |
| DOC-006 | Verifier double-prepends `<?php` | CONFIRMED | `verify-doc-snippets:113-116` unconditionally prepends; 85 of 580 fences already contain `<?php`. |
| DOC-007 | `docs-verify` not in any workflow; uncached Psalm per snippet | CONFIRMED | Only in Makefile; runs `--no-cache` once per snippet. |
| DOC-008 | 23 of 40 packages have local READMEs | CONFIRMED | Exactly 23/40; 17 missing (all observability-*, worker-pool*, http-auth/toolkit, doctrine-*, skeleton, meta). |
| DOC-009 | Release guide says 14 packages/`^1.0`; matrix has 37; manifests `dev-main` | CONFIRMED | 14 vs 37 vs `dev-main`. |
| DOC-010 | `make test` excludes Swoole/cluster/doctrine-swoole/http-swoole/perf | CONFIRMED | Target name implies "all"; help text mildly caveats. |
| QA-001 | Coverage guard `continue-on-error: true` | CONFIRMED | `ci.yml`. |
| QA-002 | Mutation `continue-on-error: true` | CONFIRMED | `ci.yml`. |
| QA-005 | 21 Psalm errors on dirty tree | **FIXED** | Was my in-progress work. Current branch: 0 errors, 99.90% inference, suppressions banned, `--find-unused-psalm-suppress` in CI. |

### Marketing claims (audit's correction table)

| Claim | Still present? | Audit correction fair? |
|---|---|---|
| "Production-grade actor system" | Yes (site description, ADR-0003, messenger docs) | Fair — aspirational given soft gates + untested packages + broken saga/perf snippets. |
| "Zero compromises on type safety" | Yes (README) | Fair with nuance — Psalm now genuinely passes at 0 with suppressions banned (stronger on this branch than at audit time); the caveat is the untested packages, not the type system. |
| "Single-writer guarantee" | Mechanism documented (WriterConflictException/ReplayFilter) | Fair — mechanism real but writer-ID not propagated from the system (DDD-003); "guarantee" overstates. |
| "At-least-once Messenger" | Yes (messenger docs) | Fair to scrutinize — it's at-least-once to the mailbox, not to processing. |
| "260K msgs/sec" | Yes (README) | Fair — unsourced number, no reproducible harness cited, perf suite partially broken. |

**Tally:** 68 findings → **62 CONFIRMED, 2 FIXED (QA-005, DSL-003/006), 4 PARTIAL (REL-002, ARCH-003, SEC-014, OPS-005/QA-003), 0 FALSE.** Plus 3 new observations the agents surfaced (see §4).

---

## 3. What this branch (PR #63) already changed

To keep the record honest about the moving worktree the audit flagged:

- **QA-005 FIXED** — Psalm is at 0 errors, 99.90% inference; `@psalm-suppress` is banned repo-wide with a CI grep gate + `--find-unused-psalm-suppress`.
- **DSL-003/DSL-006 FIXED** — `HttpApp::compile()` is now idempotent (one-shot `$compiled` guard).
- **New guardrail** — `bin/check-package-deps.php` (`make deps-check` + CI step) now enforces per-package dependency completeness; 20 manifests were corrected. This does **not** touch ARCH-001 (`dev-main` constraints remain) but it hardens the split-install story the audit worried about.
- **Untouched by this branch:** every other finding. The branch was a type-safety/packaging pass, not a semantics pass — so all reliability, security, persistence, and lifecycle findings stand exactly as the audit describes.

---

## 4. New observations beyond the audit

1. **`withReplayFilter` docblock lies** — claims `ReplayFilter::fail()` is the default while the engine defaults `off()`. A user reading the builder would believe they have split-brain protection they don't.
2. **No per-frame authorization seam in WebSockets** — `SwooleServerEventBinder` synthesizes a fake `ServerRequest('GET','/')` per inbound frame, so even if Open-time auth were added, channel actors have no request/principal to re-check on messages. Compounds SEC-001 + SEC-002 into "unauthenticated client can spawn unbounded actors *and* flood each without backpressure."
3. **Wrong handler-argument order is in the shipped class docblocks, not just the website** — so Psalm's `object`-typed middle parameter won't catch a user who copies the in-code example. Higher-impact than a docs-only error.

---

## 5. Remediation plan

Reprioritized from the audit's own roadmap by *impact per unit risk*. The ordering principle: **the cheapest, highest-leverage move is documentation honesty** — it removes adopter harm at near-zero engineering risk and buys time for the real fixes. Correctness and security fixes follow, deepest infrastructure last.

### Phase 0 — Stop misleading adopters (days, ~zero code risk) — DO FIRST

These are doc/claim edits and experimental labels. No runtime changes, no test churn.

- [ ] **P0.1** Correct the command-handler argument order everywhere: `event-sourcing.md`, `saga.md`, **and** the docblocks in `Effect.php` / `EventSourcedBehavior.php` (state-first). (DSL-004, DOC-002, DOC-003, obs #3)
- [ ] **P0.2** Remove the false guarantees from docs: `RecoveryCompleted` signal, saga "replay reissues the command," automatic system writer-ID, `RepairByDiscardOld` "permanent drop." Replace with current-behavior wording. (DDD-002, DOC-002, DOC-003, DOC-004)
- [ ] **P0.3** Fix the `withReplayFilter` docblock (`fail()`→`off()` default) — or change the default; pick one and make doc match code. (obs #1, DDD-003)
- [ ] **P0.4** Apply the audit's "Marketing and Claim Corrections" table verbatim to README/website/landing: qualify "production-grade," "single-writer guarantee," "at-least-once," "unbounded mailbox," "260K msgs/sec," "authenticated WebSockets." (all marketing rows)
- [ ] **P0.5** Add experimental banners to worker-pool, TCP cluster, persistence, WebSocket, and wallet-example pages. Fix DOC-001 (wallet README file map + `amountCents` payload + single-worker note), DOC-005 (single Swoole version, generated from manifests), DOC-009 (rewrite release guide from the real 37-entry matrix), DOC-010 (rename `make test` or make a truly-complete target).
- [ ] **P0.6** Fix the `'#orders'` HTTP shorthand docs → `#[FromActor]` (DSL-002).

### Phase 1 — Correctness & security blockers (the release gate)

Highest-value correctness/security fixes. Each needs a regression test that encodes the *correct* behavior.

- [ ] **P1.1 Effect interpreter matrix** — make every base effect × hook combination execute its `sideEffects` (None/Unhandled/Reply/Stop + durable state). Table-test all combinations. (DDD-001, DSL-001)
- [ ] **P1.2 Swoole mailbox admission** — expose real capacity, inspect every `push` result, return Dropped/Backpressured, fail asks immediately. (REL-001)
- [ ] **P1.3 WebSocket auth before upgrade** — authenticate/authorize in the pre-upgrade handshake, add WS route middleware + shared principal resolution, Origin allowlist, reject before 101. Bound channel mailboxes + cardinality (TTL/LRU), stop-on-last-close, wire `remove()`. (SEC-001, SEC-002, SEC-010, obs #2)
- [ ] **P1.4 Fail-closed HTTP authz** — auto-install authorization after routing, or fail compilation for annotated-but-unprotected routes. (SEC-003)
- [ ] **P1.5 Safe-by-default persistence codec** — default to registry/schema codec; make native `unserialize` an explicit trusted-data opt-in with nested allowlists. (SEC-004)
- [ ] **P1.6 JWT issuer/audience** — merge configured constraints; require+test iss/aud/sub/skew. (SEC-006)
- [ ] **P1.7 Pooled DB reset** — rollback-if-active + session reset (or evict) on every release; poison on cleanup failure. (SEC-005)
- [ ] **P1.8 Repeating-timer cancellation** — return a handle bound to the live `tick` id. (REL-005)
- [ ] **P1.9 TCP delivery outcomes** — return admitted/buffered/dropped; stop incrementing `frames.sent` on the three loss paths; document at-most-once. (REL-009)
- [ ] **P1.10 Fix `ClusterPerformanceTest`** — correct the `ConsistentHashRing` import (or delete the dead cluster-perf file); make a perf smoke run executable. (QA-003)

### Phase 2 — Lifecycle & delivery contracts

- [ ] **P2.1** Death watch: emit `Terminated`/`ChildFailed`, parent deregistration, nested name reuse. (REL-002)
- [ ] **P2.2** Supervision: implement or reject all-for-one, escalation, exponential backoff; fix suspend/resume (separate system/user queues so `Resume` isn't dropped — and delete the test that asserts the broken behavior). (REL-003, REL-004)
- [ ] **P2.3** Leaf-first shutdown: track descendants, await within one deadline. (REL-006)
- [ ] **P2.4** Bound dead letters, responder asks, thread-transport queues; one admission/result vocabulary across local/worker/Messenger/cluster; metrics that separate accepted/dropped/executed/persisted/acked. (OPS-001, REL-008, SCALE-001, REL-007)
- [ ] **P2.5** Rename Messenger ack semantics to ack-on-enqueue, or add process/durable-commit ack. (REL-007)

### Phase 3 — Durable DDD infrastructure (the "not yet a durable-aggregate platform" gap)

- [ ] Ownership lease/epoch + fencing tokens + expected-version writes (DDD-003, OPS-004).
- [ ] Command IDs, dedup windows, cached outcomes (DDD-004).
- [ ] Transactional outbox/inbox / persisted effect journal (DDD-002, REL-010).
- [ ] Event logical names, schema versions, upcasters, unknown-event policy (DDD-005).
- [ ] Projection offsets/replay/rebuild; streamed/paged recovery with budgets; correct snapshot retention (SCALE-003, OPS-002).

### Phase 4 — Scaling & operations evidence

- [ ] Bounded worker queues, liveness leases, unregister, resharding docs; payload-size + serializer benchmarks; multi-host TLS/packet-loss/partition/churn cluster tests; separate membership from entity ownership; root-cause Swoole cycling; repair + publish perf results. (SCALE-001/002/004, OPS-003/004, QA-003)

### Phase 5 — Releasable supply chain

- [ ] Make Psalm (**done**), coverage, mutation gates blocking (QA-001, QA-002); add auth/toolkit suites to CI (SEC-015, QA-004); wire + batch doc-snippet verification (DOC-006, DOC-007); validate every split package from a clean stable fixture; replace `dev-main` with release-compatible constraints (ARCH-001); pin action SHAs + checksum splitsh + composer/OSV audits + SBOM (SEC-014); fix deptrac Core↔Runtime + uncovered-token gating (ARCH-002, QA-006).

### Suggested first slice

If you want to start now, **Phase 0 is the obvious first move**: it is entirely documentation/claim edits, carries near-zero risk, immediately stops the docs from teaching broken patterns, and I can execute most of it directly. **P1.10** (fix the broken perf test) and **P1.8** (timer cancellation) are small, self-contained correctness wins that make good second steps. The security cluster (P1.3/P1.4/P1.5/P1.6) is the highest-severity work but each item is a real design change that deserves its own reviewed PR.
