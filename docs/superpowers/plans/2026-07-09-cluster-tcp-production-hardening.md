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
