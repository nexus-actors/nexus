# C2 — Receptionist Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Akka-shaped receptionist service discovery on the C1 TCP mesh — `ServiceKey` registry replicated via gossip, local `find`, push `subscribe`, death-watch deregistration, anti-entropy — per spec `docs/superpowers/specs/2026-07-05-cluster-receptionist-design.md` §9.

**Architecture:** A per-node `receptionist` actor owns local registrations and subscriptions; a pure `ReplicatedRegistry` VO folds registration deltas from gossip frames (the `registrations` field C1 left empty) plus periodic full-snapshot anti-entropy; `NodeDown` from the membership view drops that node's entries and re-notifies subscribers. Remote entries materialize as `ClusterRef`s via `ClusterRefFactory`.

**Tech Stack:** everything from C1 (same package `nexus-cluster-tcp`, same branch or follow-up branch `feat/cluster-receptionist`), PHPUnit 13.

## Global Constraints

Same as C1 (Docker-only, no attribution, GrumPHP, style gates, TDD, fail-safe telemetry, loopback-first testing, hard timeouts on swoole suites). Consistency semantics are part of the public docs contract: AP, ~1 gossip-interval convergence, listings may briefly include dead refs — every doc surface repeats this.

### Task C2.1: `ServiceKey` + protocol messages + `ReplicatedRegistry`

- `ServiceKey` — `final readonly`, `ServiceKey::of(string $name)` (non-empty, normalized like NodeAddress segments), equality by name, `__toString`.
- Messages (`final readonly`, `#[MessageType]` where they cross the wire): `Register(ServiceKey, ActorRef)`, `Deregister(ServiceKey, ActorRef)`, `Find(ServiceKey, ActorRef $replyTo)`, `Listing(ServiceKey, list<ActorRef>)`, `Subscribe(ServiceKey, ActorRef)`; wire deltas `ServiceRegistered{key, node: map, path}` / `ServiceDeregistered{...}` and `RegistrySnapshot{node: map, entries: list<{key, path}>}` (wire types carry address/path scalars, never refs).
- `ReplicatedRegistry` (pure, immutable): `applyRegistered/applyDeregistered/applySnapshot(NodeAddress $origin, ...)` (snapshot reconciles: add missing, drop absent for that origin), `dropNode(NodeAddress)`, `entriesFor(ServiceKey): list<{node, path}>`, `localEntries(NodeAddress): list<...>`, `changedKeys(self $before): list<ServiceKey>` (diff helper for subscriber notification). Exhaustive TDD — this is the correctness core: idempotent re-register, dedup (node+path+key unique), snapshot heal after missed delta, dropNode cascades, diff correctness.
Commit: `feat(cluster-tcp): service keys and replicated receptionist registry`

### Task C2.2: Receptionist actor + gossip piggyback + anti-entropy

- `Receptionist::create(ClusterNode $node, ...): Behavior` spawned by `ClusterNode::boot()` as `'receptionist'`; `ClusterNode::receptionist(): ActorRef`.
- Handles: `Register` (must be a locally-owned ref — auto-`expose()`; broadcast `ServiceRegistered` delta via membership gossip channel; `$ctx->watch($ref)` for auto-deregister on `Terminated`), `Deregister` (+ broadcast), `Find` (local `entriesFor` → materialize: local paths → registry-resolved local refs, remote → `ClusterRefFactory`; reply `Listing`), `Subscribe` (immediate Listing + on-change pushes; `watch` subscriber, drop on Terminated).
- Gossip integration: `MembershipService` gossip send hook pulls `localEntries()` deltas queue + every Nth round (default 10) the full local `RegistrySnapshot`; receive path feeds `ReplicatedRegistry` apply + notifies receptionist of changed keys. `NodeDown` membership event → `dropNode` + notify.
- Events/metrics per spec §12: `ServiceListingChanged` PSR-14, `nexus.cluster.receptionist.registrations` gauge, `.finds` counter (fail-safe).
- Loopback integration tests (Fiber): register on A → find on B (after ≤2 gossip rounds, TestClock-driven) returns ClusterRef → tell through it lands on A's actor; ask through a found ref; subscribe sees grow + shrink on node kill (view Down injection); death-watch auto-deregister propagates; anti-entropy heals a dropped delta (lossy loopback decorator swallows one gossip frame).
Commit: `feat(cluster-tcp): receptionist with gossip replication and anti-entropy`

### Task C2.3: Swoole socket proof + example + docs + landing + PR

- One real-socket test (suite `integration-cluster`, timeouts): 2 nodes, register on A, find+tell from B, kill A, B's subscriber listing shrinks within give-up+gossip window.
- **Example**: extend `examples/nexus-cluster-tcp/` — node A registers its greeter under `ServiceKey::of('greeter')`; node B discovers via `Find` instead of `refFor()` (replace the manual wiring — the example should showcase the receptionist as the primary pattern, manual refs as the fallback note); subscriber printing listing changes on node kill; README updated.
- **Comprehensive docs**: receptionist sections on the cluster package page + guide (register/find/subscribe walkthrough, consistency caveats box, ServiceKey conventions, subscription lifecycle), reference page `receptionist.md` + config additions, CLAUDE.md, CHANGELOG. PSR-3 logging documented (registration broadcast/heal/prune events at debug/info). Site build gate.
- **Landing**: update `landing/src/pages/cluster.astro` — receptionist feature section (Find/Subscribe snippet verified against shipped API) replacing any "manual discovery" phrasing from C1; landing build gate.
- Full battery; push; PR `feat: cluster receptionist — replicated service discovery` (stacked on C1's branch/PR if unmerged).
