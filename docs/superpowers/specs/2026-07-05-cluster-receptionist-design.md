# Nexus Cluster over Messenger — Remote Refs + Receptionist — Design

- **Date:** 2026-07-05
- **Status:** Approved design, ready for implementation plans (two: C1, C2)
- **Package:** `nexus-cluster-messenger` (new)
- **Depends on:** `nexus-cluster` (contracts), `nexus-messenger` incl. the ask/reply feature (plan `2026-07-04-messenger-ask-reply.md` — prerequisite), `nexus-core`, `nexus-runtime`, `nexus-serialization`, `nexus-observability`.

## 1. Motivation

Give Nexus a real dynamic cluster on the infrastructure users already run: Symfony Messenger transports as the inter-node backbone, Akka-style **receptionist** service discovery instead of hand-maintained routing maps. An actor on node A registers under `ServiceKey('payments')`; an actor on node B calls `find()` and gets live remote refs — location transparency across machines.

The v1 bridge left the seams deliberately: `TargetActorPathStamp` + `StampMessageRouter` ("the cluster seam"), `nexus-cluster`'s `NodeAddress`/`NodeDirectory`/`ClusterTransport` contracts, and now broker ask/reply. This design is mostly composition of shipped parts.

## 2. Scope and decomposition

One spec, **two plans / two PRs**:

- **C1 — Remote refs + node inboxes + membership view.** Usable standalone (discovery via static config maps). Deliverables: `ClusterTopology`, node inbox wiring, `ClusterRef`, inbound routing, `MembershipActor` + `ClusterView` + heartbeat pruning, observability.
- **C2 — Receptionist.** `ServiceKey` registry replicated over the membership topic, `find`/`subscribe`, anti-entropy via heartbeat piggyback, pruning integration.

Out of scope for both (documented): quorum/split-brain handling, phi-accrual failure detection, node states beyond present/absent, cluster singletons, sharding.

## 3. Constraints

- Messenger is the backbone: all inter-node traffic flows through user-configured Messenger transports. No raw broker SDK code. Operator-configured DSN templates only — **nothing on the wire ever constructs a transport** (same SSRF posture as ask/reply's logical reply-to names).
- `nexus-cluster` stays contracts-only; `nexus-messenger` unchanged (consumed as-is).
- All messages crossing nodes go through `NexusMessengerSerializer` with registered types (`#[MessageType]` on every cluster protocol message).
- Repo conventions: final/readonly, Psalm level 1, style gates, TDD, trailing-optional params, fail-safe telemetry.

## 4. Package layout (`packages/nexus-cluster-messenger`)

```
src/
├── ClusterTopology.php          # config VO (see §5)
├── ClusterNode.php              # per-node bootstrap facade (see §9)
├── Ref/ClusterRef.php           # remote ActorRef (see §6)
├── Ref/ClusterRefFactory.php    # NodeAddress + path → ClusterRef
├── Inbound/InboxRouter.php      # StampMessageRouter over the local registry
├── Inbound/LocalActorRegistry.php
├── Membership/MembershipActor.php
├── Membership/ClusterView.php   # immutable snapshot: nodes + lastSeen
├── Membership/Message/{NodeJoined,Heartbeat,NodeLeft}.php
├── Receptionist/Receptionist.php            # actor behavior factory (C2)
├── Receptionist/ServiceKey.php
├── Receptionist/Message/{Register,Deregister,Find,Listing,Subscribe,Registered,Deregistered}.php
├── Receptionist/ReplicatedRegistry.php      # pure fold of registration events (C2)
└── Event/  # PSR-14: NodeUp, NodeDown, ServiceListingChanged
```

## 5. Node identity & wiring — `ClusterTopology`

Immutable config VO:

- `clusterName: string`
- `self: NodeAddress` (existing `nexus-cluster` VO: cluster/datacenter/application/node)
- `inboxDsnTemplate: string` — `{node}` placeholder → each node's point-to-point inbox transport (built via the user's `TransportFactoryInterface`, auto-setup like reply channels)
- `membershipDsnTemplate: string` — the shared fanout topic (AMQP fanout exchange / Redis stream broadcast); consumed per-node with a `{node}`-unique consumer group/queue so every node sees every event
- `heartbeatInterval: Duration` (default 5 s), `heartbeatMissLimit: int` (default 3 → prune after 15 s silence)
- Withers for all tunables; factory `ClusterTopology::create(clusterName, self, inboxDsnTemplate, membershipDsnTemplate)`.

Inbox and membership transports are created at node boot by `ClusterNode` (§9) through the injected transport factory — never from wire data.

## 6. `ClusterRef` — the remote `ActorRef` (C1)

`final readonly class ClusterRef implements ActorRef` (template `T of object`):

- ctor `(NodeAddress $node, ActorPath $path, SenderInterface $inboxSender, ?AskSupport $askSupport = null, Observability ..., ?EventDispatcherInterface ...)`.
- `tell()` — publish the message to the target node's inbox with `TargetActorPathStamp((string) $path)` (+ source/trace stamps). Reuses `MessengerActorRef` mechanics; implement by composition or shared internals — implementer's choice, no behavior drift.
- `ask()` — delegates to the ask/reply feature (`AskSupport::ask()` with the same stamps + the inbox sender). Unconfigured → `UnsupportedOperationException`, same convention as `MessengerActorRef`.
- `path()` returns the real remote `ActorPath`; `isAlive()` returns `true` when the node is present in the current `ClusterView`, else `false` (best-effort liveness — documented as advisory).
- Serializable identity: `ClusterRefFactory` can reconstruct a ref from `(NodeAddress, ActorPath)` — this is what the receptionist replicates (never live objects).

**Inbound:** each node runs `spawnReceivers()` on its inbox with `InboxRouter`: resolves `TargetActorPathStamp` against `LocalActorRegistry` (paths of locally spawned, cluster-exposed actors — explicit `expose($ref)` registration, not automatic; keeps the attack/routing surface deliberate). Unroutable → existing reject/dead-letter policies. Ask-stamped inbound messages work via the already-shipped responder path (locator configured with the reply transports).

## 7. Membership (C1)

- `MembershipActor` (one per node, spawned by `ClusterNode`): on start publishes `NodeJoined(self, registrationSnapshot: [])`; every `heartbeatInterval` publishes `Heartbeat(self, snapshot)` (snapshot payload used by C2, empty list in C1); on graceful shutdown (`PostStop`) publishes `NodeLeft(self)`.
- Consumes the membership topic (own consumer group) and folds into `ClusterView`: `nodes(): list<NodeAddress>`, `lastSeen(NodeAddress): ?DateTimeImmutable`, `has(NodeAddress): bool`. View updates dispatch PSR-14 `NodeUp`/`NodeDown`.
- Pruning: on each heartbeat tick, nodes with `now - lastSeen > heartbeatInterval × heartbeatMissLimit` are dropped (→ `NodeDown`). Clock via `ActorSystem::clock()` (PSR-20; `TestClock` for deterministic tests).
- Own events are consumed and folded like any other node's (self always present while alive) — no special-casing.
- All membership messages are `final readonly` with `#[MessageType]`; `NodeAddress` serialized via its string form.

## 8. Receptionist (C2)

API (messages to the per-node `receptionist` actor; Akka-shaped):

- `Register(ServiceKey $key, ActorRef $ref)` — ref must be locally-owned (its path resolved via `LocalActorRegistry`; also auto-`expose()`s it). Broadcasts `Registered(key, self, path)` on the membership topic.
- `Deregister(ServiceKey, ActorRef)` — broadcasts `Deregistered(...)`. Actor termination auto-deregisters via death-watch (`$ctx->watch()` + `Terminated` signal).
- `Find(ServiceKey $key, ActorRef $replyTo)` → replies `Listing(ServiceKey, list<ActorRef>)` — **local lookup** against `ReplicatedRegistry` (remote entries materialized as `ClusterRef` via `ClusterRefFactory`; local entries as the real local refs). Works with `ask()` for request/reply lookups.
- `Subscribe(ServiceKey, ActorRef)` — subscriber receives a fresh `Listing` immediately and on every change (registration events or node pruning). Subscriptions are local-node state; subscriber termination cleans up via death-watch.
- `ServiceKey` — `final readonly` VO: `ServiceKey::of(string $name)` (+ optional `forType(class-string)` sugar); equality by name.

**Replication & anti-entropy:** `ReplicatedRegistry` is a pure fold: `apply(Registered|Deregistered|snapshot, NodeAddress $origin)`, `dropNode(NodeAddress)`, `listingFor(ServiceKey): list<{node, path}>`. Heartbeats piggyback the origin node's full local-registration snapshot; receivers reconcile (add missing, drop absent) — heals missed events within one heartbeat interval. `NodeDown` → `dropNode()` → affected subscribers get updated listings.

**Consistency (documented honestly):** eventually consistent; listings may briefly include dead refs (tells dead-letter at the owning node, asks time out) or miss brand-new registrations (< 1 heartbeat interval). First-class for stateless service discovery; NOT a linearizable registry.

## 9. Bootstrap / DX — `ClusterNode`

```php
$node = ClusterNode::boot($system, $topology, $transportFactory, $serializer /* NexusMessengerSerializer */);
$node->expose($ordersRef);                                  // make routable from other nodes
$node->receptionist()->tell(new Register(ServiceKey::of('payments'), $paymentsRef)); // C2
$ref  = $node->refFor($nodeAddress, $path);                 // manual remote ref (C1 discovery)
$view = $node->view();                                      // ClusterView snapshot
```

`boot()` wires: inbox transport + receivers (+ ask responder locator), membership transport + `MembershipActor`, ask support for `ClusterRef::ask()`, receptionist actor (C2). `ActorSystem::shutdown()` integration publishes `NodeLeft`.

## 10. Observability

- Metrics: `nexus.cluster.nodes` (gauge), `nexus.cluster.heartbeats.sent|received`, `nexus.cluster.nodes.pruned`, `nexus.cluster.messages.sent|received` (attrs: `nexus.cluster.peer`), C2: `nexus.cluster.receptionist.registrations` (gauge), `nexus.cluster.receptionist.finds`.
- Spans: cluster tells/asks reuse the messenger `messenger.send`/`messenger.ask`/`messenger.receive` spans (attr `nexus.cluster.peer` added); trace context already propagates via `TraceContextStamp`.
- PSR-14: `NodeUp(NodeAddress)`, `NodeDown(NodeAddress)`, `ServiceListingChanged(ServiceKey, list)`.
- All telemetry fail-safe (swallow pattern).

## 11. Testing strategy

- Multi-node in one process: N `ActorSystem`s (Fiber) sharing in-memory transports through a test `TransportHub` (in-memory transport instances keyed by DSN — a tiny test-support factory implementing `TransportFactoryInterface`).
- C1: tell node A → handler on node B; ask A→B round-trip (reuses ask/reply); membership convergence (3 nodes see each other); pruning with `TestClock` (stop node B's heartbeats, advance clock, assert `NodeDown` + view shrink); `isAlive()` reflects view.
- C2: register on A → find on B returns ClusterRef → tell/ask through it lands on A's actor; subscribe sees listing grow and shrink (node prune); anti-entropy (drop a Registered event via a lossy hub decorator, assert heal after one heartbeat); death-watch auto-deregister.
- Unit: `ReplicatedRegistry` fold (pure), `ClusterView` pruning math, `ServiceKey` equality, topology validation (templates must contain `{node}` where required).

## 12. Documentation deliverables

Package page (`website/docs/packages/cluster-messenger.md`), guide chapter "Clustering over Messenger" (topology config per broker, receptionist walkthrough, consistency caveats, scaling notes), reference pages (`ClusterNode`, `Receptionist`, `ClusterTopology` config), CLAUDE.md graph + section, CHANGELOG, README, split.yml + repo, example app extension (second node in the Redis example compose).

## 13. Open questions resolved during brainstorming

- Membership: broker-native pub/sub topic (no static lists, no external registry).
- Registry: fully replicated via the membership topic; `find()` is local (Akka-ddata style, AP/eventually consistent).
- v1 scope: remote refs + receptionist + heartbeat pruning; no quorum/failure-detector sophistication.
- Sequencing: ask/reply (plan A) ships first on PR #50; C1/C2 are separate follow-up PRs.
