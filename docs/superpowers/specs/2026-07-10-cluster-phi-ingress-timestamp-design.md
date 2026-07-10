# Design: receive-time heartbeat timestamping for the phi failure detector

**Date:** 2026-07-10
**Package:** `nexus-cluster-tcp`
**Audit finding:** 1.A (control/data-plane separation) — reframed after code-grounded diagnosis
**Status:** approved for implementation

## Problem

Under data-plane saturation the phi-accrual failure detector raises false
`Suspect` events against perfectly healthy peers. The 16-node distributed
mesh-soak at **default** phi shows ~210 suspicion events per node (gossip ~190,
phi ~15) while every peer is alive and reachable. The audit labelled this
"control/data-plane separation" and hypothesised a dedicated control connection
as the fix. Grounding that hypothesis in the code disproved it.

### Confirmed mechanism (root cause)

The detector measures **local scheduler latency, not the network.** The arrival
timestamp fed to the phi window is stamped when the `MembershipActor` coroutine
is *scheduled to process* a queued liveness message — not when the bytes landed
on the socket:

1. Bytes arrive → `SwoolePeerLink` recv loop decodes a frame → dispatches it
   synchronously in the recv coroutine.
2. `ClusterNode::observeLiveness()` does `membershipRef->tell(new
   PeerLivenessObserved($peerAddr, null))` — the message carries **no
   timestamp**.
3. The message waits in the `MembershipActor` mailbox until that actor's
   coroutine wins CPU.
4. `MembershipActor::handle()` stamps `$now = $this->clock->now()` at
   **processing time** and feeds it to the single detector call site
   (`MembershipService.php:416`, `$detector->heartbeat($key, $now)`).

Under load the `MembershipActor` coroutine competes for the single reactor with
saturated data-plane actor coroutines, so step 4 fires late and jittery. The
detector's inter-arrival window is poisoned by that jitter (bursty catch-up
dequeues followed by long CPU-starvation gaps), inflating both the window
variance and the measured `elapsed`, so phi crosses the 8.0 threshold on live
peers.

### Why the audit's "dedicated control connection" is the wrong fix here

- A second socket targets **wire** head-of-line blocking (a large in-flight
  data frame delaying a control frame on the same stream). The soak payload is
  **1 KB** — wire HOL is negligible at that size.
- The control frame would still land in the same `MembershipActor` mailbox on
  the same saturated reactor, so the processing-time jitter — the actual cause —
  is unchanged.
- It is a large, high-risk change (doubled connection lifecycle, handshake
  demux, reconnection, DoS caps) solving a failure mode this workload does not
  exhibit.

It stays a documented, **evidence-gated future item**: build it only if a
*large-payload* soak later demonstrates genuine wire HOL.

## Goal

Make failure detection immune to local scheduler contention by feeding the phi
detector the **true socket-receive time** of each liveness signal, so phi
measures the wire rather than local CPU load.

## Approach: ingress heartbeat timestamping

Stamp the arrival timestamp in the recv coroutine at frame ingress, carry it on
the liveness message, and feed *that* timestamp to the detector. All view/status
logic keeps using the processing-time `$now`; only the failure-detector feed
changes.

### Components touched

1. **`PeerLivenessObserved`** (`Membership/Message/PeerLivenessObserved.php`) —
   add a `DateTimeImmutable $observedAt` field. It already implements
   `UntracedMessage`; stays `final readonly`.

2. **`ClusterNode::observeLiveness()`** (~line 519) — stamp `observedAt` at
   ingress. The method already runs in the recv coroutine and already consults
   the `LivenessThrottle`, so this is the true arrival moment. Use the system
   clock the membership actor already uses:
   `new PeerLivenessObserved($peerAddr, null, $this->system->clock()->now())`.
   (Confirm during implementation whether to cache a `ClockInterface` field for
   clarity vs. calling `$this->system->clock()` inline; either is acceptable —
   no new constructor dependency is required.)

3. **`MembershipService::applyLiveness()`** — add a `DateTimeImmutable
   $observedAt` parameter, thread it into `recordLiveness()`, and use it **only**
   at the `$detector->heartbeat($key, $observedAt)` call (currently line 416).
   The recovery / `lastSeen` / view-status logic continues to use `$now`.

4. **`MembershipActor::handle()`** — pass `$message->observedAt` into
   `applyLiveness(...)` for the `PeerLivenessObserved` branch.

### Deliberately out of scope

- **`HandshakeReceived` / `applyHandshake` detector feed.** The handshake is the
  detector's first sample and fires once at join, not on the under-load steady-
  state hot path. Leaving it on processing-time is harmless for the false-
  positive-under-load problem. If a follow-up wants a fully consistent detector
  clock it is a trivial extension, but it is not part of this fix.
- **`GossipReceived` / `applyGossip`.** Does not feed the detector (verified —
  `applyGossip` takes no `PhiAccrualDetector`); the liveness path is the sole
  heartbeat signal.
- **Monotonic phi clock (audit #7).** Separate concern. This fix keeps the
  existing wall-clock; it only changes *when* the timestamp is taken, not which
  clock. Ingress and tick already read the same `ClockInterface`.
- **Dedicated control connection (original 1.A).** Deferred, evidence-gated as
  described above.

## Data flow (after change)

```
socket bytes
  → SwoolePeerLink recv loop (decode frame)
    → ClusterNode::observeLiveness()  ── stamp observedAt = clock->now()  [RECV TIME]
      → tell(PeerLivenessObserved(peer, null, observedAt))
        → MembershipActor mailbox (may wait under load)
          → MembershipActor::handle()  ── $now = clock->now()  [PROCESSING TIME]
            → applyLiveness(view, …, $observedAt, $now)
              → recordLiveness: detector->heartbeat(key, observedAt)  ← detection uses RECV time
                                view-status/recovery uses $now         ← unchanged
```

## Error handling / edge cases

- `observedAt` is always populated at ingress (never null) — it is a required
  constructor arg, so no null-branch is introduced downstream.
- Clock consistency: ingress and tick both read the same `ClockInterface`
  instance (`$system->clock()`), so `elapsed = now - observedAt` is well-formed
  and non-negative in normal operation.
- Backward compatibility: `PeerLivenessObserved` is an internal message
  (constructed only in `ClusterNode`); no serialized/wire representation exists,
  so adding a field is safe.

## Testing

**Unit (necessary, not sufficient):**
- New `MembershipServiceTest`: call `applyLiveness` with an `observedAt` far in
  the past and a processing `$now` well ahead of it; assert the detector's phi
  is computed from `observedAt` (i.e. a fresh `observedAt` keeps phi low even
  when `$now` is late), and conversely that a stale `observedAt` is what drives
  phi — proving the detector feed no longer depends on processing time.
- `PeerLivenessObserved` construction test for the new field.
- Full existing cluster-tcp unit + Swoole suites stay green (240 unit + 46
  Swoole baseline).

**Acceptance (the real bar):**
- 16-node distributed mesh-soak at **default** phi (no `MESH_PHI_TUNING`).
- Pass criteria: suspected/node collapses from ~210 into a low single-digit /
  near-zero range **and `down = 0`** across all nodes, with no throughput
  regression. Unit-green alone does **not** close this finding — the soak is
  authoritative, per the lesson from the reverted #1 attempt.

## Risks

- If the soak does *not* collapse suspicion, the mechanism diagnosis is
  incomplete (e.g. the recv coroutine is itself CPU-starved so ingress stamps
  are also late). Mitigation: the soak health line already breaks suspicion into
  conn/gossip/phi; a residual phi count after the fix tells us whether ingress
  stamping is being starved, pointing back to B (control lane) with evidence.
- Low code risk: additive field + one threaded parameter, no control-flow
  change to view evolution.
```
