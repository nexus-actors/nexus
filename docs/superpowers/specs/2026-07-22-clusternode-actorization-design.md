# ClusterNode Actorization — Design

- **Date:** 2026-07-22
- **Status:** Approved. Plan series roadmap + Plan 1 written
  (`docs/superpowers/plans/`). Prerequisite audit stack #79–#105 **landed on
  `main` 2026-07-22** (rebase-merge, tree verified byte-identical to the
  CI-green chain tip) — all constraints C6/C14 and the framework fixes the
  design relies on (REL-002/003/004, OPS-001, DSL-001/004, SEC-004) are now
  on `main`.
- **Package:** `nexus-cluster-tcp`
- **Supersedes:** the monolithic `ClusterNode` service (`src/ClusterNode.php`, 1485 lines)
- **Companion docs (to be written during implementation):** ADR `docs/adr/0009-actorized-cluster-node.md`, updated `website/docs/packages/cluster-tcp.md`

## 1. Context & Goals

`ClusterNode` is the boot, wiring, and runtime hub of the TCP cluster: it owns all
link registries, handshake admission, frame routing, seed dialing, tombstones,
shutdown, and telemetry in one 1485-line stateful class. Its mutable state is
touched re-entrantly from recv-loop closures, timers, membership effects, and the
shutdown caller — confined only by cooperative scheduling, never by actor
discipline. The membership subsystem (`MembershipActor` + pure
`MembershipService`) is already cleanly actorized and serves as the exemplar.

Goals of the refactor, in priority order:

1. **Actorize the node:** connection lifecycle, admission, and routing become
   actors whose lifecycle *is* the connection lifecycle. State is owned, not shared.
2. **Event-sourced cluster state:** the membership actor becomes a real
   `EventSourcedBehavior` with a journal of membership facts and safe replay.
3. **Showcase Nexus:** become/behavior switching, `ReceiveTimeout`, supervision
   strategies as reconnect policy, watch/`Terminated`, ask, bounded mailboxes as
   backpressure, `StepRuntime` testability, persistence — used where each is the
   *natural* tool, not decoratively.
4. **Preserve every soak-won behavior** (§7 constraint ledger) and the tuned
   hot-path performance (§8 gates).
5. **Absorb SEC-008** (node identity authorization) as a first-class property of
   the new admission design.

## 2. Decisions

| # | Decision | Choice | Notes |
|---|----------|--------|-------|
| D1 | Migration posture | **Greenfield redesign inside `nexus-cluster-tcp`** | Not in production; no BC constraints. Protocol behavior and soak-won fixes preserved; tests adapted to the new API keep asserting the same behavior. |
| D2 | Persistence role | **Full event sourcing with redesigned fact-events + safe replay** | See §2.1 for the recorded dissent and its resolution. |
| D3 | Byte→actor boundary | **Actors from the frame up** | Thin coroutine pumps do socket reads + incremental framing; complete typed frames enter link-actor mailboxes. One extra mailbox hop on ingress, benchmark-gated. |
| D4 | SEC-008 | **Absorbed into the redesign** | Identity bound into the HMAC handshake; control frames validated against the link's authenticated identity. Delivered via Plan 3 of the series; the `fix/audit-sec-008-node-identity` branch was dropped (no commits) when the audit stack landed. |
| D5 | Topology | **Per-connection supervision tree** (§3) | Per-peer isolation preserved; a coarse single-manager actor was rejected because one inbound funnel re-introduces cross-peer head-of-line blocking. |
| D6 | Multi-transport | **Transport seam now, TCP first; HTTP and Queue as follow-ups** (§3.4) | The actor core (membership+journal, routing, peer actors, asks) becomes transport-neutral behind an explicit SPI with pluggable wire formats. TCP is reimplemented on the seam as the proving transport in this refactor. HTTP (point-to-point, JSON/msgpack wire formats) and Queue (brokered, building on nexus-messenger's `StampMessageRouter` cluster seam) each get their own follow-up spec on the frozen SPI. |

### 2.1 Recorded dissent: event-sourcing cluster state

The deep-dive analysis recommended *against* full event sourcing: today's
membership events are deduplicated notifications that cannot rebuild state;
membership is convergent soft state re-taught by gossip in seconds; and the
persistence engine has real frictions (spawn-blocking recovery, no snapshot
pruning, writerId defaults unsuitable for restarts). The load-bearing durable
subset is small: own incarnation + departed-peer tombstones.

**Resolution (decided with this dissent on the table):** keep full event
sourcing, but on redesigned terms that answer each objection:

- **New fact-events** (§5) carry incarnations and timestamps and *can* rebuild
  state — the notification events remain a separate, unchanged PSR-14 concern.
- **Write rate is churn-proportional**, not message-rate-proportional: a
  converged cluster journals ~nothing. The message hot path never touches the
  journal.
- **Safe replay** (§5): replayed liveness is never trusted — only dial-hints,
  incarnation floor, and tombstones are.
- **writerId is pinned** to the stable SEC-008 node identity, making
  `ReplayFilterMode::Fail` correct: a second writer on one journal means two
  nodes claim the same identity.

What full ES buys over the minimal subset: **seed-independent rejoin** (a
restarted node dials remembered peers even when seeds are down) and a
**persistent membership audit trail** — directly valuable given this project's
history of multi-week flapping investigations. Accepted costs: a DBAL/SQLite
store for real durability, spawn-blocking recovery once at boot (bounded by
snapshots), and slow-but-unbounded journal growth until framework snapshot
pruning lands.

## 3. Architecture

```
/user/cluster                ClusterGuardian (setup + wiring + shutdown protocol)
├── membership               MembershipActor as EventSourcedBehavior
│                            pure MembershipService core (unchanged semantics)
│                            journal: membership fact-events (§5)
├── connections              ConnectionSupervisor
│   │                        owns link directory + endpoint registry + tombstone
│   │                        projection; publishes immutable RoutingSnapshot
│   ├── in-<addr>            InboundLinkActor   (one per accepted socket)
│   │                        Unidentified → Identified via become
│   │                        Slowloris deadline = ReceiveTimeout
│   └── peer-<node>          OutboundPeerActor  (one per peer)
│                            mailbox = send queue: bounded(100, DropNewest)
│                            reconnect = exponentialBackoff supervision
└── asks                     AskRegistryActor   (correlation, timeouts, capacity)
```

### 3.1 Actors

**ClusterGuardian** — `Behavior::setup` composition root. Builds pure services
(codecs, serializer, `HandshakeAuthenticator`, telemetry helper), spawns the
three children, orchestrates shutdown (§4.7). The monolith's `&$selfNode`
lazy-sender closure dissolves: an `ActorRef` is a stable identity before
behavior runs, so the effect interpreter is wired with real refs.

**MembershipActor** — existing pure functional core, wrapped in
`EventSourcedBehavior`. Commands: `GossipReceived`, `HeartbeatObserved`,
`HandshakeReceived`, `LeaveReceived`, `PeerLinkClosed`, `Tick`, `QueryView`
(ask). Supervision restart replays the journal and recreates ephemeral trackers
(phi windows, dedup, selector) in setup — fixing today's asymmetric partial
reset where `MembershipState` reset while tracker instances survived.

**ConnectionSupervisor** — owns all connection-level state the monolith held in
five maps. Enforces `maxInboundLinks` at accept; implements re-handshake
supersede (replace slot, never close the prior link); dials seeds and
journal-recovered dial-hints on start; `watch`es all children and converts
`Terminated` into directory cleanup + `FailAllFor(node)` + `PeerDisconnected`.
Publishes **`RoutingSnapshot`**, an immutable `readonly` object atomically
swapped on every directory/tombstone/endpoint change and read lock-free by
egress and admission (safe under cooperative single-threaded scheduling).

**InboundLinkActor** — per accepted socket. Starts `Unidentified`: the behavior
accepts *only* `Handshake` frames; anything else closes the link. Admission
(§4.2) runs here; success → supervisor registration → `become(Identified)`,
which is where gossip/message/reply handling lives. Pre-auth ingress is
structurally impossible: the unidentified behavior has no ingress wiring to
reach, and mailbox FIFO guarantees no frame overtakes the handshake that
preceded it on the wire. Bounded mailbox (cap 1024, close on overflow) carries
over the pendingFrames flood bound for pre-auth peers.

**OutboundPeerActor** — per peer. Owns the dialed link; its bounded mailbox
(100, DropNewest) *is* the send queue, replacing `PeerConnection`'s hand-rolled
one. Reconnect is supervision (§6). Sends the re-signed self-Handshake preamble
in `PreStart` on every (re)connect.

**AskRegistryActor** — single-writer home for ask correlation (today's
`TcpAskRegistry` is mutated from four call sites). Capacity 10k; overflow fails
the future with `AskCapacityExceededException` (unified failed-future semantics
replacing the sync throw). Timeouts via `scheduleOnce`; `FailAllFor(node)` on
peer loss.

### 3.2 Deliberately not actors

| Component | Why |
|---|---|
| Socket pumps | Actors must not block; a thin coroutine per socket does blocking reads + incremental `FrameCodec` and posts typed frames. Stamps the **phi ingress timestamp at socket-receive time** and runs `LivenessThrottle` (≤1/peer/50 ms) *before* the mailbox hop. |
| Codecs/serializers | Pure functions. The hand-rolled `MessagePayloadCodec` (~12 µs of the ~32 µs/msg budget), `ControlFrameCodec`, and all size bounds move verbatim. |
| `RoutingSnapshot` | Immutable read model; egress pays one property read, not an ask round-trip. |
| `LocalDelivery`, `LocalActorRegistry`, `ClusterRef` family | Stay plain services — statelessness is **not** a requirement. `LocalActorRegistry` keeps its mutable `expose()` map (single writer: app wiring), the delivery seams keep their drop counters. An actor hop here would add mailbox latency to every local delivery without adding confinement value; correctness is validated by the Fiber and Swoole integration suites (§8.4), not by a purity constraint. |
| `SwooleMeshTransport` / `SwoolePeerLink`, Loopback transports | The Swoole-coupled edge stays crisp beneath the pumps; recv-timeout≠EOF guard stays put. Known dead-link append leak in `$links`/`$serverLinks` is fixed in passing (supervisor-driven deregistration). |

### 3.3 Package layout & public API

`src/` reorganizes to: `Node/` (guardian, facade, bootstrap, config),
`Connection/` (supervisor, link actors, snapshot), `Membership/`
(core + `Persistence/` events), `Messaging/` (refs, delivery, ask), `Protocol/`
(frames, wire formats, payloads), and the carriers `Transport/Tcp/` (Swoole,
pumps) and `Transport/Loopback/` — boundary rules in §3.4.

Facade API: `boot()`, `expose()`, `refFor()`, `view()` (ask-based; the
double-`yield` hack dies), `queryViewAsync()`, `self()`, `shutdown()`.
Deleted: `receptionist()` stub (C2's future input is the membership view).
Demoted to internal protocol: `sendByPrefix`, `evictOutbound`.

### 3.4 Transport seam (SPI)

The actor core must run unchanged over TCP today and HTTP/Queue tomorrow. The
seam is expressed as four capabilities the core consumes, never as "sockets":

1. **Addressing** — `NodeEndpoint` generalizes to URI-style addresses
   (`tcp://host:port`, later `http(s)://…`, `queue://<broker-channel>`).
2. **Carrier** — outbound `send(peer, frame): DeliveryOutcome` and an inbound
   stream of `(peer, frame, receivedAtIngress)`. Two profiles: the
   **connection-oriented** profile (TCP, HTTP) provides links with
   accept/dial/close, served by `InboundLinkActor`/`OutboundPeerActor` as
   designed; the **brokered** profile (Queue) has no links — per-message
   authentication and an inbox pump replace the preamble, and its precise actor
   shape is decided in the queue transport's own spec. The SPI must not
   preclude it: admission is abstracted as a `PeerAuthenticator` capability,
   not as a handshake frame, and `DeliveryOutcome` is already admission-based,
   which maps cleanly onto broker publish semantics.
3. **Liveness source** — ingress-stamped observations (C3 generalizes: the
   stamp is taken where the transport receives, never after mailbox hops).
4. **Wire format** — the codec family for *protocol payloads* (handshake,
   gossip, leave, message envelope) behind a `WireFormat` interface.
   `MsgpackWireFormat` is **exactly today's hand-rolled, perf-tuned codecs
   renamed into the seam** — zero hot-path change; `JsonWireFormat` lands with
   HTTP for debuggability. User message *bodies* keep their own pluggable
   `MessageSerializer`, orthogonal to wire format. Per-transport
   configuration; negotiation is out of scope until a second format ships.

**Packaging:** this refactor stays in `nexus-cluster-tcp` but draws a hard
internal boundary: `Node/`, `Membership/`, `Messaging/`, `Protocol/` are
transport-neutral; `Transport/Tcp/` (Swoole) and `Transport/Loopback/` are
carriers. The boundary is **Deptrac-enforced** (transport → core only, core
depends solely on the SPI — same mechanism as the ARCH-002 Runtime→Core
guard). Physical package split (`nexus-cluster-core` + per-transport packages)
happens when the second transport lands, so the split follows a proven seam
instead of a speculative one.

### 3.5 Metrics extraction

Metrics move out of behavior classes into dedicated `readonly` metrics classes,
one per subsystem: `ConnectionMetrics` (frame/link lifecycle, delivery-outcome
counters, socket-write failures), `MembershipMetrics` (transitions, suspicions,
gossip), `AskMetrics` (pending, timeouts, capacity rejections). Each class
creates its instruments **eagerly** from `MeterInterface` at wiring time in the
guardian (cheap no-ops when observability is disabled) and is injected via
constructor DI as a plain instance — no singletons, no lazy `??=` instrument
creation in handlers. These classes become the single place the documented
metric names live, which is how the metric-name compatibility constraint is
enforced. The seven copy-pasted `safely()`/lazy-counter blocks reduce to one
small shared error-guard helper for span/dispatch wrapping.

## 4. Data Flows

### 4.1 Ingress (message hot path — 2 mailbox hops)
Pump: socket read → incremental `FrameCodec` (send+recv maxFrameSize,
reassembly cap maxFrameSize+64 KiB) → typed frame + ingress timestamp → `tell`
link actor *(hop 1)*. Identified behavior: `Message` frames → envelope decode +
trace extract → `LocalDelivery` → target mailbox *(hop 2)*. Decode CPU remains
per-link serialized (link actor handler ≙ today's recv coroutine); a slow
decode backpressures only that peer. Control frames decode in the link actor →
`tell` membership. Net change vs today: +1 hop on the message path —
benchmark-gated (§8.2).

### 4.2 Handshake admission
`Unidentified` behavior, in order: HMAC verify (freshness window + nonce replay
set), cluster-name/protocol match, **SEC-008 identity binding** (the asserted
canonical `NodeAddress` is part of the HMAC'd material; the link's
authenticated identity is fixed at admission), tombstone check via
`RoutingSnapshot`. Success →
supervisor registers endpoint + supersedes prior slot + clears tombstone →
`become(Identified)` → `HandshakeAck` (with view) sent. Failure or
the Slowloris deadline → close + stop; queued frames die with the actor,
never reaching ingress. **AMENDED 2026-07-23:** the Slowloris deadline is a
hard self-scheduled `HandshakeDeadline` message (`scheduleOnce` at accept,
cancelled at identification) — NOT `setReceiveTimeout`, whose
reset-on-every-user-message semantics let trickle junk frames defer the
admission deadline forever (caught by adversarial review of the
implementation; the pre-actorization code always had a hard deadline). `HandshakeAck` view application remains gated behind
identification (registry-poisoning defense).

**Control-frame authorization (SEC-008, concretized 2026-07-23):** the HMAC
already binds the full identity claim (node map + advertise are in the signed
canonical JSON); the gap is downstream authorization of control frames on
identified links. Five checks close it:

1. **Leave is self-attesting** — `LeavePayload` gains optional
   `nonce/issuedAt/mac` signed by the *leaver* with the group secret
   (freshness window + nonce replay set, mirroring the handshake). This
   preserves the deliberate star-relay (in a B→A←C star, C legitimately
   receives B's Leave on A's link — a strict naming-must-match-link rule
   would break it) while making forged Leaves impossible without the secret.
   Unsigned Leaves are accepted only when no `authSecret` is configured.
2. **Re-identification pinning** — a Handshake on an already-identified link
   asserting a DIFFERENT NodeAddress is rejected and counted (same-identity
   re-handshake keeps C10 supersede + endpoint-failover semantics).
3. **Ack-view authority** — the ack sender's own view entry must match its
   HMAC-bound advertise; view entries are filtered against tombstones.
4. **Gossip endpoint-write policy** — a gossip member entry naming the
   *sender's own* address must match the link's HMAC-bound advertise;
   endpoints registered from a verified Handshake are not overwritable by
   unauthenticated-per-entry gossip. The membership VIEW merge is untouched
   (gossip's transitive third-party value must survive; only the
   endpoint-registry write path is restricted).
5. **Liveness accounting excludes rejected frames** — a frame rejected by
   authorization must not feed the phi detector.

Known ceiling (documented, deferred): the shared group secret means an
admitted member can still forge a fresh *handshake* as another identity;
per-node keypairs (spec §9 deferred hardening) are the full fix.

### 4.3 Egress (1 mailbox hop)
`ClusterRef::tell` on the caller's coroutine: encode (caller pays, as today) →
`RoutingSnapshot` read → resolve peer → `offer()` into the peer actor's bounded
mailbox → `EnqueueResult` maps to `DeliveryOutcome`:

| Condition | Outcome |
|---|---|
| Link up, enqueue accepted | `Admitted` |
| Reconnecting (backoff window), enqueue accepted | `Buffered` |
| Queue full / no route / tombstoned / stopping | `Dropped` |

**Semantic shift (ADR-documented):** `Admitted` now means "admitted to the
peer's send queue", not "socket write returned". REL-009's enum was already
admission-shaped; post-admission socket-write failures get a dedicated metric.
At-most-once is unchanged. The peer actor performs the write (5 s deadline);
a stalled peer suspends only its own actor — HOL protection now falls out of
the topology, and `dispatchControlSend`'s per-frame coroutine spawning is
deleted. Control frames from the effect interpreter take the same path.

### 4.4 Membership loop
Pump-stamped, throttled liveness observations and decoded gossip flow into
`MembershipActor`. Pure transitions yield `{events, effects}`: effects go to a
thin interpreter resolving targets via `RoutingSnapshot` (gossip fanout via
`ShuffledCycleSelector`, cadence unchanged — the `applyTick` detection/gossip
split is deferred; it changes observable cadence and needs its own soak).
Events feed three consumers: the journal (§5), the deduplicated PSR-14
publisher chain (unchanged event types), and the tombstone projection.

### 4.5 Tombstone unification
The journal is the single source of truth for departures. `NodeDeparted`
events project into `RoutingSnapshot`'s tombstone set (FIFO cap 10 000);
frame-level admission and gossip filtering read that projection. Today's two
overlapping mechanisms (frame-level `$departedTombstones`, membership-level
`DepartedPeerTracker`) collapse into this one model. Re-handshake emits
`TombstoneCleared`. Gossip/Leave frames are filtered against the projection
*before* membership processing, preserving today's anti-resurrection ordering.

### 4.6 Ask/reply
`ClusterRef::ask` → register with `AskRegistryActor` → egress with reply-path
stamp (shape guard preserved) → reply decoded by a link actor → registry →
future completes. Timeouts inside the registry; peer loss fails all pending
asks for that node.

### 4.7 Shutdown
Guardian: enter `Stopping` (new sends → `Dropped`; no re-dials — replaces the
`$stopped` flag) → membership stops gossip ticks **then** broadcasts Leave
(star-relay preserved) and journals `SelfLeft` → peer actors flush + close
intentionally (decider: Stop, no reconnect) → PoisonPill cascade → transport
close — all under `ActorSystem::shutdown`'s deadline. Ordering guarantees a
post-Leave lazy re-dial can never re-announce identity (constraint C1).

## 5. Persistence Design

- **Identity:** the SEC-008 node identity **is the canonical `NodeAddress`**
  (cluster/datacenter/application/node — configured, operator-visible, already
  asserted in handshakes). It is bound into the HMAC'd handshake material and
  pinned at admission; the same address reconnecting from a new endpoint is
  allowed (failover/re-IP — endpoint registry updates on handshake).
  `PersistenceId::of('ClusterMembership', $nodeAddress)` and **writerId = the
  canonical NodeAddress string** with `ReplayFilterMode::Fail`. Per-node
  keypair identity (Ed25519 fingerprint) is deferred hardening on this seam.
- **Events** (facts with incarnations + timestamps, distinct from PSR-14
  notifications): `SelfJoined`, `NodeJoined`, `NodeStatusChanged(node, status,
  incarnation, observedAt)`, `SelfIncarnationBumped(n)`, `NodeDeparted(node,
  reason)`, `TombstoneCleared(node)`, `SelfLeft`. Transitions that don't change
  the view persist nothing (`Effect::none()`).
- **Snapshots:** `SnapshotStrategy::everyN(100)`; `RetentionPolicy` configured.
  Journal growth is churn-proportional and slow but unbounded until framework
  snapshot pruning lands — documented limitation.
- **Recovery (the safe-replay contract):** replayed members **never enter
  `ClusterView`**. The journal contributes exactly three things:
  - `selfIncarnation` **floor** — monotonicity survives restarts (the
    load-bearing anti-flapping win);
  - **tombstones**, trusted as-is;
  - a **dial-hint list** handed to `ConnectionSupervisor`, dialed like extra
    seeds (seed-independent rejoin).

  The gossiped view cold-starts as `{self}` — structurally identical to
  today's validated cold boot, so stale liveness cannot contaminate gossip
  merge and no new wire-visible `MemberStatus` exists. Gossip remains the sole
  liveness authority; `ClusterView::pickWinner` is untouched.
- **Stores:** pluggable — `InMemoryEventStore` for unit/loopback tests,
  DBAL/SQLite documented for deployments. Recovery is spawn-blocking once at
  boot (acceptable for one actor; Swoole coroutine-hook caveat documented).

## 6. Supervision & Error Handling

| Edge | Strategy | Rationale |
|---|---|---|
| Guardian → Membership | `oneForOne` restart; journal replay = consistent full reset. `WriterConflictException`/replay failure **escalates** to node shutdown | Identity corruption must not limp along |
| Guardian → ConnectionSupervisor | Escalate to node shutdown | Link directory without sockets is incoherent; fail fast |
| Supervisor → OutboundPeerActor | **Crash containment only** (`oneForOne`, modest retries; `IntentionalClose → Stop`); supervisor `watch`es and respawns on `Terminated`. **Reconnect is an internal backoff state machine** inside the actor (scheduleOnce retries, preamble-then-flush, exactly today's `PeerConnection` semantics) | AMENDED 2026-07-23 during Plan-3 grounding: the framework's real semantics disprove supervision-as-reconnect for queue survival — `restart()` clears the suspend buffer (frames sent during backoff would be silently lost, no dead-letter, `ActorCell::restart`) and `exponentialBackoff`'s `maxRetries` is a lifetime cap (window is zero). Internal reconnect keeps the mailbox (send queue) intact across retries = `Buffered`, preserving C-semantics verbatim. |
| Supervisor → InboundLinkActor | Any failure → Stop | Inbound links are cheap; the peer re-dials |
| Decode failure on identified link | Close link (protocol violation) | Never crashes membership |

Asymmetric close semantics preserved: local `close()` fires no local callbacks;
`Terminated` via watch replaces remote-close handlers; intentional-close is a
message/decider concern, not a flag. Quorum floor (`minimumMembers` gates Down,
emits `ClusterDegraded`) and evict-only-on-graceful-Leave stay in the pure core.

## 7. Constraint Ledger (soak-won invariants → new home)

| # | Invariant | New home |
|---|---|---|
| C1 | Handshake preamble per (re)connect; shutdown stops gossip before Leave; stopped-gate on sends | OutboundPeerActor `PreStart`; guardian `Stopping` behavior (§4.7) |
| C2 | Zero ingress before identification; HandshakeAck gated | `Unidentified` behavior + mailbox FIFO + bounded pre-auth mailbox (§4.2) |
| C3 | Phi timestamp stamped at socket receive, never after mailbox hops | Pump stamps; carried in `HeartbeatObserved` (§3.2) |
| C4 | Gossip echo dedup filters publication only, never the view | Publisher chain unchanged |
| C5 | Incarnation monotonicity; `pickWinner` merge order | Pure core untouched; journal floor strengthens it (§5) |
| C6 | REL-009 tri-state delivery admission; drops explicit | `EnqueueResult`→`DeliveryOutcome` mapping (§4.3) + write-failure metric |
| C7 | HOL protection: stalled peer never delays membership | Per-peer actor isolation (§4.3) |
| C8 | `ShuffledCycleSelector` mandatory (idle-mesh heartbeat starvation) | Membership core unchanged |
| C9 | Recv timeout ≠ EOF | `SwoolePeerLink` untouched |
| C10 | Re-handshake supersede without closing prior link | ConnectionSupervisor slot logic (§3.1) |
| C11 | Asymmetric close; intentional-close distinguishes shutdown from death | Watch/`Terminated` + decider (§6) |
| C12 | `evictOutbound` only on graceful Leave, never phi Down | Pure core + interpreter unchanged |
| C13 | Quorum floor gates Down → `ClusterDegraded` | Pure core unchanged |
| C14 | SEC-007 fail-closed topology defaults: non-loopback bind without TLS throws unless `allowInsecureBind: true`; `createProduction()` requires TlsConfig + non-empty auth secret | `Node/` config validation in the new bootstrap/facade `boot()` |

Hardening inventory carried verbatim: frame size bounds, reassembly cap,
pre-auth mailbox cap 1024, 5 s send deadline vs 10 s maxNoHeartbeat, Slowloris
deadline (as `ReceiveTimeout`), `maxInboundLinks`, tombstone FIFO 10 000, ask
capacity 10 000, reply-path shape guard, TypeRegistry decode allowlist, HMAC
freshness + nonce replay set. Documented metric/span names and PSR-14 event
types are preserved.

## 8. Validation Gates

1. **Phase 0 — recommit the 16-node soak harness in-tree** (currently not
   committed; without it C3/C5/C8 are unguarded). Acceptance: **zero false Down
   at true default phi**, idle and loaded.
2. **Throughput:** ≥90% of the ~738k msg/s baseline despite the +1 ingress hop;
   documented escape hatch: move message-frame decode back into the pump.
3. **Soak per lifecycle change, never batched** (transport-regression lesson).
4. **Behavior parity:** integration tests adapted to the new facade assert the
   same protocol behavior; the two `ReflectionProperty` assertions are replaced
   by an ask-based `LinkReport` query on the supervisor. Concretely: the Fiber
   (loopback) and Swoole cluster suites (`make test-fiber`, `make test-swoole`,
   `make test-cluster`) run green after each phase as the standing parity check.
5. **Unit story:** per-actor `StepRuntime` tests for admission, supersede,
   backoff, safe replay — the decomposition makes these deterministic.

## 9. Scope Ledger

- **In scope:** everything above; SEC-008; the transport SPI + Deptrac boundary
  with TCP as the proving carrier (§3.4); metrics extraction into injected
  per-subsystem metrics classes (§3.5) + shared error-guard helper;
  unify the two `DeliveryOutcome` enums; `MeshOutboundSink` near-duplicate and
  `dispatchControlSend` die with the rewrite; C1/C2 plan-label docblock residue
  cleaned in rewritten files.
- **Deferred (each with its own follow-up spec):** **HTTP transport**
  (point-to-point on the SPI, `JsonWireFormat`); **Queue transport**
  (brokered profile on the SPI, building on nexus-messenger's
  `StampMessageRouter`/`TargetActorPathStamp` cluster seam); physical package
  split into `nexus-cluster-core` + per-transport packages (when the second
  transport lands); `applyTick` detection/gossip split (own soak); C2
  receptionist (membership view remains its input); framework snapshot pruning;
  per-node keypair identity (Ed25519) as hardening on the NodeAddress-identity
  seam.
- **Untouchable:** `pickWinner` merge semantics, phi defaults, codec wire
  formats, HMAC scheme (extended with identity binding, not replaced).

## 10. Documentation Deliverables

1. `docs/adr/0009-actorized-cluster-node.md` — decision record: topology
   choice (incl. rejected alternatives A/C), ES decision **including the §2.1
   dissent**, I/O boundary, `Admitted` semantic shift.
2. `website/docs/packages/cluster-tcp.md` — new architecture section:
   supervision tree, flow diagrams, constraint ledger, persistence & recovery
   semantics, updated API reference.
3. This spec, committed under `docs/superpowers/specs/`.
