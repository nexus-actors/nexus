# C1 — TCP Cluster Transport + Membership Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** New package `nexus-cluster-tcp` — Swoole TCP mesh between Nexus nodes (framing, seed join, gossip membership, phi-accrual + connection-death failure detection, `ClusterRef` with TCP asks), per spec `docs/superpowers/specs/2026-07-05-cluster-receptionist-design.md` §4–§8, §10–§12.

**Architecture:** Every node runs a `ClusterServer` accept-loop coroutine (spawned via `Runtime::spawn()` inside `SwooleRuntime`'s `Co\run` scope) plus one `PeerConnection` outbound coroutine per known peer. `TcpClusterTransport` implements the existing `nexus-cluster` `ClusterTransport` contract over length-prefixed msgpack frames. `MembershipService` folds Join/Gossip/Leave + phi-accrual into an immutable `ClusterView`; `ClusterRef` short-circuits self-node sends and does one-RTT asks via `PendingAskRegistry`-style correlation.

**Tech Stack:** Swoole 6.2.1 `Coroutine\Server`/`Coroutine\Client` (verified present), `nexus-serialization-msgpack` (S1 — prerequisite), `nexus-cluster` contracts, `nexus-core`/`nexus-runtime`/`nexus-runtime-swoole`, PHPUnit 13.

## Global Constraints

- Branch `feat/cluster-tcp` off main once S1 merges (stack if needed; controller records base).
- Package `nexus-actors/cluster-tcp`, namespace `Monadial\Nexus\Cluster\Tcp`. Requires: php >=8.5.7, ext-swoole >=6.2.1 IN SUGGEST not require (loopback transport + Fiber must work without Swoole for the dev/test path — only the real TCP classes hard-require the extension at runtime via `extension_loaded` guards; unit/loopback tests run in the plain `php` container, socket tests in `php-swoole`), dev-main: cluster, core, runtime, runtime-swoole (suggest), serialization, serialization-msgpack, observability.
- GROUNDED FACTS (verified — build on these): `ClusterTransport { send(NodeAddress, string): void; listen(callable(string): void): void; close(): void }`; `NodeAddress(cluster, datacenter, application, node)` has NO host/port — C1 introduces `NodeEndpoint` + `EndpointResolver` for that; `NodeAddress::toPathPrefix()` is the canonical string form; `NodeAddress::temporaryAskReplyPath(string)` exists for remote asks; `SwooleRuntime::spawn()` creates long-lived coroutines (immediate inside Co\run, queued before `run()`); `SwooleRuntime::yield()` = `Coroutine::sleep(0.001)`; Makefile `test-cluster` target ALREADY points at suite `integration-cluster` which does not exist in phpunit.xml — C1 defines it (fixing the pre-existing inconsistency).
- Wire security: cluster-name + protocol-version handshake validation; registry-strict msgpack types; optional TLS via Swoole socket SSL options; docs warn plaintext = private networks only.
- All the usual: Docker-only commands, no Claude attribution, GrumPHP per commit, style gates, trailing-optional params, fail-safe telemetry (swallow), TDD, hard `timeout` wrappers on every swoole suite invocation (hang lesson).
- Swoole test suites: unit-swoole additions + new `integration-cluster` suite (php-swoole container). Loopback/Fiber tests go in the plain `unit` suite + `tests/Integration/ClusterTcp/` under a NEW `integration-cluster-loopback` suite run in the php container.

### Task C1.1: Scaffold + `NodeEndpoint`/`EndpointResolver` + `ClusterTopology`

Monorepo wiring per repo convention (composer maps, phpunit suites `integration-cluster` + `integration-cluster-loopback` + unit dirs, deptrac layer `ClusterTcp` → Cluster/Core/Runtime/RuntimeSwoole/Serialization/SerializationMsgpack/Observability, Makefile `test-cluster-loopback` target, split.yml + gh repo, CHANGELOG, CLAUDE.md, README).
New VOs (TDD):
- `NodeEndpoint(public string $host, public int $port)` + `fromString('host:port')` (validate port range; IPv6 bracket form documented out of scope v1).
- `interface EndpointResolver { public function resolve(NodeAddress): ?NodeEndpoint; }` + `MapEndpointResolver(array<string, NodeEndpoint> /* keyed by toPathPrefix() */)` — gossip carries (NodeAddress, NodeEndpoint) pairs so the resolver map grows dynamically; `MutableEndpointRegistry` implements resolver + `register(NodeAddress, NodeEndpoint)`.
- `ClusterTopology` per spec §5 (clusterName, self, selfEndpoint: NodeEndpoint, bind vs advertise, seeds: list<NodeEndpoint>, heartbeatInterval 1s, phiThreshold 8.0, gossipInterval 1s, reconnect initial/max backoff, ?TlsConfig) — factory + withers, validation (seeds non-empty unless single-node, clusterName non-empty).
Commit: `feat(cluster-tcp): scaffold with node endpoints and cluster topology`

### Task C1.2: Frame + codec

`Frame(public FrameType $type, public string $payload)` (payload = msgpack bytes); `enum FrameType: int { Handshake=1; HandshakeAck=2; Message=3; Gossip=4; Ping=5; Pong=6; Leave=7; Error=8; }`.
`FrameCodec`: `encode(Frame): string` = `pack('N', 1+len) . chr(type) . payload`; `decodeStream(string $buffer): array{frames: list<Frame>, rest: string}` — incremental (partial frames stay in rest); max-frame-size guard (default 8 MiB, topology-tunable) → `ProtocolException`; unknown frame type → `ProtocolException`.
Protocol payload VOs (all `final readonly` + `#[MessageType]`, msgpack-encoded via S1 serializer with a dedicated cluster `TypeRegistry` built by `ClusterNode`): `Handshake{clusterName, protocolVersion:int=1, node: address-map, advertise: 'host:port'}`, `HandshakeAck{accepted: bool, reason: ?string, view: snapshot-map}`, `MessagePayload{targetPath, messageType, body: bin, correlationId: ?string, replyPath: ?string, trace: map<string,string>}`, `GossipPayload{view: map, registrations: list /* C2 uses; empty in C1 */}`, `LeavePayload{node}`.
TDD: codec round-trip, partial-frame reassembly (byte-by-byte feed), oversize rejection, unknown-type rejection. Pure PHP — no Swoole needed.
Commit: `feat(cluster-tcp): length-prefixed msgpack frame protocol`

### Task C1.3: Transport seam + loopback implementation

`interface MeshTransport` (internal, richer than the public contract): `connect(NodeEndpoint): PeerLink`, `serve(NodeEndpoint $bind, callable(PeerLink): void $onAccept): void`, `close(): void`. `interface PeerLink { sendFrame(Frame): void; onFrame(callable(Frame): void): void; onClose(callable(): void): void; close(): void; remote(): ?NodeEndpoint; }`.
`LoopbackMeshTransport` (test-support quality but shipped in src/ — it is the Fiber dev-mode transport): in-process hub keyed by endpoint string; `connect` pairs two in-memory `PeerLink`s; frame delivery via runtime `spawn`/queues (works on Fiber). TDD on Fiber: connect/serve/frames both directions/close events.
Commit: `feat(cluster-tcp): mesh transport seam with in-process loopback`

### Task C1.4: Swoole TCP mesh transport

`SwooleMeshTransport implements MeshTransport` — `serve()`: `Swoole\Coroutine\Server($bind->host, $bind->port, $tls ? SWOOLE_SSL : 0)` inside a `Runtime::spawn()`ed coroutine; per-connection receive loop feeds `FrameCodec::decodeStream`. `connect()`: `Swoole\Coroutine\Client(SWOOLE_SOCK_TCP [| SSL])` + receive coroutine; both wrap links in a shared `SwoolePeerLink`. Reconnect/backoff is NOT here (PeerConnection layer, C1.5). TLS from `TlsConfig` (cert/key/ca/verify options → Swoole `set()`).
Tests (suite `integration-cluster`, php-swoole, `timeout 120` per test cmd; ephemeral ports via `stream_socket_server` port-probe helper): serve+connect+bidirectional frames, half-frame stress (send byte-split), close propagation, TLS happy path (self-signed fixture certs committed under tests/fixtures), handshake-free at this layer.
Commit: `feat(cluster-tcp): Swoole coroutine TCP mesh transport with optional TLS`

### Task C1.5: Membership — view, phi-accrual, gossip state machine

- `ClusterView` (immutable): members map keyed by `toPathPrefix()` → `{address, endpoint, incarnation: int, status: MemberStatus enum Up|Suspect|Down, lastSeen}`; `merge(ClusterView $other): self` (higher incarnation wins; equal incarnation → worse status wins); `withStatus/withMember/withoutNode`; `nodes()`, `upNodes()`, `has()`. Pure TDD.
- `PhiAccrualDetector`: per-peer sliding window (default 200 samples) of heartbeat inter-arrival times; `heartbeat(string $peer, DateTimeImmutable $now)`, `phi(string $peer, DateTimeImmutable $now): float` (Hayashibara: `-log10(1 - CDF(elapsed))` with normal approximation; guard min stddev). Injected clock; pure TDD with scripted arrivals (steady 1s arrivals → phi < 1 at 1.5s, > 8 by ~3-4s; jittery arrivals tolerate more).
- `MembershipService` (plain class driven by an owning actor in C1.6): consumes link events + frames, owns handshake exchange (validate clusterName/protocolVersion → HandshakeAck with current view or Error+close), gossip send every `gossipInterval` to min(3, peers) random peers, PING/PONG per heartbeatInterval per peer feeding the detector, state transitions Up→Suspect (phi or link-close) →Down (reconnect give-up default 10s) with recovery, incarnation bump on self rejoin, `Leave` handling, callbacks `onViewChange(callable(ClusterView, list<MembershipEvent>): void)` → PSR-14 `NodeUp/NodeDown` + counters/gauge at the ClusterNode layer.
TDD: pure unit over scripted link/frame sequences with TestClock (join handshake happy/reject paths, gossip merge, suspect via phi, suspect via close, down via give-up, recovery, leave, incarnation supersedes stale entries).
Commit: `feat(cluster-tcp): gossip membership with phi-accrual and connection-death detection`

### Task C1.6: `ClusterRef`, inbound routing, TCP asks, `ClusterNode` boot

- `LocalActorRegistry` (`expose(ActorRef): void` — LocalActorRef required; `resolve(string $path): ?ActorRef`), `InboxRouter` (MessagePayload → registry resolve → deserialize body via cluster serializer → `offerEnvelope` with `withCorrelationId`/reply `senderRef` when `replyPath` present → drops counted; unroutable → counter + debug, NO nack (spec §7 decision)).
- `TcpAskRegistry` — reuse pattern (bounded, first-wins, timeout) — implement standalone in this package (do NOT import nexus-messenger); reply frames route by `replyPath` matching `NodeAddress::temporaryAskReplyPath()` ids.
- `ClusterRef implements ActorRef` per spec §7: self-node short-circuit (compare `NodeAddress` equality → deliver via local registry/system), otherwise MessagePayload frame via the peer's `PeerConnection` (bounded send buffer during reconnect, overflow → dead-letter counter + drop); `ask()` = correlation + replyPath + registry + timeout (one RTT); `isAlive()` from view. `ClusterRefFactory(NodeAddress, ActorPath) → ClusterRef`.
- `PeerConnection`: owns one `PeerLink` + reconnect/backoff loop + bounded outbound queue; used by membership pings/gossip and refs alike.
- `ClusterNode::boot($system, $topology, ?MessageSerializer $serializer = null /* default msgpack */): self` — starts transport (Swoole when ext loaded + runtime is Swoole; loopback otherwise — explicit `withTransport()` override for tests), joins seeds, wires membership actor (`'cluster-membership'`), exposes `expose()/refFor()/view()/receptionist() /* C2 */`, shutdown hook sends Leave + closes.
- Integration (loopback, Fiber, suite `integration-cluster-loopback`): 3-node join + view convergence; tell A→B; ask A→B round-trip + timeout; self short-circuit (assert no frames sent); prune via TestClock; graceful leave.
- Integration (Swoole sockets, suite `integration-cluster`): 2-node real-socket boot/join/tell/ask; kill -9 one node → other reaches Down within give-up window; handshake rejection (wrong cluster name).
Commit: `feat(cluster-tcp): ClusterRef with TCP asks, inbound routing, and ClusterNode bootstrap`

### Task C1.7: Observability + docs + PR

Metrics/spans/events per spec §12 wired through ClusterNode (fail-safe); docs: package page + "Clustering over TCP" guide chapter (topology, seeds-in-k8s, TLS, decision table broker-edge vs mesh), reference pages (ClusterNode, ClusterTopology, PhiAccrualDetector config), CLAUDE.md, CHANGELOG; example: two-node TCP demo added to the Redis example's compose. Full battery incl. both cluster suites; push `feat/cluster-tcp`; PR `feat: nexus-cluster-tcp — TCP mesh clustering with gossip membership`.
