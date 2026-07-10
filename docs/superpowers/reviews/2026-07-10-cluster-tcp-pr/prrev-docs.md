# PR Review: Documentation & Landing Page (feat/cluster-tcp)

## Verdict

**Approve with nits** — one Important-severity accuracy issue (stale phi/minStdDev tuning advice that contradicts a same-branch root-cause fix), otherwise the docs are thorough, honest, and well-hedged, and all previously-flagged regressions (cluster.receive span, ClusterDegraded event, split-brain/withMinimumMembers consistency, duplicated landing bullet, metric miscount) are confirmed fixed and did not recur.

## Findings

### Important

**1. Stale phi-accrual tuning advice contradicts a same-branch root-cause fix.**
`website/docs/guides/clustering-over-tcp.md:203-204` and `website/docs/packages/cluster-tcp.md:129-134` recommend widening `minStdDev`/`phiMinStdDev` to 1-2s for WAN/jittery deployments, framed around "ordinary coroutine or GC pauses cross the threshold and produce false Suspect → Down flapping."

This framing is stale. Commits `e7ea7e61`, `3d7302f1`, `1c6d63bf` (all present on this branch, same day as HEAD) fixed exactly this failure mode by feeding the phi-accrual detector the **TCP ingress/socket-receive timestamp**, stamped synchronously in the recv coroutine (`ClusterNode::observeLiveness()`, `packages/nexus-cluster-tcp/src/ClusterNode.php:553-558`), rather than the actor-processing timestamp. `PeerLivenessObserved`'s doc comment states this explicitly: feeding ingress time "keeps failure detection immune to local scheduler contention under data-plane load." A regression test (`livenessFeedsTheDetectorAtObservedTimeNotProcessingTime`) simulates an 8s actor-processing delay and proves the detector is unaffected. This matches my own project memory of the fix (`cluster-tcp-phi-ingress-timestamp.md`), validated at true default phi settings with zero minStdDev widening.

The docs should be updated to note the detector is fed real per-frame socket-arrival time and is inherently robust to local scheduler/GC jitter — `minStdDev` widening is no longer a workaround for that class of false suspicion. (Widening may still be legitimate advice for *genuine network-level* jitter on true WAN links, but the current text conflates the two and cites the exact symptom the fix eliminated.)
- Fix: `website/docs/guides/clustering-over-tcp.md:203-204`, `website/docs/packages/cluster-tcp.md:129-134`.
- Note: `landing/src/pages/cluster.astro:255` only documents the default (500ms) with no WAN-widening recommendation — it does not carry this stale advice and needs no change.

### Minor

**2. Metrics tables are non-exhaustive without saying so.** `website/docs/packages/cluster-tcp.md`'s metrics table and `website/docs/operations/metrics.md`'s callout together document 11 of the cluster metrics that exist in source, but several more exist and are undocumented anywhere (`messages.received`, `messages.unroutable`, `frames.received`, `bytes.received`, `send_buffer.dropped`, `nodes.suspected`, `nodes.recovered`, `nodes.pruned`, `heartbeats.received`, `gossip.rounds`). Not inaccurate, just incomplete — worth a "non-exhaustive" caveat or a follow-up to document the rest.

**3. Benchmark prose multipliers don't match the doc's own table, and the K=12 shortfall goes unacknowledged.** `website/docs/guides/cluster-tcp-benchmarks.md:154` claims "~1.95× at K=2, ~3.8× at K=4, ~7.7× at K=8" but the scaling table's own numbers (`:145-152`, baseline 78,314 at K=1) give 2.01×, 3.91×, and 7.44× — all three look stale, likely carried over from the pre-tuning run. Separately, the per-core figure × 12 (84,100 × 12 ≈ 1.01M) sits ~13% above the claimed K=12 aggregate (877,859; ~93% efficiency vs the K=1 scaling baseline), and the prose explains the K=12→16 plateau but never acknowledges this K=12 gap. Fix: recompute the three multipliers from the table and add one sentence acknowledging ~90% scaling efficiency at K=12. The surrounding hedging ("near-linear," "order-of-magnitude guidance, not a datasheet," ballpark-labeled comparison table) is otherwise honest and consistent.

## Regression checks (previously flagged issues — verified fixed)

- **`cluster.receive` span**: documented (`website/docs/packages/cluster-tcp.md:190`, `landing/src/pages/cluster.astro:302`) and confirmed real in source (`Messaging/InboxRouter.php:78`), including its parent-chaining to the sender's propagated trace context (`ClusterRef.php:93-94` comment explicitly describes the ordering fix).
- **`ClusterDegraded` event**: documented consistently in the guide (`clustering-over-tcp.md:237,326`), package reference (`cluster-tcp.md:26,213`), and landing page (`cluster.astro:361,378`); field names (`$reachableMembers`, `$requiredMembers`) match source (`Membership/ClusterDegraded.php:17`) exactly.
- **Split-brain / `withMinimumMembers()` consistency**: guide and landing page now agree — both state split-brain protection is opt-in via `withMinimumMembers()`, both describe the degraded/quorum-floor behavior identically. Confirmed method exists in source (`ClusterTopology.php:186`).
- **Duplicated landing bullet**: no duplicate bullet/sentence text found in `cluster.astro` or `messenger.astro` (checked via text-dedup scan of all `<span>`-wrapped bullet content).
- **Metric miscount**: `cluster.astro:304` claims "Six counters + one bytes-sent histogram" — the `obsCode` block lists exactly 6 counters + 1 histogram; count is now correct.

## Additional accuracy spot-checks (all CONFIRMED against `packages/nexus-cluster-tcp/src`)

- `ClusterTopology::create()` param names/order, defaults (heartbeatInterval 1s, gossipInterval 1s, phiThreshold 8.0 as a real named `create()` param, maxInboundLinks 1024, singleNode false) — confirmed. `authSecret` is not a `create()` param (only via `withAuthSecret()`) — docs correctly never show it passed to `create()`.
- `withFailureDetection(sampleSize, minStdDev, maxNoHeartbeat, phiThreshold)` — confirmed, defaults 200/500ms/10s match CLAUDE.md and docs.
- `withReconnectBackoff(initialBackoff, maxBackoff)` defaults 100ms/30s — confirmed.
- `withInboundLimits(handshakeTimeout, maxInboundLinks)` defaults 10s/1024 — confirmed.
- `SuspicionReason` enum cases `Connection`/`Gossip`/`Phi` — confirmed.
- Span names `cluster.handshake` (Internal), `cluster.send`/`cluster.ask` (Producer), `cluster.receive` (Consumer) — confirmed, all real.
- `ClusterNode::boot()` signature (`ActorSystem, ClusterTopology, ?TypeRegistry, ?MeshTransport, ?Observability, ?LoggerInterface`) — confirmed.
- Max frame size 8 MB — confirmed (`ClusterTopology.php:121`).
- HMAC handshake auth: SHA-256 over identity claim + nonce + timestamp, 60s freshness window — confirmed exactly (`HandshakeAuthenticator.php:72,117,173`).
- Core metric names (`messages.sent`, `messages.local_shortcircuit`, `asks.sent`, `asks.capacity_rejected`, `bytes.sent`, `frames.sent`, `handshake.rejected`) and the `operations/metrics.md` extras (`frames.decode_failed`, `asks.pending`/`.resolved`/`.timed_out`, `ask.duration`) — all confirmed present in source.

## Completeness (adopting cluster-tcp from zero)

The guide (`clustering-over-tcp.md`) covers topology config, bind-vs-advertise, seeds (including Kubernetes headless-service DNS discovery and env-var parsing), TLS + mutual-TLS guidance, failure-detection tuning (modulo the Important finding above), consistency/AP caveats with `withMinimumMembers()`, wire format, reconnect backoff, inbound connection limits, and PSR-14 event wiring — a reader can reach a working TLS+auth cluster from this guide alone. Minor gap: no explicit "rolling restart / version upgrade" section (the guide does cover "no rejoin after Down — must restart process" but not a multi-node rolling-upgrade sequencing story); this is a reasonable omission for a C1 milestone doc rather than an error, but worth a future addition if this becomes a common operational question.

## Strengths

- The benchmarks doc is unusually candid: explicit methodology, environment caveats (Docker Desktop VM, shared-reactor contention), a "before tuning" comparison column, an honest cross-system comparison table with consistent hedging language, and a clear-eyed "where it is not the answer" section.
- The "What the cluster is not" caution boxes (both guide and landing page) are specific and technically accurate rather than boilerplate — no leader election, no service registry, no auto-rejoin, gossip convergence latency, and (now) the opt-in quorum floor are all named precisely.
- Landing page markup is now fully consistent: every bullet across `cluster.astro` and `messenger.astro` uses the `<span>` wrapper pattern established by the doctrine/http/observability fixes in this same diff — no regression.
- Package README follows the exact established minimal pattern used by sibling packages (`nexus-messenger`, `nexus-cluster`, `nexus-core`) — consistent, not thin.
