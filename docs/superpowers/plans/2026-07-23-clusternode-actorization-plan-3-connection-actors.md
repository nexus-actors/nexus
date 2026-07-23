# ClusterNode Actorization — Plan 3: Connection Actors + SEC-008

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close SEC-008 (five control-frame authorization checks, self-attesting Leave), then convert the connection edge into the spec's actor topology: `ConnectionSupervisor` + `RoutingSnapshot`, `InboundLinkActor` (Unidentified→Identified become), `OutboundPeerActor` (mailbox-as-send-queue, internal reconnect) — soak-gated at every step.

**Architecture:** SEC-008 lands FIRST on the current stable structure (checks then travel with the code into actors). Actorization then proceeds inside-out: supervisor+snapshot (state ownership), inbound link actors (admission), outbound peer actors (egress). Spec §3.1/§4.1–4.3/§6 as amended 2026-07-23 (internal-reconnect correction; SEC-008 five checks).

**Tech Stack:** PHP 8.5 (Docker only), PHPUnit 13, Psalm level 1 zero-suppressions, GrumPHP per commit.

## Global Constraints

- Docker-only commands; GrumPHP green per commit; never `@psalm-suppress`; soak per link-lifecycle change judged by PER-NODE verdicts (aggregate line unreliable — known teardown hang).
- Wire strings stay bare `host:port`; frozen codec keys — ADDING optional keys with default-tolerant decode (`nullableString`/`nullableInt`) is the established forward-compat pattern and is permitted for LeavePayload auth fields.
- All 14 constraint-ledger invariants (spec §7) hold throughout; specifically here: C2 (zero pre-identification ingress), C3 (stamp+throttle pre-mailbox where the transport receives), C7 (per-peer isolation), C10 (same-identity supersede), C12 (evict only on authorized graceful Leave), C13 untouched.
- **Framework facts every task must respect** (verified against nexus-core at this branch):
  - become = return a plain `Behavior::receive`/`withState` (wrappers like setup are NOT resolved on become; anything else dead-letters). onSignal handlers do NOT survive become — re-attach on the new behavior.
  - `exponentialBackoff` maxRetries is a LIFETIME cap and `restart()` clears the suspend buffer without dead-lettering — hence reconnect is INTERNAL (scheduleOnce state machine), supervision is crash containment only; supervisor `watch`es children and respawns on `Terminated` (dead child names are pruned and reusable; `ActorNameExistsException` only for live duplicates).
  - Supervision sees ONLY user-message handler throws; setup/PreStart throws do NOT engage it (setup throw at first spawn propagates to the SPAWNER). Never do socket work in setup/PreStart — child setup runs synchronously in the spawner's coroutine; self-tell a message instead.
  - Bounded mailboxes: use `Props::withBoundedMailbox($cap)` (defaults DropNewest); raw `MailboxConfig::bounded()` defaults ThrowException — never use it bare. `LocalActorRef::tell` dead-letters Dropped; use `offer(): EnqueueResult` where the caller needs the outcome (DeliveryOutcome mapping).
  - `setReceiveTimeout` resets on user messages only; the timeout signal fires OUTSIDE the mailbox loop on Swoole (tolerate interleaving with an in-flight handler).
  - On SwooleRuntime each actor loop is its own coroutine with SWOOLE_HOOK_ALL: a socket write in a handler suspends only that actor (natural per-link write serialization).
- Ordering invariant (from the current admission path): endpoint registration must complete before membership processes `HandshakeReceived`, and egress readers fall back to the accepted inbound link when no route exists — the actorized chain (link actor → supervisor → membership) preserves the first through the supervisor's serialized mailbox; the snapshot-lag window is covered by the existing fallback.
- Branch `refactor/cluster-node-actorization`; controller pushes per task; never commit the pre-existing `.gitignore` working-tree edit.

---

### Task 1: SEC-008 — five control-frame authorization checks (pre-actorization)

**Files:**
- Modify: `packages/nexus-cluster-tcp/src/Payload/LeavePayload.php` (+ optional `?string $nonce = null`, `?int $issuedAt = null`, `?string $mac = null`), `packages/nexus-cluster-tcp/src/Payload/LeavePayloadCodec.php` (pack the three new keys alphabetically among existing; unpack via `nullableString`/`nullableInt` — old frames decode with nulls)
- Modify: `packages/nexus-cluster-tcp/src/Membership/PeerAuthenticator.php` (+ `public function signLeave(LeavePayload $leave): LeavePayload;` `public function verifyLeave(LeavePayload $leave, int $nowUnix): bool;`), `packages/nexus-cluster-tcp/src/Membership/HandshakeAuthenticator.php` (implement both: HMAC-SHA256 over canonical JSON `{issuedAt, node, nonce}`, same freshness window + THE SAME nonce-replay set as handshakes — one set, one confinement)
- Modify: `packages/nexus-cluster-tcp/src/ClusterNode.php` — the five checks (below)
- Modify: `packages/nexus-cluster-tcp/src/Telemetry/ConnectionMetrics.php` (+ `$controlRejected` counter `nexus.cluster.control.rejected`, unit `{frame}`, attribute `check` ∈ leave_unsigned|leave_replay|reidentify_mismatch|ack_view_authority|gossip_endpoint_authority — documented in Plan 5's docs task)
- Tests: extend `tests/Unit/Payload/*` (codec round-trip old+new form), `tests/Unit/Membership/HandshakeAuthenticatorTest.php` (signLeave/verifyLeave, replay, freshness), `tests/Integration/ClusterTcp/ClusterNodeTest.php` (forged-Leave rejected + relay of SIGNED Leave still works; re-identify mismatch closes/rejects; gossip cannot overwrite verified endpoint)

**The five checks (each with its exact insertion point — verify by symbol):**
1. `processLeaveFrame`: when `$this->authenticator !== null`, reject unsigned or `!verifyLeave($payload, time())` Leaves → count `leave_unsigned`/`leave_replay`, return (no tombstone, no relay, no evict, no membership tell). Signed relayed frames still verify (mac is leaver-bound, link-independent). `ClusterNode::shutdown`'s Leave broadcast signs via `$this->authenticator?->signLeave(...)` before packing.
2. `handleLinkFrame` Handshake branch: if `$state->peerAddr !== null` and the parsed identity's `toPathPrefix()` differs → reject, count `reidentify_mismatch`, do NOT rebind, do NOT feed liveness; same-prefix re-handshake proceeds unchanged (C10).
3. `applyHandshakeAckView`: skip entries whose prefix is tombstoned; for the entry equal to the SENDER's own prefix, require the endpoint string to equal the link's HMAC-bound advertise (`$state`-held from admission) else skip + count `ack_view_authority`.
4. `processGossipFrame`: for a member entry naming the sender's own prefix, same advertise equality else skip registration + count `gossip_endpoint_authority`; for third-party entries, register ONLY if the prefix has no handshake-verified endpoint (track verified prefixes in a bounded set alongside the registry — same 10k FIFO discipline). View merge/`$liveMembers` forwarding UNCHANGED.
5. Rejected frames must not reach `observeLiveness` — audit each check's return path.

Store the HMAC-bound advertise on `LinkState` (`public ?string $boundAdvertise = null`, set at identification) so checks 3–4 need no map lookups.

- [ ] TDD: failing tests per check → implement → suites green (`make test-unit test-fiber test-swoole test-cluster`).
- [ ] Soak both harnesses (per-node verdicts).
- [ ] Commit: `fix(cluster-tcp): close SEC-008 — self-attesting Leave + control-frame authorization` — push handled by controller.

### Task 2: `RoutingSnapshot` + `ConnectionSupervisor` actor

**Files:** Create `packages/nexus-cluster-tcp/src/Connection/{RoutingSnapshot.php, RoutingSnapshotHolder.php, ConnectionSupervisor.php, Message/*.php}`; Modify `ClusterNode.php` (state ownership migrates), tests under `tests/Unit/Connection/` (StepRuntime) + integration adaptation.

- `RoutingSnapshot`: `final readonly` — endpoint map (prefix→NodeEndpoint), tombstone set, verified-prefix set, accepted-link directory (prefix→PeerLink), generation int. `RoutingSnapshotHolder`: mutable single-slot holder with `current(): RoutingSnapshot` / `publish(RoutingSnapshot): void` (cooperative-scheduling safe; readers: egress path, admission checks).
- `ConnectionSupervisor` (spawned by ClusterNode boot as `cluster-connections`): `Behavior::setup` + `withState`; owns ALL mutations previously on ClusterNode: `RegisterIdentifiedLink(peerAddr, endpoint, boundAdvertise, link)` (registry write + C10 supersede + tombstone clear + verified-set add + publish + `membershipRef->tell(HandshakeReceived...)` — the ordering invariant rides its serialized mailbox), `LinkClosed(...)` (identity-guarded slot removal, tombstone, throttle-forget, PeerLinkClosed, PeerDisconnected, failAllForNode), `RecordTombstone/ClearTombstone`, `EvictPeer(addr)` (pool evict — Leave only), `LinkReport` ask (replaces the ReflectionProperty test seams — spec §8.4).
- ClusterNode's `$acceptedLinks`/`$departedTombstones`/registry writes delegate to supervisor tells; READS (sendByPrefix, admission) go through the holder's snapshot with the existing inbound-link fallback absorbing snapshot lag. `MutableEndpointRegistry` becomes supervisor-internal.
- [ ] StepRuntime unit tests for every message; integration parity; soak; commit `refactor(cluster-tcp): ConnectionSupervisor owns routing state behind RoutingSnapshot`.

### Task 3: `InboundLinkActor`

**Files:** Create `packages/nexus-cluster-tcp/src/Connection/InboundLinkActor.php` (behavior factory class); Modify `Transport/InboundLinkAcceptor.php` (spawns actors via injected spawner closure instead of wiring closures), `ClusterNode.php`; tests StepRuntime + integration.

- Unidentified behavior (`Props::withBoundedMailbox(1024)`, setup arms `setReceiveTimeout(handshakeTimeout)`): ONLY `FrameReceived(Frame, ingressStamp)` with `FrameType::Handshake` runs admission (moved verbatim: parse → cluster/protocol → HMAC → identity → SEC-008 checks) → on success tell supervisor `RegisterIdentifiedLink`, reply `HandshakeAck`, **become Identified** (plain `Behavior::receive` + re-attached onSignal for PostStop/ReceiveTimeout-cancel via `setReceiveTimeout(null)`); anything else → silent (C2: no ingress structurally). `ReceiveTimeout` signal → close link + `Behavior::stopped()`. PostStop closes the link.
- Identified behavior: the `handleLinkFrame` non-handshake branches move here verbatim (ack-view→supervisor tells, gossip→membership + liveness, Leave→verify + supervisor tombstone/evict + relay via egress, Message→FrameIngress). Ingress stamp + LivenessThrottle REMAIN in the pump (acceptor's frame callback stamps + throttles BEFORE `offer()`ing to the actor — C3).
- Acceptor: capacity gate stays; spawns actor per link (name `in-<n>`), `offer()`s frames (Dropped → link close: pre-auth flood bound), watches via supervisor.
- [ ] StepRuntime tests: admission paths, become, timeout-stop, C2; integration + soak; commit `refactor(cluster-tcp): inbound links are actors (Unidentified→Identified become)`.

### Task 4: `OutboundPeerActor`

**Files:** Create `packages/nexus-cluster-tcp/src/Connection/OutboundPeerActor.php`; Modify `ClusterNode.php` egress (`sendByPrefix`→snapshot resolve→`offer()` to peer actor; `DeliveryOutcome` mapping: link-up+Accepted→Admitted, connecting+Accepted→Buffered, Dropped/no-route/tombstoned/stopping→Dropped; socket-write failures post-admission → `$socketWriteFailed` counter), supervisor spawns/watches/respawns (`peer-<prefix>`); `Transport/PeerConnectionPool` retired from the hot path (kept only if tests still exercise it, else deleted with its unit test).
- Behavior (`Props::withBoundedMailbox(100)` — the send queue): internal state machine {Disconnected, Connecting, Connected} with `Connect` self-message from setup, `scheduleOnce` backoff retries (initial→doubling→cap, reset after established — exact `PeerConnection` semantics incl. preamble-then-flush: on connect, send preamble FIRST, then drain the mailbox-queued frames in FIFO), `SendFrame` messages queue naturally while not Connected (mailbox IS the buffer), `IntentionalClose` → stopped. Supervision: `oneForOne` modest retries for crashes; supervisor respawns on Terminated unless intentionally stopped.
- The inbound pump for seed-dialed links: frames from the outbound socket go to an InboundLinkActor-equivalent identified flow (outbound links are pre-identified by the dial target — preserve today's no-op accepted-callback semantics).
- [ ] StepRuntime tests: queue-while-connecting, flush-order-after-preamble, backoff reset, intentional close, DeliveryOutcome mapping; integration + BOTH soaks + `make bench-saturation` (≥90% of 995k baseline — egress hop is hot-path); commit `refactor(cluster-tcp): outbound peers are actors with mailbox send queues`.

### Task 5: Plan-3 verification
- [ ] Full gates + boundary check + deptrac; both soaks; saturation; `.superpowers/sdd/plan3-verification.md`; PR body update by controller. Delete any psalm.xml seam exemptions whose classes gained src consumers this plan.

## Self-review notes
- Spec coverage: §4.2 checks 1–5 → T1; §3.1 supervisor/snapshot + §8.4 LinkReport → T2; §3.1 link actors + C2/C10 → T3; §3.1/§4.3 peer actors + amended §6 → T4.
- The plan deliberately sequences SEC-008 before actorization so security diffs are reviewable on stable code and travel with moved blocks.
- Deferred within plan: guardian/facade (Plan 5), membership persistence (Plan 4), ask registry actor (Plan 5).
