# Nexus Cluster — TCP Mesh + Receptionist — Design

- **Date:** 2026-07-05 (rewritten 2026-07-06: cluster pivoted from broker-backed to native TCP; see §14)
- **Status:** Approved design, ready for implementation plans (S1, C1, C2)
- **Packages:** `nexus-serialization-msgpack` (new, S1), `nexus-cluster-tcp` (new, C1+C2)
- **Depends on:** `nexus-cluster` (contracts), `nexus-core`, `nexus-runtime`, `nexus-runtime-swoole`, `nexus-serialization`, `nexus-observability`. The Messenger bridge (incl. ask/reply) is a SIBLING, not a dependency — see §2.

## 1. Motivation & layering

Two kinds of traffic, two right tools:

- **North–south (edge):** Symfony apps ↔ Nexus over **Messenger/brokers** — durable queues, interop, broker ask/reply. Already shipped/shipping on PR #50; the future Symfony bundle builds on it. Unchanged by this design.
- **East–west (cluster):** Nexus node ↔ Nexus node over a **direct TCP mesh** — sub-millisecond tells, connection-level liveness, gossip membership, receptionist service discovery. This is how Akka (Artery), Erlang distribution, and Orleans connect nodes; brokers stay at the edges.

An actor on node A registers under `ServiceKey('payments')`; an actor on node B `find()`s it and talks to it over TCP with no broker hop.

## 2. Scope and decomposition

Three plans / three PRs, strictly ordered:

- **S1 — `nexus-serialization-msgpack`.** `MessagePackMessageSerializer implements MessageSerializer`: ext-msgpack fast path when loaded, `rybakit/msgpack` pure-PHP fallback; object hydration via the existing Valinor mapper (msgpack → array → typed object), same `TypeRegistry` discipline. Also usable by the Messenger bridge (binary bodies; SQS/text transports need base64 — documented). This is the cluster's native wire format, so it ships first.
- **C1 — TCP transport + membership.** `ClusterTransport` implementation over Swoole coroutine TCP, framing, seed-node join, gossip, phi-accrual failure detection, `ClusterView`, `ClusterRef` with self-node short-circuit.
- **C2 — Receptionist.** ServiceKey registry replicated over the mesh, `find`/`subscribe`, anti-entropy, pruning integration. (API identical to the pre-pivot design.)

Out of scope for all three (documented): quorum/split-brain resolution, cluster singletons, sharding, cross-datacenter federation, WAN-optimized gossip.

## 3. Constraints

- **Runtime:** cluster nodes require **Swoole** (coroutine TCP server/client). This matches the repo philosophy — Fiber for dev/test, Swoole for production. Tests run the full stack over an in-process **loopback transport** (see §11) so CI needs no real sockets beyond the Swoole suites.
- **Wire format:** MessagePack native (S1). Frames carry registered `#[MessageType]` names — the registry-strict decode posture carries over from the bridge (unknown type → connection-scoped protocol error, never dynamic class materialization).
- `nexus-cluster` stays contracts-only. No Messenger dependency anywhere in `nexus-cluster-tcp`.
- Repo conventions: final/readonly, Psalm level 1, style gates, TDD, trailing-optional params, fail-safe telemetry.

## 4. Packages layout

```
packages/nexus-serialization-msgpack/src/
├── MessagePackMessageSerializer.php     # MessageSerializer impl
├── Packer.php / Unpacker.php            # internal: ext-msgpack | rybakit dispatch
└── (tests mirror nexus-serialization's)

packages/nexus-cluster-tcp/src/
├── ClusterTopology.php                  # config VO (§5)
├── ClusterNode.php                      # per-node bootstrap facade (§10)
├── Transport/TcpClusterTransport.php    # implements nexus-cluster ClusterTransport
├── Transport/Frame.php                  # length-prefixed msgpack frame VO
├── Transport/FrameCodec.php             # encode/decode + protocol errors
├── Transport/PeerConnection.php         # one outbound coroutine connection + reconnect/backoff
├── Transport/ClusterServer.php          # Swoole TCP server, inbound frame loop
├── Ref/ClusterRef.php                   # remote ActorRef (§7)
├── Ref/ClusterRefFactory.php
├── Inbound/InboxRouter.php              # frame → LocalActorRegistry dispatch
├── Inbound/LocalActorRegistry.php       # expose()d actors only
├── Membership/MembershipService.php     # join/gossip/leave state machine (§8)
├── Membership/ClusterView.php           # immutable snapshot
├── Membership/PhiAccrualDetector.php    # failure detector (§8)
├── Membership/Gossip/{Join,JoinAck,Gossip,Leave,HeartbeatPing,HeartbeatAck}.php
├── Receptionist/…                       # identical file set to pre-pivot design (§9)
└── Event/                               # PSR-14: NodeUp, NodeDown, ServiceListingChanged
```

## 5. Node identity & config — `ClusterTopology`

- `clusterName: string` — handshake-validated; mismatched nodes are rejected.
- `self: NodeAddress` (existing contract VO) + `bindHost/bindPort` and `advertiseHost/advertisePort` (NAT/k8s-friendly).
- `seeds: list<string>` — `host:port` seed endpoints (static list; in k8s, resolve a headless-service DNS name into this list at boot).
- `heartbeatInterval: Duration` (default 1 s), `phiThreshold: float` (default 8.0), `gossipInterval: Duration` (default 1 s), `reconnectBackoff` (initial/max).
- `tls: ?TlsConfig` — optional Swoole SSL (cert/key/CA, verify peer). Plaintext allowed but the docs say loudly: cluster ports must never be exposed untrusted; TLS + network policy for anything beyond a private network.
- Factory + withers per repo convention.

## 6. Wire protocol

- **Frame:** `uint32 length | uint8 frameType | msgpack payload`. Frame types: `HANDSHAKE`, `HANDSHAKE_ACK`, `MESSAGE` (actor envelope), `GOSSIP`, `PING`, `PONG`, `LEAVE`, `ERROR`.
- **Handshake:** on connect, `HANDSHAKE{clusterName, protocolVersion, self: NodeAddress, advertise}` → `HANDSHAKE_ACK{accepted, view}` or `ERROR` + close. Protocol version is a single integer; mismatch rejects (documented upgrade story: rolling upgrades require protocol stability within a major).
- **MESSAGE payload:** `{targetPath, messageType, body(msgpack), correlationId?, replyPath?, trace?: map}` — trace map carries the same W3C carrier the bridge uses, so spans link across the mesh exactly like across brokers.
- **Asks over TCP:** no broker reply queue needed — `replyPath` names a per-ask temporary path on the asking node; the responder's reply frame routes back over the same (or any) connection to that path, resolving the asker's `FutureSlot` via the same `PendingAskRegistry` pattern the bridge uses (bounded, first-reply-wins, timeout). Cheap compared to broker asks: one RTT.

## 7. `ClusterRef` — the remote `ActorRef` (C1)

- ctor `(NodeAddress $node, ActorPath $path, TcpClusterTransport $transport, ClusterView $viewAccessor, ...observability/events trailing)`.
- `tell()` — **self-node short-circuit first**: if `$node` equals the local node, deliver directly to the local mailbox (µs, no socket). Otherwise encode a MESSAGE frame and send via the peer connection (queued during reconnect up to a bounded buffer; overflow → dead letters + counter).
- `ask()` — TCP-native per §6; `AskTimeoutException` semantics identical to local/bridge asks.
- `isAlive()` — current `ClusterView` membership (advisory, now ~1–2 s fresh thanks to phi-accrual).
- Reconstructible from `(NodeAddress, ActorPath)` — what the receptionist replicates.

Inbound: `ClusterServer` frame loop → `InboxRouter` → `LocalActorRegistry` (explicit `expose($ref)` only) → `offerEnvelope` delivery with the same backpressure semantics as the bridge (mailbox-full → frame-level NACK? No: v1 applies DropNewest at the exposed actor's mailbox policy and counts drops; TCP has no redelivery — actors needing durability sit behind the Messenger edge instead. Documented decision.)

## 8. Membership (C1)

- **Join:** connect to any seed → handshake → `JoinAck` carries the current view → connect to all known peers (full mesh v1; fine to ~50 nodes, documented).
- **Gossip:** every `gossipInterval`, each node sends its view (+ receptionist deltas, §9) to a few random peers; views merge by (node, incarnation) — a rejoining node bumps its incarnation so stale entries lose.
- **Failure detection, two signals:**
  - **Connection death** — Swoole close event → immediate `Suspect`; reconnect attempts with backoff; after `reconnectGiveUp` (default 10 s) → `Down`.
  - **Phi-accrual** — `PING`/`PONG` every `heartbeatInterval` per connection; `PhiAccrualDetector` (per-peer arrival-interval history, standard Hayashibara formula) marks `Suspect` at `phiThreshold` even while TCP looks connected (zombie/hung process). Both signals feed the same state machine: `Up → Suspect → Down` (with recovery `Suspect → Up` on evidence).
- `Leave` frame on graceful shutdown (`ActorSystem::shutdown()` integration) → immediate `Down` without suspicion delay.
- `ClusterView` snapshots + PSR-14 `NodeUp`/`NodeDown`. No split-brain resolution in v1: a partition makes both sides mark each other Down and keep running (documented; quorum strategies are future work).

## 9. Receptionist (C2) — unchanged API from pre-pivot design

`ServiceKey`, `Register`/`Deregister` (death-watch auto-deregister), `Find` → `Listing` (local lookup against the fully replicated `ReplicatedRegistry`), `Subscribe` (push on change). Only the carrier changed: registration deltas ride gossip frames; anti-entropy via periodic full-snapshot exchange with gossip peers (every Nth gossip round); `NodeDown` → `dropNode()` → subscriber updates. Consistency statement unchanged and documented: AP/eventually consistent (~1 gossip interval convergence), listings may briefly include dead refs (tells drop/dead-letter, asks time out) — first-class for stateless service discovery, not a linearizable registry.

## 10. Bootstrap / DX — `ClusterNode`

```php
$node = ClusterNode::boot($system, $topology, $serializer /* msgpack */);
$node->expose($ordersRef);
$node->receptionist()->tell(new Register(ServiceKey::of('payments'), $paymentsRef));
$listing = $node->receptionist()->ask(new Find(ServiceKey::of('payments')), Duration::seconds(2))->await();
$view = $node->view();
```

`boot()` starts the server coroutine, joins via seeds, wires membership + receptionist + ask registry; `ActorSystem::shutdown()` sends `Leave` and closes peers.

## 11. Testing strategy

- **Loopback transport:** `LoopbackClusterTransport` (test support) implementing the same transport interface over in-process queues — the full membership/receptionist/ref stack runs on Fiber in `tests/Integration/ClusterTcp/` without sockets; determinism via `TestClock` (phi-accrual gets injected clock + scripted arrival times in unit tests).
- **Real-socket suite** (Swoole container, `integration-cluster-tcp` suite, hard `timeout` wrappers per the earlier hang lesson): 3 nodes on 127.0.0.1 ephemeral ports — join via seed, tell/ask A→B, kill B's process → phi/connection detection → `NodeDown` + listing shrink; graceful `Leave`; handshake rejection (wrong cluster name, wrong protocol version); TLS handshake happy path.
- **Unit:** FrameCodec round-trip + malformed-frame rejection, PhiAccrualDetector math, view merge/incarnation, ReplicatedRegistry fold (reused from pre-pivot design), msgpack serializer round-trips (S1: parity tests against both backends — same bytes both ways).

## 12. Observability

Metrics: `nexus.cluster.nodes` (gauge), `nexus.cluster.peers.connected` (gauge), `nexus.cluster.frames.sent|received` (attr `frame.type`), `nexus.cluster.messages.sent|received` (attr `nexus.cluster.peer`), `nexus.cluster.reconnects`, `nexus.cluster.nodes.suspected|pruned`, `nexus.cluster.asks.*` (mirroring bridge names), C2: `nexus.cluster.receptionist.registrations` (gauge), `.finds`. Spans: `cluster.send`/`cluster.receive` (Producer/Consumer kinds) with trace carrier in frames. PSR-14 as §8/§9. All fail-safe.

## 13. Documentation deliverables

Package pages (`serialization-msgpack`, `cluster-tcp`), guide chapter "Clustering over TCP" (topology, seeds in k8s, TLS, consistency caveats, when-to-use-broker-edge-vs-mesh decision table), reference pages (ClusterNode, ClusterTopology, Receptionist, PhiAccrualDetector config), CLAUDE.md, CHANGELOG, READMEs, split.yml + repos, example: two-node compose in the Redis example extended with a TCP cluster demo.

## 14. Considered alternative: broker-backed cluster (rejected 2026-07-06)

The first version of this spec ran the cluster itself over Messenger transports (node inboxes + a fanout membership topic). Rejected in favor of TCP because: broker hop per tell (~ms vs µs), 2-hop asks, ~15 s heartbeat-topic failure detection vs sub-second connection-death + phi-accrual, and it conflated the integration edge with the cluster fabric. The Messenger bridge remains the north–south edge (durable queues, Symfony interop, broker ask/reply); nothing from PR #50 is discarded. If a broker-only cluster niche appears (networks where node-to-node TCP is impossible), the receptionist/membership design here still fits over a topic carrier — no door closed.

## 15. Open questions resolved during brainstorming

- Edge vs fabric split: Messenger for Symfony↔Nexus, TCP for node↔node (user decision 2026-07-06).
- Discovery: static seed nodes (k8s headless DNS resolves into the seed list).
- Wire format: MessagePack native (S1 package; ext fast path + pure-PHP fallback — user chose "both").
- Failure detection: connection-death + phi-accrual for v1.
- Old broker-cluster spec: rewritten in place (this document), alternative recorded in §14.
