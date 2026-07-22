# ClusterNode Actorization — Plan Series Roadmap

> Spec: `docs/superpowers/specs/2026-07-22-clusternode-actorization-design.md`
> Branch: `refactor/cluster-node-actorization` (stacked PRs, one per plan)
> Standing gate for every plan: `make test-fiber`, `make test-swoole`,
> `make test-cluster` green; GrumPHP (php-cs-fixer, phpcs, psalm level 1,
> phpunit) green; soak per lifecycle change, never batched.

Each plan is a separate document written **after** its predecessor lands, so
task code is grounded in the real, landed shape of the previous phase. Each
plan produces working, testable software and its own PR.

## Prerequisites — audit-stack merge sequencing ✅ SATISFIED 2026-07-22

The audit stack #79–#105 **landed on `main` 2026-07-22** via rebase-merge of
the chain tip (see the executed
`2026-07-22-audit-stack-merge-plan.md`); `main`'s tree was verified
byte-identical to the CI-green chain tip, `main` CI passed, and this branch
was rebased onto `main` (the 30 stack commits dropped as patch-identical).
Plan-PR sequencing is unblocked.

Fixes the design explicitly depends on (regressing any of these is a plan
failure):

| PR | Finding | Why the refactor needs it |
|---|---|---|
| #105 | REL-009 delivery outcomes | Constraint C6 — `DeliveryOutcome` mapping in the egress design |
| #84 | SEC-007 fail-closed topology | Constraint C14 — bootstrap validation in the new facade |
| #96 | REL-003 supervision strategies | Reconnect-as-supervision requires working `exponentialBackoff` + deciders |
| #95 | REL-002 death watch | `ConnectionSupervisor` watches links; `Terminated`-driven cleanup |
| #97 | REL-004 suspend/resume | Supervision resume path used by link actors |
| #93 | OPS-001 bounded dead letters | Unidentified-link close routes queued frames to dead letters |
| #89 | DSL-001/004 persistence routing | Plan 4's event-sourced membership unhandled-command semantics |
| #81 | SEC-004 deserialization allow-list | Serializer defaults consumed by `ClusterMessageCodec` |
| #104 | ARCH-002 Deptrac guard | Plan 1 extends the same boundary-guard pattern |

SEC-008 has no PR — it is absorbed into Plan 3 by design decision D4.

## Plan 1 — Validation Net + Seams (this repo state → additive only)

Doc: `2026-07-22-clusternode-actorization-plan-1-foundation.md` (written)

- Recommit/recreate the 16-node soak harness in-tree + `make` target
  (spec §8.1 — the acceptance gate for everything that follows).
- `WireFormat` interface + `MsgpackWireFormat` wrapping today's hand-rolled
  codecs verbatim (spec §3.4.4 — zero hot-path change).
- Per-subsystem metrics classes `ConnectionMetrics` / `MembershipMetrics` /
  `AskMetrics` + shared error-guard helper (spec §3.5), created as standalone
  units; consumed by new actors in Plans 3–5 (the monolith is not retrofitted).
- Deptrac intra-package boundary: `ClusterCore` ↛ `ClusterTransport`
  (spec §3.4 packaging, ARCH-002 pattern).
- **No behavior change.** Existing tests untouched and green; soak harness
  baseline numbers recorded in the PR description.

## Plan 2 — Transport SPI + Pumps

- Carrier SPI (connection-oriented profile): serve/dial/link contracts,
  ingress-stamped frame delivery, `PeerAuthenticator` seam (spec §3.4.2–3).
- Socket pumps extracted: read + incremental framing + ingress stamp +
  `LivenessThrottle` before any mailbox (spec §3.2, C3).
- `NodeEndpoint` → URI-style addressing (`tcp://…`) (spec §3.4.1).
- Swoole + Loopback carriers move under `Transport/`; existing `ClusterNode`
  drives the new edge through a thin adapter — behavior unchanged, soak run.

## Plan 3 — Connection Actors + SEC-008

- `ConnectionSupervisor` + `RoutingSnapshot`; `InboundLinkActor`
  (Unidentified→Identified become, `ReceiveTimeout` Slowloris, bounded
  pre-auth mailbox 1024); `OutboundPeerActor` (mailbox-as-send-queue
  bounded(100, DropNewest), backoff supervision as reconnect, preamble in
  PreStart) (spec §3.1, §4.1–4.3, §6).
- SEC-008 admission: canonical `NodeAddress` bound in HMAC material, link
  identity pinned, control frames validated against link identity (spec §4.2).
- `EnqueueResult`→`DeliveryOutcome` mapping + socket-write-failure metric
  (spec §4.3). Monolith's link handling replaced; **soak gate + throughput
  gate ≥90% of baseline** (spec §8.2).

## Plan 4 — Membership Persistence

- Fact-events (`SelfJoined`, `NodeJoined`, `NodeStatusChanged`,
  `SelfIncarnationBumped`, `NodeDeparted`, `TombstoneCleared`, `SelfLeft`);
  `MembershipActor` wrapped in `EventSourcedBehavior`;
  `writerId = canonical NodeAddress`, `ReplayFilterMode::Fail`,
  `SnapshotStrategy::everyN(100)` (spec §5).
- Safe replay: journal contributes only {incarnation floor, tombstones,
  dial-hints}; view cold-starts `{self}` (spec §5 recovery).
- Tombstone unification: journal → `RoutingSnapshot` projection (spec §4.5).
- Stores: InMemory (tests), DBAL/SQLite (documented default). Soak gate.

## Plan 5 — Guardian, Facade, Asks, Deletion + Docs

- `ClusterGuardian`, `Stopping` shutdown protocol (spec §4.7);
  `AskRegistryActor` (failed-future capacity semantics, §3.1/§4.6); new
  facade (`boot/expose/refFor/view/queryViewAsync/self/shutdown`), ask-based
  `view()`, `LinkReport` introspection replacing ReflectionProperty tests
  (spec §3.3, §8.4); delete `ClusterNode` monolith, `dispatchControlSend`,
  `MeshOutboundSink` duplicate; unify the two `DeliveryOutcome` enums.
- Docs deliverables: ADR `docs/adr/0009-actorized-cluster-node.md`,
  `website/docs/packages/cluster-tcp.md` architecture rewrite (spec §10).
- Final acceptance: full soak (idle + loaded, zero false Down at default
  phi), throughput ≥90% baseline, all suites green.

## Deferred beyond the series (spec §9)

HTTP transport spec; Queue transport spec (nexus-messenger seam); physical
package split; `applyTick` split; C2 receptionist; snapshot pruning; Ed25519
identity hardening.
