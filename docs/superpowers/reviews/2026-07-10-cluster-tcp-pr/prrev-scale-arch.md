# PR Review — feat/cluster-tcp — Scalability & Architecture

**Scope:** nexus-cluster (contracts) + nexus-cluster-tcp (Swoole mesh) at HEAD `6c343f2b`.
**Validated baseline (trusted):** 16-node full mesh, 1KB, ~738k msg/s aggregate, flat suspicion, down=0, default phi.

## Verdict: **approve-with-nits**

The C1 milestone is architecturally sound and correct at its designed scale (N≈16, mesh). Every scalability limit I found is *inherent to the full-mesh + single-reactor design*, not a bug — but the practical ceiling is **undocumented**, which is the one thing that should change before merge. No request-changes-level defects in the reviewed dimensions.

---

## Findings

### S1 — Full-mesh O(N²) connection topology is the hard ceiling; undocumented. [major-nit / docs]
`ClusterNode::boot` dials every seed (line 343-346) and `sendToPrefix` lazily opens one outbound `PeerConnection` per peer prefix (line 509) while accepting one inbound link per peer (line 618). Result: **2 sockets per node-pair, ~2N per node, ~N² cluster-wide.** Plus `SwoolePeerLink` runs one recv-loop **coroutine per link** → ~2N coroutines/node, N² cluster-wide.
- Growth: fd usage per node = O(N); coroutine count per node = O(N). At N=500 that's ~1000 fds + ~1000 recv coroutines *per process* before app sockets — brushing default `ulimit -n 1024`.
- Boot connection storm: N mutually-seeding nodes each dial all seeds simultaneously → O(N²) simultaneous handshakes cluster-wide at t=0. `PeerConnection` backoff (100ms→30s) cushions *failed* dials but not the initial thundering herd.
- **The 15-line README documents no N ceiling, no fd guidance, no delta-gossip caveat.** This is the single most important gap. `packages/nexus-cluster-tcp/README.md`.

### S2 — Gossip carries the full member list → O(N) per frame, O(N²)/round/node-fanout. [known-deferred, urgency rising past N≈50]
`MembershipService::viewToGossipMembers` (line 603) serialises **every** known member into each `GossipPayload`. `buildGossipEffects` (line 622) selects `min(3, |candidates|)` targets, so per node per tick = 3 frames × O(N) members = O(N) bytes; cluster-wide O(N) nodes × O(N) = **O(N²) gossip bytes/round**. Dissemination to full convergence adds another factor.
- At N=16: ~16 members × 3 fanout — trivial (matches the 738k baseline showing no gossip pressure).
- At N=50: each gossip frame ~50 members (~3-4KB msgpack); 3/sec/node — still fine.
- At N=100+: full-list gossip becomes a real fraction of reactor time and bandwidth; **delta/scuttlebutt gossip (already a listed deferred item) becomes necessary, not optional.** Recommend documenting N≈50-64 as the tested-comfortable ceiling for full-list gossip.

### S3 — Single write-mutex serialises control-plane behind data-plane per link. [known, mitigated for phi, residual]
`SwoolePeerLink::write` (line 274-283) holds a capacity-1 channel across `sendAll` for **all** frames on a link — an app `tell` flood and a gossip/heartbeat send contend for the same token. Phi is now immune (fed by ingress timestamps, not send cadence), but **gossip SENDING still queues behind saturating app traffic on the same socket.** Under sustained data-plane saturation on a hot link, a node's *outbound* gossip to that peer can be delayed → transient false suspicion of an otherwise-healthy busy peer. Not observed at the 1KB/738k baseline; would surface with large payloads + few hot peers. Acceptable for C1; note as a known interaction.

### S4 — Per-message hot path allocation. [minor, acceptable]
`ClusterRef::tell` (line 69): encode → `new MessagePayload` → codec pack → `new Frame` → write. ~3-4 allocations + one msgpack copy + one frame-header concat per message, no per-message O(N). Span/metric overhead when observability is ON: 1 span start/end + 2-3 counter adds, all `safely()`-wrapped. This is a fixed per-message cost, N-independent — fine. `FrameCodec::decodeStream` (line 115) `substr($buffer, 4+len)` per frame *can* be O(n²) if many frames batch in one buffer, but the one-frame-per-link-in-flight invariant (documented line 208-213, buffer capped at maxFrameSize+chunk) keeps it O(1) in practice.

### S5 — Memory growth is bounded everywhere. [strength — no finding]
Verified all per-peer/lifetime structures are capped: phi window 200 floats FIFO via `array_shift` (`PhiAccrualDetector` line 79-81), `processedLeaves` 10k FIFO cap (`ClusterNode` line 970), seen-nonce set time-evicted per `freshnessWindow` (`HandshakeAuthenticator`), dedup map keyed by peer prefix (bounded by N), inboundLinks capped at `maxInboundLinks`=1024. **No structure grows with lifetime-events.** The `array_shift` on the phi window is O(200) per beat — trivial; the listed "deferred" ring-buffer optimisation is genuinely cosmetic.

### S6 — Single-reactor deployment shape is unstated. [docs]
One process = one reactor core. Data-plane throughput and all control processing (gossip, phi, membership actor) share it. The obvious answer is process-per-core with the cluster mesh across processes, but **no deployment guidance states this.** Given worker-pool-swoole exists for intra-machine thread scaling, the intended composition (threads within a node, cluster across nodes) should be written down.

## Architecture

### A1 — Contract package is genuinely transport-agnostic. [strength]
`nexus-cluster` requires only `nexus-actors/core` (composer verified) and contains pure contracts: `ClusterTransport::send(NodeAddress, string)`, `NodeDirectory`, `NodeHashRing`, `NodeAddress`. Nothing Swoole/TCP-specific leaks in. A `nexus-cluster-quic` could implement `ClusterTransport` unchanged. Deptrac honest: `Cluster → [Core]` only; `ClusterTcp → [Cluster, Core, Observability, Runtime, RuntimeSwoole, Serialization, SerializationMsgpack]`. Correct layering, Core stays foundational.

### A2 — Runtime coupling is behind the right seam. [strength, minor caveat]
`MeshTransport` (serve/connect/close returning `PeerLink`) is the abstraction boundary; `LoopbackMeshTransport` and `SwooleMeshTransport` are peers. `MeshOutboundSink`/`PeerConnection`/`MembershipActor` depend only on `MeshTransport`+`PeerLink`+`Runtime` — no direct Swoole types. The loopback impl *proves* the seam holds. Caveat: `ClusterTcp` deptrac-depends on `RuntimeSwoole` directly, and `ClusterNode::boot` auto-selects Swoole — the abstraction is real but the package still ships the concrete Swoole binding rather than splitting it. Fine for one impl; revisit when a second transport lands.

### A3 — worker-pool vs cluster composition is coherent but undocumented. [nit]
Clean conceptual split: `WorkerActorRef` (intra-machine, cross-thread, no serializer) vs `ClusterRef` (inter-machine, msgpack frames). Both are `ActorRef<T>` — location transparency holds end to end. `NodeHashRing` exists in contracts (mirrors WorkerPool's `ConsistentHashRing`, 150 vnodes) so the EntityRef/sharding future path is viable on this foundation. But the two-level story (threads within node, nodes across cluster) lives only in my head and CLAUDE.md — not in the package README.

### A4 — Failure-domain layering is clean. [strength]
Escalation is well-separated and one-directional: transport (`PeerLink::onClose` → `PeerLinkClosed`) → membership (`MembershipService::applyLinkClosed` → Suspect/Connection; phi/silence → Suspect; give-up window → Down) → application (PSR-14 `NodeUp/Down/Suspected/PeerConnected/Disconnected`). Pure transition functions in `MembershipService`, I/O only in the actor + interpreter. `MembershipEventDeduplicator` correctly gates *publication* without touching the view transition (line 244). No tangling.

---

## Practical limits (expectations)

| N    | Sockets/node | Gossip frame | Assessment |
|------|--------------|--------------|------------|
| 16   | ~30          | ~16 members  | Validated: 738k msg/s, flat suspicion, down=0. Comfortable. |
| 50   | ~100         | ~50 (~3KB)   | Expected fine. Boot herd noticeable; full-list gossip still cheap. Within default fd limits. |
| 100  | ~200         | ~100 (~7KB)  | Full-list gossip starts costing reactor time; delta-gossip advisable. fd/coroutine count climbing but OK. Recommend raising `ulimit`. |
| 500  | ~1000        | ~500 (~35KB) | **Not recommended on this design.** N² sockets + N² gossip bytes + O(N) recv-coroutines/node push against default fd limits and reactor budget. Needs delta-gossip + partial-mesh/seed-tiering first. |

## Strengths (summary)
- Bounded memory everywhere — no lifetime-event leaks (S5).
- Contract package truly transport-agnostic; deptrac honest (A1).
- `MeshTransport` seam correct, proven by loopback impl (A2).
- Clean, one-directional failure-domain escalation with pure transition core (A4).
- Correct incarnation/refutation + dedup design (prior memory confirms this was hard-won).

## Recommended before merge (non-blocking)
1. Expand README: state the tested N ceiling (~16, comfortable to ~50), full-mesh O(N²) fd cost + `ulimit` guidance, single-reactor process-per-core deployment shape, and that delta-gossip is required past ~N=100. (Addresses S1, S2, S6, A3 — all doc gaps, not code.)
