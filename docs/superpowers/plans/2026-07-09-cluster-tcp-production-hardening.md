# nexus-cluster-tcp — Production Hardening Plan

**Status:** draft, ready to execute
**Branch:** `feat/cluster-tcp` (base `feat/msgpack-serializer`)
**Baseline commit:** `4ccc9f79` (C2 P1 peer authentication landed)
**Author context:** distilled from the 2026-07-09 review + `.superpowers/sdd/progress.md` ledger + memory files `cluster-tcp-gossip-echo`, `cluster-tcp-perf-tuning`, `cluster-tcp-recv-timeout-bug`.

## Goal

Take `nexus-cluster-tcp` from "documented, honestly-scoped v1 for trusted/authenticated ≤20-node meshes" to "production-ready clustering with great docs + homepage." Success = the qualifiers in the review are dropped one by one, each backed by a passing distributed-mesh soak at **default** settings.

## Standing rules (apply to every task)

- TDD: failing test first. Every behavioural guard is **neutralize-validated** (revert the fix, prove the test fails, restore).
- Gates green per commit: `phpcs` + `php-cs-fixer` (run BOTH — they disagree; cs-fixer last), `psalm --no-cache`, unit suite, GrumPHP. Never `git add .deptrac.cache`.
- Membership logic stays in the **pure `MembershipService` transition layer**; actor-confined mutable collaborators (detector, throttle, dedup) never leak.
- Re-validate the distributed mesh (`tests/Performance/distributed/run.sh`) after any protocol change; a change "works" only when the 16-node soak passes.
- Docs are part of "done": package page, clustering guide, CLAUDE.md topology surface, CHANGELOG per feature.
- Wire compatibility: new payload fields are nullable, appended, read by-key. Add a cross-decode test (old⇄new) for any wire change.
- Cost discipline: one focused session per phase; do not start a large phase near context limit. Update the ledger at each step.

---

## PHASE 0 — Merge readiness (≈1 day, do first)

Goal: earn a clean, honest v1 merge. No new features.

- **0.1 Unpaced mesh re-validation (owed).** In `tests/Performance/distributed/thread_mesh_node.php` revert the send-loop pace `Coroutine::sleep(0.008)` → `0.001`; run `./run.sh 60 1024`. Expect PASS now that refutation is reachable (883e26f9). If it fails, that is P2 evidence — record it, restore the pace, do NOT block merge on it.
  - *Accept:* either full-rate PASS (rewrite the pacing comment to "headroom, not required") or a documented failure pinned to control-plane contention.
- **0.2 Infection on the package.** `docker compose exec php vendor/bin/infection` scoped to `packages/nexus-cluster-tcp` (see `infection.json5`). Triage survivors: kill real gaps with tests, annotate provably-equivalent mutants.
  - *Accept:* MSI ≥ the repo minimum (80% / 90% covered) for the package, or a written justification per surviving mutant.
- **0.3 Full clean-machine battery.** Nothing else running (stop docs/landing/example containers). `make test` + cluster loopback + Swoole + `make psalm` + `make phpcs` + `make cs` + deptrac + website build + landing build. Capture the numbers.
  - *Accept:* all green; teardown segfault (0.4) either fixed or explicitly quarantined.
- **0.4 Loopback teardown segfault.** Exit 139 after `OK(NN)` on `make test-cluster-loopback`. Bisect: is it OTel/Swoole ext at process exit, or a cluster shutdown-order bug? If external + post-assertions → quarantine with a `register_shutdown_function` note + doc. If ours → fix shutdown order.
  - *Accept:* suite exits 0, or a one-line documented known-issue with root cause identified (not "unknown").
- **0.5 PR text.** Base `feat/msgpack-serializer`; title `feat: nexus-cluster-tcp — TCP mesh clustering with gossip membership`. Body must state the honest scope qualifiers (auth-required/TLS/≤20 nodes/tuned-FD-under-load) and link the benchmarks + trust-model docs.

---

## PHASE 1 — Protocol hardening (the priority; 2–4 focused sessions, one task each)

### 1.A — Control/data-plane connection separation  ⭐ highest leverage
**Why:** gossip/heartbeat frames queue behind bulk `Message` traffic on the shared socket; today's mesh only passed saturated after hand-widening phi `minStdDev` to 3s. This is the root cause of every "needs manual FD tuning under load" caveat.
**Design:** a dedicated per-peer **control connection** carries `Handshake`/`HandshakeAck`/`Gossip`/`Leave`; the existing link stays the **data plane** for `Message` frames. `PeerConnection` gains a role; `sendByPrefix` routes by `FrameType`; `dialSeed` opens the control connection first (identity + membership), data lazily on first `Message`. Inbound: classify an accepted link by its first frame's type. The write-mutex now guards two lighter sockets.
**Files:** `ClusterNode` (sendByPrefix, dialSeed, wireInboundLink), `PeerConnection`, `MeshOutboundSink`.
**Tests:** loopback — control frames flow while the data link is saturated (assert gossip interarrival stays bounded under a Message flood); Swoole — two-node still converges + tell/ask.
**Accept:** the distributed 16-node mesh passes with **default** phi settings (remove the `minStdDev(3s)` tuning from the soak topology) — this is the acceptance bar for the whole phase.

### 1.B — Gossip efficiency + staleness
**Why:** `viewToGossipMembers` ships the full view every round → O(N²) cluster-wide bytes; and suspicion has no timestamp, so staleness is invisible to merge.
**Design:** (1) add `lastStatusChange` (unix) per member to `GossipPayload` members (nullable map key, read by-key; cross-decode test). (2) Digest-then-delta: gossip a view **hash** first; send the full/delta view only on mismatch. Keep the join-semilattice merge; `pickWinner` may use `lastStatusChange` to break incarnation ties toward fresher info.
**Files:** `GossipPayload`, `MembershipService` (buildGossipEffects, gossipToView, mergeView, pickWinner), `ClusterView`.
**Tests:** unit — hash stable across equal views, mismatch triggers full send; timestamp survives round-trip; staleness tie-break. Wire — old⇄new cross-decode.
**Accept:** 32-node mesh (`WORKERS=8 THREADS=4` or `4×8`) converges; measured gossip bytes/node stays ~flat as N grows 8→16→32.

### 1.C — Rejoin (`RejoinRequested`)
**Why:** a Down node must currently restart its process; restart-at-incarnation-1 now interacts with the dedup 30s quiet period.
**Design:** wire the designed `RejoinRequested` message so a node that observes itself Down (or is told to) bumps its incarnation and re-announces via `applyRejoin` (already exists, unwired). Ensure the higher incarnation bypasses the dedup quiet period (it already does — add the explicit test).
**Files:** `MembershipActor`, `MembershipService::applyRejoin`, a `RejoinRequested` message, `ClusterNode` trigger.
**Tests:** unit — self-Down → rejoin bumps incarnation, re-announced NodeUp published despite quiet period. Integration — kill+restore a node, assert re-converge without process restart.
**Accept:** a node can rejoin after Down without restarting; dedup does not swallow the rejoin.

### 1.D — Minimum-members partition guard
**Why:** AP with no floor — a split lets both halves make independent Down decisions (split-brain Down storm).
**Design:** `ClusterTopology::withMinimumMembers(int)`. Below quorum, the node enters a **degraded** mode: makes no new Down decisions, emits a `ClusterDegraded` event, keeps serving data. Above quorum again → `ClusterRecovered`.
**Files:** `ClusterTopology`, `MembershipService::applyTick` (gate Down transitions on member count), new events.
**Tests:** unit — below floor, a phi-crossed peer is NOT downed + degraded event; recovery re-enables. Integration — partition a 4-node mesh 2|2, assert neither half storms Downs.
**Accept:** a symmetric partition produces zero spurious Down beyond the guard threshold.

### 1.E — Per-node mTLS identity binding
**Why:** P1 authenticates membership (shared secret); one leaked secret impersonates any node.
**Design:** VERIFY FIRST whether Swoole exposes the peer certificate on the accepted `Coroutine\Socket` after `exportSocket()` (spike before committing to the approach). If yes: bind the handshake `NodeAddress` to the cert SAN/CN or a topology-pinned fingerprint, checked in `parseHandshakeFrame` after the HMAC gate. If no: document HMAC-as-primary and defer identity binding with the spike findings recorded.
**Accept:** either cert-bound identity rejection tests pass, or a written spike conclusion + deferral.

---

## PHASE 2 — Code quality + operations (≈1 week)

- **2.A Split `ClusterNode` (1,119 LOC god-class).** Extract `ClusterBootstrap` (the `boot()` wiring), `FramePump` (unify the near-duplicate `wireInboundLink`/`dialSeed` `onFrame` handlers into one parameterised pump), `ConnectionRegistry` (accepted/outbound link maps + caps + liveness throttle). Delete the anonymous outbound sink in `boot()` that duplicates `MeshOutboundSink`. *Accept:* `ClusterNode` < ~400 LOC, no behaviour change, full battery green, one frame-pump implementation.
- **2.B Operations runbook** (`website/docs/guides/clustering-operations.md`): what each metric means and its alert threshold; the NodeSuspected→Down lifecycle and how to read it; auth-secret rotation procedure; phi tuning decision table under load; degraded-mode response; capacity guidance (connections/gossip bytes vs N).
- **2.C Hard-kill chaos test.** Extend the distributed harness (or a Swoole integration test) to `docker kill` a node *mid-flood* and assert the survivors mark it Down within the window and re-converge; restore and assert rejoin. *Accept:* deterministic, hang-safe, neutralize-validated against 1.C/1.D.

---

## PHASE 3 — Polish + homepage (≈1 week)

- **3.A Homepage/landing refresh** (`landing/src/pages/cluster.astro`): lead with the security story (shared-secret auth + TLS), the tuned perf numbers, and the honest scale envelope; update the caveats box to reflect P1/P2 progress.
- **3.B Kubernetes example.** A `>2`-node example with headless-service seed discovery + a `StatefulSet`, and a docs walkthrough. Demonstrates advertise-vs-bind and auth-secret via Secret.
- **3.C Write-batching perf pass.** Coalesce small frames per event-loop tick to attack the ~10µs syscall/scheduler remainder; re-run the hot-path profiler + benchmarks; only keep if it measurably helps (measure, per the tuning discipline — do not ship speculative complexity).
- **3.D Concurrent-ask benchmark.** Add a pipelined-ask scenario (K in flight) to `ClusterTcpPerformanceTest`; document the real ask throughput ceiling (the current ~28K/s is sequential).

---

## Sequencing recommendation

`Phase 0` → **`1.A` (single highest-leverage — makes the failure detector trustworthy at default settings)** → `1.B` → `2.A` (before the code sprawls further) → `1.C`/`1.D` → `2.B`/`2.C` → `1.E` → `Phase 3`.

Merging after Phase 0 + 1.A alone drops most of the review's qualifiers. Everything after is "top-notch," not "production-ready."

## Acceptance for "production-ready clustering" (the phrase that matters)

The distributed 16- and 32-node mesh soaks pass at **default** failure-detection settings (no hand tuning), with authentication on, through a hard-kill of one node mid-load, with flat gossip bytes/node as N grows — and an operator can follow the runbook to diagnose a NodeSuspected without reading the source.

---

## REVIEW ADDENDUM (2026-07-09) — 5-reviewer adversarial audit

Five parallel adversarial reviewers (protocol, code-quality, concurrency, docs, security+perf) audited the package. Headline findings source-verified by the lead. Verdict: **merge-ready as an honestly-scoped v1 for trusted/authenticated/≤16-node/low-churn meshes AFTER the P0 blockers; NOT yet production-ready** for untrusted/large/high-churn. No finding is an architectural dead-end. The pure `MembershipService`/`ClusterView` core, the write-mutex, recv-timeout fix, auth-gate ordering, and DoS-map bounds all verified SOLID.

### P0 — PRE-MERGE BLOCKERS (small, do before opening PR)
- **[S1 HIGH ✓verified] maxFrameSize DoS.** `FrameCodec` defaults 8 MB (FrameCodec.php:38), NOT wired from `ClusterTopology` (no field), no per-link reassembly-buffer cap. 1024 inbound links × 8 MB ≈ 8 GB pin via slow partial frames (handshake timeout disarms after ID). Add `maxFrameSize` to topology (default ~1 MB), thread into SwoolePeerLink's FrameCodec, cap the reassembly buffer + `pendingFrames`.
- **[C#1 HIGH ✓verified] Asks never fail on peer death.** `TcpAskRegistry` cleared only by reply or per-ask timeout; nothing in PeerDisconnected/NodeDown touches it. One dead peer parks coroutines + can exhaust the 10k cap → `AskCapacityExceededException` to HEALTHY peers. Track target NodeAddress per correlation id; add `failAllForNode()` on disconnect.
- **[M1 MED] Silent decode failures.** 29 `catch(Throwable)`; handshake/gossip/leave/ack decode paths return null with no log/metric. Add `nexus.cluster.frames.malformed` counter + debug log per path.
- **[Docs H2 HIGH] Example vs benchmark contradiction.** example README warns of false `Suspect→Down` flapping; benchmarks.md claims "zero suspicion PASS 16/16". This IS a symptom of P1#1 below. Re-verify on current HEAD; reconcile wording (honesty blocker an adopter meets day one).

### P1 — PROTOCOL CORRECTNESS (the priority)
- **[#1 HIGH ✓verified] Non-monotonic recovery clobber.** `recordLiveness:394` recovers a peer to Up keeping incarnation N; `pickWinner:126` lets incoming `Suspect@N` beat local `Up@N`. A locally-recovered peer flaps every gossip round until the OWNER refutes (higher incarnation). Root of the "noisy under load / needs minStdDev(3s)" symptom AND the example's false-Suspect. FIX: local liveness must not be demotable by equal-incarnation gossip (treat local Up as tie-winner, or only owner asserts own Up).
- **[#4 HIGH] Incarnation-1 re-add regression.** `recordLiveness:410` re-adds a removed peer at hard-coded incarnation 1 → loses to lingering higher-incarnation Suspect/Down; feeds #1. Preserve last-known incarnation (dedup already tracks it) and re-add at `max(1, lastKnown)`.
- **[#6 MED] Silent peer never suspected.** phi returns 0.0 on empty window (PhiAccrualDetector:103); a peer that handshakes once then goes silent is never suspected (no absolute no-heartbeat fallback). Add `now-lastArrival > maxNoHeartbeat → Suspect` independent of phi.
- **[#7 MED] Wall-clock phi.** Detector fed `DateTimeImmutable` (wall clock); NTP/leap jumps poison the window (forward jump → spurious mass-Suspect). Use `hrtime(true)` monotonic (as LivenessThrottle already does).
- **[#2 HIGH] Handshake seeds empty view.** `MembershipActor` passes `ClusterView::empty()` as theirView → transitive membership seeding is dead code; join relies purely on gossip. Fold HandshakeAck view members into the membership view.
- **[#3 HIGH] No quorum/partition guard.** Symmetric partition → both halves Down each other → NodeDown storm on heal; a minority of 1 declares the rest Down and runs as singleton (single-writer guarantees are cluster-blind). Add `withMinimumMembers()` + degraded mode (no new Downs below floor).
- **[1.A ⭐ / P3 MED] Control/data-plane separation.** Gossip/heartbeat share the peer socket + write-mutex with bulk Message frames → send-side HOL starves liveness under load = the phi TRIGGER. Dedicated control connection. (Reduces the trigger; #1 is why a triggered suspicion then flaps.)
- Rejoin without process restart (applyRejoin wired; bypass dedup quiet-period on higher incarnation).

### P2 — SECURITY
- **[S2 MED ✓verified] Nonce never checked** → free replay within the 60 s window (HandshakeAuthenticator:74 validates MAC+freshness, never records nonce). Bounded seen-nonce set (TTL=window), or bind to TLS channel. At minimum document the 60 s replay window.
- **[S3 MED] Endpoint-registry poisoning.** advertise endpoint never validated vs observed TCP peer; gossiped `addr→endpoint` mappings trusted → traffic-redirection primitive. Prefer observed peer addr; require auth to modify entries; rate-limit new endpoints/peer.
- **[S5 LOW] Unauthenticated Leave for a THIRD node** → forced immediate Down + mesh relay. Require Leave.node == authenticated sender (self-departure only).
- **[S4 MED] Insecure default.** No TLS + no auth = any reachable process joins. Boot-time warning when neither set on a non-singleNode topology.

### P3 — CODE QUALITY
- **[H4 HIGH] Triple-duplicated connection management.** `MeshOutboundSink` (tested) is bypassed by `buildOutboundSink` anon class + `sendByPrefix`, creating TWO independent socket pools to the same peer. Route everything through one `MeshOutboundSink`; delete the anon sink.
- **[H1/H3 HIGH] ClusterNode god-class (1,119 LOC, ~5 responsibilities); near-duplicate onFrame handlers (already drifted → the acceptedLinks asymmetry).** Extract ConnectionRegistry / FramePump / Bootstrap; unify onFrame behind a PeerContext. Target <300 LOC.
- **[H2 HIGH] Frame pump has zero direct tests** (falls out of H1/H3 extraction).
- **[C#2 MED] Link-map leaks:** inbound onClose never unsets `acceptedLinks`; `outboundConns` + perpetual reconnect loops never cleaned on NodeDown.
- **[C#3 MED ✓verified] SwooleRuntime.timerIds** added but never pruned (only cleared at shutdown) → unbounded RSS growth on a long-running node. (runtime-swoole pkg; cluster is heaviest user.) Prune in the one-shot callback + on cancel.

### P4 — PERFORMANCE
- **[P1 HIGH] Ask cost, not pipelining.** ~28K/s ask vs 100s-of-K tell is per-ask `random_bytes(16)` CSPRNG + per-ask `scheduleOnce` timer + span, not protocol serialization. Monotonic per-connection counter IDs + a single timing-wheel for timeouts closes most of the gap.
- **[P2 HIGH] O(N²) gossip.** Full view every round; ~1.8 MB/s cluster-wide at N=100, ~180 MB/s at N=1000. Delta/digest (scuttlebutt) gossip.
- **[P4 MED] No write-batching** — one `sendAll()` syscall per frame; coalesce per-peer per event-loop tick (highest tell-throughput win).
- **[P5 MED] Document the per-node one-core ceiling + scale-out story** (multiple nodes/host or reactor sharding).

### P5 — DOCS & HOMEPAGE
- **[Docs H1 HIGH] No operations runbook.** operations/runbook.md + metrics.md have ZERO cluster content. Add: 7 `nexus.cluster.*` metric meanings + alert thresholds; NodeSuspected→Down lifecycle (reason Connection/Phi/Gossip playbook); auth-secret rotation across a live mesh; phi-tuning decision table by symptom; capacity vs N.
- **[Docs M1/M2] API-table inaccuracies:** `withFailureDetection` 4th param `phiThreshold` undocumented; `create()` signature misrepresented (maxNoHeartbeat/phiSampleSize/phiMinStdDev are wither-only).
- **[Docs M3] isAlive() doc stale** (aliveChecker seam now exists).
- **[Docs M4/L1] Auth invisible on the guide + landing** (only in package trust-model). Add a "Membership authentication" subsection + one honest homepage line (open by default; set a secret).
- **[Docs M5] Link the benchmarks page** from ref/guide/landing.
- 32-node validation + K8s StatefulSet example (still owed).

### Sequencing
P0 (pre-merge) → P1#1+#4+1.A (the correctness/noise root) → P0-adjacent security S2/S3 → P3 H4/H1 (before more sprawl) → remainder. #1 and #4 share one root: **incarnation monotonicity across recovery/re-add** — the load-bearing invariant the whole LWW merge depends on. Fixing it (plus 1.A reducing the trigger) is what lets the soak pass at DEFAULT phi.

---

## AUDIT FIXES APPLIED (2026-07-10) — status after the whole-PR audit

FIXED & committed (all gates green; 237 cluster-tcp unit + 46 Swoole + 32 loopback pass):
- **S1 (complete)** f4b984a8 — SwoolePeerLink reassembly buffer now explicitly bounded + pendingFrames capped.
- **C#1 (complete)** f4b984a8 — AskFailingMembershipEventPublisher fails in-flight asks on the authoritative NodeDown (phi-driven, no socket close), not just inbound onClose.
- **Observer effect** 3ce9f8ce — core UntracedMessage marker; ActorCell skips per-message span+metrics for membership ticks (GossipTick/HeartbeatTick/PeerLivenessObserved), so tracing no longer perturbs the phi detector.
- **#6** 1ea9c89f — PhiAccrualDetector::millisSinceLastHeartbeat + applyTick absolute-silence fallback: a handshake-once-then-silent peer (empty phi window) is now suspected.
- **C3** 269ac257 — nexus.cluster.frames.decode_failed counter + debug log on the handshake/gossip/leave decode catches.
- **D3/D5** 8b93ff61 — example README reconciled with the benchmark (honest, no false "fixed"); landing surfaces withAuthSecret/open-by-default.

REMAINING (deferred — large/risky, need focused sessions, NOT rushed in a marathon):
- **#1 + #4 incarnation monotonicity** — THE flapping root. Changing recovery/re-add so a locally-recovered/re-added peer is not demotable by equal-or-stale gossip touches the join-semilattice convergence core; must be validated against the distributed mesh soak (getting it wrong diverges the mesh). Highest value, highest risk — dedicated session.
- **#2 handshake view seeding** — wire-format change (HandshakeReceived must carry the member list) + merge wiring.
- **#3 quorum / degraded mode** — new ClusterTopology::withMinimumMembers() + degraded state + ClusterDegraded/Recovered events + applyTick gating.
- **#7 monotonic phi clock** — mechanical but broad API churn (detector + service + actor + phi tests all pass DateTimeImmutable); feed hrtime.
- **C1 ClusterNode god-class** extraction (ConnectionRegistry/FramePump/Bootstrap) + **C2** delete the buildOutboundSink duplicate (route through MeshOutboundSink).
- **Perf finding-1** debugEnabled guard (marginal); **CSPRNG-per-ask**, **O(N^2) delta gossip**, **write-batching** (optimizations).
- **Docs D1** operations runbook + fix metrics.md ("no registry" is now wrong); **D2** promote the observability story into website/docs.

---

## #1 SOAK-FIRST RESULT (2026-07-10) — hypothesis DISPROVEN, NOT committed

Attempted the incarnation-monotonicity fix (recordLiveness stops locally recovering a Suspect
peer; applyTick holds off Down while directly hearing from it; recovery only via higher-
incarnation refutation). Unit-validated (240 unit + 46 Swoole green). Then validated against
the 16-node distributed soak at DEFAULT phi (MESH_PHI_TUNING toggle), which DISPROVED it:

- BASELINE (default phi, no fix): suspected ~210/node [gossip ~190, phi ~15], down=0. Noisy, self-heals.
- POST-#1 (default phi): suspected ~195/node (barely moved) AND down=8-22 — a REGRESSION.

Root cause revealed: the soak's suspicion storm is NOT the recordLiveness flap (the
MembershipEventDeduplicator already absorbs that). It is data-plane SATURATION stalling the
single-core reactor for multiple seconds → gossip/heartbeat processing is delayed → phi
legitimately fires (~15x/node) and each firing spreads a Suspect through the mesh (~190 gossip
events). That is **1.A (control/data-plane separation)**, not #1. Worse, removing the
liveness-recovery made the applyTick hold-off unreliable: during a multi-second stall
millisSinceLastHeartbeat exceeds the give-up window, so peers were falsely Downed.

CONCLUSION: #1 is coupled to 1.A and regresses standalone. CORRECT SEQUENCING: land **1.A first**
(dedicated control connection so heartbeat/gossip never queue behind bulk Message frames →
phi stops firing under load), THEN layer the incarnation-monotonicity fix on top (with the
hold-off now safe because control frames don't stall). The soak-first discipline caught this
before any commit — the fix was reverted, HEAD stays at the landing-fix commit.
