# feat/cluster-tcp — final pre-merge review triage (2026-07-10)

Four parallel reviewers over the branch (merge-base `da8ec590`): A = cluster-tcp source
(opus), B = tests+example+soak, C = docs+landing, D = integration/rest. Totals:
**2 Critical, 18 Important, 17 Minor** (before de-duping design-deferred items).

## FIX NOW — code defects (real, tractable, merge-blocking)

| id | sev | file | defect |
|----|-----|------|--------|
| C1 | Critical | ClusterNode.php:636-647 | `onClose` frees `inboundLinks[$id]` but never removes `acceptedLinks[$prefix]` → unbounded memory + monotonic Leave-fanout CPU with reconnect churn |
| C2 | Critical | ClusterNode.php:579 | re-handshake overwrites `acceptedLinks[$prefix]` without `close()`ing the prior link → recv-coroutine + socket/fd leak |
| I6 | Important | ClusterNode.php:469-496,444-452 | lazily-created outbound `PeerConnection` never evicted on NodeDown → reconnect timer + SYN traffic leak per departed peer |
| I5 | Important | Messaging/InboxRouter.php:122-133, ClusterReplyRef.php:46-53,71 | inbound-ask `replyPath` echoed unvalidated; malformed path throws in delivery seam — guard shape before constructing reply ref |
| I2 | Important | Membership/HandshakeAuthenticator.php:74-89 | HMAC handshake has no nonce table → captured frame replayable for the 60 s freshness window; docstring overclaims "cannot be replayed" — add bounded seen-nonce set + correct docstring |
| D1 | Important | messenger Poll.php/Lifecycle/Tick.php/MessagesProcessed.php | don't implement the `UntracedMessage` marker this branch introduced → per-message span+metric noise on the broker-poll hot path |

## FIX NOW — soak-harness honesty (now unblocked by the phi-ingress fix)

| id | sev | file | action |
|----|-----|------|--------|
| B1 | Important | thread_mesh_node.php:298-308 | the `minStdDev: 3s` detune is now OBSOLETE for the data-plane case (phi-ingress fix → phi=0 at default). Remove/re-gate it and rewrite the comment: steady-state passes at default phi; the residual is convergence-echo (#1), not data-plane |
| B2 | Important | thread_mesh_node.php:580 | `suspectedUnderLoad > 30` magic threshold — add a comment on its provenance (observed convergence-echo baseline + margin) |
| B6b | Important | thread_mesh_node.php:495-517 | disclose the ask-probe burst as a second historical phi-noise source (now moot post-fix) |
| B6c | Important | run.sh header | one-line note: the observability overlay can induce observer-effect instability; run.sh runs WITHOUT it for the authoritative verdict |

## FIX NOW — docs (cheap, accuracy)

| id | sev | file | fix |
|----|-----|------|-----|
| C1d | Important | packages/cluster-tcp.md, landing/cluster.astro | document the `cluster.receive` (Consumer) span |
| C2d | Important | packages/cluster-tcp.md, clustering-over-tcp.md, landing | document the `ClusterDegraded` PSR-14 event (the quorum-loss alert signal) |
| C3d | Important | guides/clustering-over-tcp.md | "Consistency caveats" contradicts landing — add that `withMinimumMembers()` provides an opt-in quorum floor |
| C4d | Minor | landing/cluster.astro:369-370 | remove the duplicated "No leader election" bullet |
| C5d | Minor | landing/cluster.astro:303 | metric miscount — "six counters + one bytes-sent histogram" (7 total) |
| M5 | Minor | ClusterNode.php:921-925 | `processedLeaves` comment says "oldest" but eviction is FIFO-by-insertion — correct the wording |

## FIX NOW — example + lint (cheap)

| id | sev | file | fix |
|----|-----|------|-----|
| D2 | Important | examples/nexus-messenger-redis/composer.json | add `observability` + `observability-serialization` to `require` (README's standalone path is broken without them) |
| B7 | Minor | examples/nexus-cluster-tcp/bin/node.php:182-188 | log-context array keys not alphabetically sorted → would fail phpcs |
| B8 | Minor | examples/nexus-cluster-tcp/bin/node.php:226-230,307-311 | misconfig path leaves `run()` blocking forever — add explicit exit |

## FIX NOW — test coverage (quality, targeted)

| id | sev | file | add |
|----|-----|------|-----|
| B3 | Important | HandshakeAuthenticator + its test | make `sign()` clock-injectable; drive the test with a fake clock (determinism) |
| B4 | Important | ClusterMetrics/AskRegistry tests | assert `AskCapacityExceededException` type is surfaced (contract currently unverified) |
| B5 | Important | membership/integration tests | end-to-end non-EOF heartbeat-timeout → Down path (the class of bug already fixed once; the untested one) |
| B6 | Important | ClusterNodeSwooleTest | replace negative-assert-after-fixed-sleep with bounded poll-for-wrong-state |

## DEFER — tracked follow-up (NOT merge-blocking)

- **I1** (Important) control/data-plane HOL under large frames = **Approach B**, consciously
  deferred, evidence-gated (1 KB payloads don't exhibit it; phi-ingress fix already handles the
  observed steady-state case). Documented in the hardening plan.
- **I4** (Important, A) ingress-timestamp seam — the reviewer's doubt was empirically settled by
  the soak (phi 15→0). Not a defect; no change.
- **I3** (Important, A) `applyGossip` self-refutation drops merge `suspectSince` — no data loss
  today (rejoin passes it through), latent trap only.
- **M1** (Minor, A) `ClusterNode` god-class + `wireInboundLink`/`dialSeed` ~90% duplication →
  extract-class refactor. Large; defer. NOTE: the C1/C2/I6 fix must cover BOTH duplicated
  onFrame branches until this refactor lands.
- **M2/M3/M4** (Minor, A) `FrameCodec` O(n²) substr, phi window `array_shift`, blanket `safely()`
  swallowing → perf/robustness follow-up.
- **B9/B10/B11, D3/D4/D5** (Minor) test-tax sleeps, weak-invariant test names, example
  unexercised path, monorepo composer-constraint drift.

## Validation required for the code fixes

Transport-lifecycle changes (C1/C2/I6) MUST be re-validated by the 16-node mesh soak (default
phi, ingress fix in place) — convergence + steady-state must remain clean and no new downs — per
the session lesson that unit-green is necessary but not sufficient for the mesh.
