# PR Review — Algorithm & Logic Dimension (`feat/cluster-tcp`)

**Verdict: approve-with-nits**

The distributed-systems core is sound and reflects hard-won soak-test learnings (echo dedup, incarnation refutation, quorum floor, min-sample guard). No correctness defect that produces oscillation, a stuck view, or an ask/frame corruption. Findings are one low-severity processing-time leak and several accepted AP tradeoffs worth documenting.

Counts: 0 blocker · 0 major · 1 minor · 3 nits · 7 verified-sound.

---

## Findings

### MINOR — Handshake feeds the phi detector at processing time, not ingress time
`MembershipService::applyHandshake` (packages/nexus-cluster-tcp/src/Membership/MembershipService.php:122-129) calls `recordLiveness(..., $detector, $peer, $endpoint, $now, $now)`, i.e. `observedAt = $now`. That `$now` is captured in `MembershipActor::handle` (MembershipActor.php:147) at **actor-processing time**, then flows into `PhiAccrualDetector::heartbeat($key, $now)` (MembershipService.php:420).

- **Invariant (Hayashibara):** the detector must be fed inter-arrival **ingress** timestamps; processing-queue latency must not enter the window.
- **Assessment:** The steady-state liveness path is clean — `observeLiveness` (ClusterNode.php:555-557) stamps `clock()->now()` inside the recv-coroutine `onFrame` callback and carries it as `PeerLivenessObserved::observedAt`, which `applyLiveness` feeds to the detector. Gossip does NOT feed the detector (correct; liveness is decoupled via `observeLiveness` after `processGossipFrame`). Only the **handshake** path leaks processing time.
- **Counterexample (bounded):** Peer reconnects after a transient; its re-Handshake sits behind a backed-up membership mailbox for, say, 300 ms. The first inter-arrival interval recorded post-reconnect is inflated by that queue delay, nudging the fitted mean/stddev. Impact is small — handshakes are rare (boot + reconnect), the very first handshake records no interval (only sets `lastArrivalMs`), and `MIN_SAMPLE_INTERVAL_MS`/`minStdDev` floors absorb most of it. Still, it is a genuine residual processing-time leak; the prompt asked to confirm none remain, and this one does.
- **Fix:** thread an ingress `observedAt` into `HandshakeReceived` (as `PeerLivenessObserved` already does) and pass it, not `$now`, to `recordLiveness`.

### NIT — `pickWinner` uses `>=` on equal incarnation+status, biasing toward incoming
ClusterView.php:132 returns `incoming` when `incoming->lastSeen >= current->lastSeen`. On a full three-way tie (same incarnation, same status, same lastSeen) the incoming record wins. This is idempotent for value-equal records and cannot cause oscillation (the semilattice join is still commutative up to lastSeen, which is the tiebreak), so it is not a convergence defect — but the `>=` makes the merge not strictly order-independent when two records share a timestamp but differ in endpoint. Practically harmless; note it.

### NIT — `RandomPeerSelector` returns the live input array reference when `count >= total`
RandomPeerSelector.php:33-35 returns `$peers` directly (not a copy). Callers in `buildGossipEffects` pass a freshly-built `$candidates` list, so no aliasing bug today, but returning the caller's array unchanged is a latent footgun if a future caller mutates the result. Cosmetic.

### NIT — `processedLeaves` FIFO evicts by insertion order, not recency
`ClusterNode::processLeaveFrame` (ClusterNode.php:976-980) `array_shift`s the oldest entry at capacity. Unlike `MembershipEventDeduplicator::startSlate` (which re-inserts for recency), a still-relevant Leave prefix could be evicted while a stale one survives. Worst case is one redundant `LeaveReceived` re-delivery (idempotent — `applyLeave` on an absent node is a no-op), matching the documented tolerance. Accept.

---

## Verified-sound

1. **Phi-accrual math** — Direct A&S 26.2.17 normal tail (avoids `1-CDF` cancellation), `minStdDev` floor prevents phi-explosion on near-periodic arrivals, `MIN_SAMPLE_INTERVAL_MS=50` correctly drops the microsecond mutual-seed duplicate interval while still refreshing `lastArrivalMs`. Absolute-silence fallback (`millisSinceLastHeartbeat` + `downAfter`, MembershipService.php:358-359) correctly covers the empty-window (handshake-once-then-silent) case that phi alone can't detect. Sound.

2. **SWIM membership + refutation** — `applyGossip` detects peer-asserted self-suspicion via raw payload scan (`peerAssertedSelfSuspicion`, self excluded from `gossipToView`), then floors the incarnation bump to `max(selfIncarnation, suspectedAt)+1` via `applyRejoin` so the refutation deterministically wins `pickWinner`. Self record only mutated here or at startup. The known/accepted equal-incarnation `Suspect > Up` (rank 2 > 1) is the intended escape-hatch, resolved by refutation. No oscillation or stuck-state path found: merge is monotone in (incarnation, status-rank, lastSeen), and refutation strictly raises incarnation, guaranteeing forward progress.

3. **Gossip dissemination + echo control** — `buildGossipEffects` includes Suspect targets (so a universally-suspected node still receives the gossip that triggers its refutation — the fix for the 16-node flapping). `MembershipEventDeduplicator` filters PUBLISHED events (never view transitions): older incarnation suppressed, higher always resets slate, same-incarnation status-change always published (recovery NodeUp never lost), post-Down churn quieted for `downQuietPeriod`. `startSlate` re-inserts for FIFO-by-recency. Closes the one-transient→N×-events amplification. Sound.

4. **Quorum floor / degraded mode** — `applyTick` gates only the destructive `withoutNode`+NodeDown when `reachable < minimumMembers`; Suspect transitions still happen (local observation preserved), and `ClusterDegraded` is emitted. A minority partition cannot evict the majority. No permanent wedge: the floor is re-evaluated every tick against live `upNodes()`, so once liveness heals `reachable` recovers and Down resumes — degraded mode is level-triggered, not latched.

5. **Ask correlation (`TcpAskRegistry`)** — Capacity check before insert (`AskCapacityExceededException`); first-reply-wins via `unset` before `resolve` (idempotent — late/dup correlation returns false); `remove()` is the single teardown for timeout, symmetric with `resolve`. Timeout↔reply race is safe: whichever runs first `unset`s the slot; the loser sees `!isset` and no-ops. `failAllForNode` (link-close and phi-Down via `AskFailingMembershipEventPublisher`) covers the half-open/black-hole case where no EOF fires. `InboxRouter::isValidAskReplyPath` prefix-guards the peer-supplied replyPath (`origin-prefix + /temp/remote-ask-`) before minting `ClusterReplyRef`, preventing a hostile path from crashing routing. Sound.

6. **Framing (`FrameCodec`)** — Length-prefix decode is correct at all boundaries: `<4` bytes → partial (`rest`), `>maxFrameSize` → throw before buffering body (bounds reassembly memory), `<1` → throw, `<4+bodyLength` → partial, unknown type byte → throw, empty payload handled. `unpack('Nlength', $buffer)` reads only the leading 4 bytes regardless of buffer length; `substr` offsets (5, `bodyLength-1`) and consume (`4+bodyLength`) are exact. Incremental reassembly across reads is correct. Sound.

7. **Link lifecycle (reworked path)** — Non-closing re-handshake replace: new inbound link overwrites `acceptedLinks[$prefix]`; prior link's `onClose` is guarded (`(acceptedLinks[$prefix] ?? null) === $link`) so it cannot unset the newer slot. Leak is bounded by `maxInboundLinks` + `handshakeTimeout` slowloris guard (unidentified links closed on deadline, `inboundLinks` counter decremented in both onClose and the timeout). A peer re-handshaking while holding old links open is capped at `maxInboundLinks` live sockets, then refused — DoS-bounded. Leave-only outbound eviction: `evictOutbound` fires only on graceful Leave; a crashed peer that never sends Leave keeps its `PeerConnection` reconnect loop running forever — this is the **intended** AP tradeoff (a phi-Down may be a false positive; keeping the bounded exponential-backoff reconnect lets the peer heal back to Up). The reconnect loop is bounded by `reconnectMaxBackoff`, so "hammers forever" is really "retries at max-backoff cadence forever," which is acceptable and correct for an AP cluster.
