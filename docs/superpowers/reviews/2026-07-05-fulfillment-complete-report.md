# Nexus Fulfillment — Complete Development & Review Report

**Scope:** the entire `feat/fulfillment-example` branch (PR #51) — milestones 1–3 of the 8-milestone
series, 2026-07-03 → 2026-07-05.
**Volume:** 49 commits, 132 files, ~14,200 insertions; 122 example tests + 149 persistence-package
tests; all example gates + full monorepo root battery + PR CI green at head.
**Companion documents:** design spec (`docs/superpowers/specs/2026-07-03-nexus-fulfillment-example-design.md`),
three implementation plans (`docs/superpowers/plans/2026-07-0{3,4}-fulfillment-*.md`, plan 3 carrying
Amendments A–C), expert reviews + synthesis (`docs/superpowers/reviews/`).

---

## 1. Executive summary

Three milestones built a production-grade, multi-tenant order-fulfillment reference application on
the Nexus actor framework: event-sourced entity actors with passivation and live-proven replay, a
compensating fulfillment saga, registry-strict Valinor persistence with versioned wire names, CQRS
read models fed by bus-subscribed projectors, and an authenticated HTTP API carrying value objects
on the wire. Development ran plan-per-milestone with a fresh implementer and reviewer per task, a
whole-branch review per milestone, and closed with a three-lens expert review (DDD, distributed
systems, framework DSL).

The defining thread was a user-driven **actor-design arc** (three amendments in milestone 3) that
transformed the domain style from functional DECIDE/EVOLVE tables into rich DDD aggregates whose
**method signatures are the routing table**, with actors reduced to trivial orchestration
declarations. Two framework capabilities were upstreamed into `nexus-persistence` along the way.

The expert review's verdict: strong domain layer and honest engineering culture, but the example
currently inherits fail-silent framework defaults for supervision/mailboxes/writer-identity, and
one framework-level interaction bug (restart double-apply) is severe enough to corrupt inventory
under ordinary production conditions. A three-wave action plan closes it (§7).

---

## 2. What was built

```
examples/nexus-fulfillment/
├── SharedKernel/        TenantId, Sku, Quantity, Money, OrderId, OrderLine (validating VOs),
│                        AggregateRoot, RejectionEvent marker, Contracts/{Orders,Inventory} (13 wire-named)
├── Orders/              Order aggregate (place/cancel/markStockReserved), OrderActor (declaration),
│                        orders_view read model (composite tenant PK), 4 HTTP handlers
├── Inventory/           InventoryItem aggregate (restock/reserve/release + ReservationPolicy),
│                        inventory_levels read model (reservations-map dedup), restock/list handlers
├── Fulfillment/         FulfillmentProcess saga aggregate (start/confirm/reject + compensation),
│                        FulfillmentProcessActor (forProcess), FulfillmentManagerActor (bus router)
├── Platform/            Boot (App/DoctrineKit/SchemaBootstrap/config), Bus (ContextBus),
│                        Actor (AggregateBehavior — signature-driven builder), Http (VoParamResolver,
│                        resolvers, auth), Persistence (pooled wire-name-translating stores),
│                        Serialization (MessageTypes registry: 21 wire names)
└── docker: app (Swoole worker) + Postgres 17; Makefile gates; standalone CI workflow
```

**Guarantee highlights, live-proven:** entity replay across container restart (409 cancel-guard from
a replayed aggregate); journal carrying `orders.*.v1`/`inventory.*.v1`/`fulfillment.*.v1` wire names;
saga happy path (order → `stock_reserved`, inventory decremented) and compensation (oversized order
→ `cancelled`, holds released — including the rejection-races-ahead interleaving); tenant isolation
under identical client-supplied ULIDs; 401/403/400/409 error surfaces.

---

## 3. Milestone 1 — Foundation (`d628b7ec..2273286f`)

**Delivered:** standalone project scaffolding (PHP 8.5 ZTS + Swoole 6.2.1 image, compose, Makefile),
six SharedKernel value objects (TDD), the `OrderPlaced` contract with the `{context}.{event}.v{N}`
wire-name convention and Valinor round-trip proof, platform bootstrap with `/healthz` + `/readyz`
(live-verified incl. Postgres-down 503), four quality gates (Psalm level 1 + nexus-psalm plugin,
Deptrac DDD layers, CS-Fixer, PHPCS/Slevomat), standalone CI workflow.

**Review record:** six task reviews, all approved, zero Critical/Important at task level. The
whole-branch review returned **With fixes**, catching two Importants fixed in `2273286f`:
- `PDO::ATTR_TIMEOUT` is inert for pgsql — the readiness probe could hang for the OS TCP timeout on
  a black-holed DB (fixed via DSN `connect_timeout=2`, overriding the plan-mandated DSN test string
  with user approval);
- the README's "copy the folder out and `git init` it" claim was false as written.
Plus triaged: OrderId case normalization (a lowercase ULID would silently address a different
entity) and `.dockerignore`.

---

## 4. Milestone 2 — Orders vertical slice (`82e8c115..ce60e8c0`)

**Delivered:** Doctrine pools + pooled event/snapshot stores; pure Orders domain (decision table with
idempotent place/cancel, `Rejection` as a value — later superseded); the `ContextBus` fan-out actor
(the future Messenger seam); the event-sourced `OrderActor` with receive-timeout passivation and
spawn-on-demand `OrderRefFactory`; the `orders_view` projector; the authenticated HTTP vertical
(3 demo tokens with tenant claims, idempotent `POST /api/orders` keyed by client-supplied ULID,
tenant-scoped CQRS reads).

**User directives absorbed mid-milestone:** PHP 8.4 modernization (`private(set)` entities, promoted
properties — getters deleted); WeakMap-cached store wrappers; **value objects on the wire** (VO
request/response DTOs reusing SharedKernel `OrderLine`; `VoParamResolver` casting route params à la
Symfony value resolvers).

**Framework changes (nexus-persistence):** `EventSourcedBehavior::withSignalHandler()` — required
because `ActorCell` strips outer setup wrappers, so ES actors could never observe `ReceiveTimeout`;
the passivation seam simply did not exist. Landed BC-safe (appended param) after an Opus review
caught a mid-signature insertion that would have runtime-crashed every `AbstractEventSourcedActor`.

**Major catches in this milestone's reviews:**
- **Registry-strict replay was latently broken** (controller catch): the engine stamps FQCNs into
  `event_type`; the strict Valinor registry only knows wire names — persist worked, replay exploded.
  A subagent had "fixed" it by wiping the database; the real fix (`b2058415`) translates envelope
  types in the pooled stores, proven by a live restart-then-cancel scenario and raw-SQL assertions.
  Snapshots had the same latent failure (unregistered `OrderState` would throw at event #50).
- **Cross-tenant read-model overwrite** (whole-branch review): the view was keyed by client-supplied
  ULID alone — tenant B replaying tenant A's known id would overwrite A's row. Fixed with composite
  `(tenant_id, id)` identity, live-proven with the same-ULID-two-tenants scenario.
- A silent CI regression: PR lint/static-analysis had been red since the framework change (root gates
  were never run over modified `packages/` files) — fixed and made a standing dispatch rule.

---

## 5. Milestone 3 — Inventory + saga, and the actor-design arc (`3d69c04e..df03d100`)

**Delivered (plan tasks):** Inventory domain (seven-row decision table; **rejection-as-persisted-
event** with a no-op fold — the audit-trail teaching moment); `InventoryItemActor` (rejections
published to the bus — the saga's failure signal); Orders `stock_reserved` extension with the
cancel-after-reservation 409 guard; the `FulfillmentProcess` saga (bus-routed replies via a
stateless manager — no `sender()` reliance — with compensation and mid-flight replay proof);
per-context Deptrac fences (red/green proven); `#[RequiresRole]` migration; the inventory vertical
with the full live E2E.

**The actor-design arc (user-directed, three amendments):**
- **Amendment A — rich aggregates:** functional DECIDE/EVOLVE replaced by aggregate roots with
  behavior methods, `public private(set)` state, append-only `record()`, and **all rejections as
  persisted `RejectionEvent`s** (full Verraes — chosen over exceptions and result objects). Thin
  actors derive replies from drained events. Decision semantics were frozen 1:1 and verified.
- **Amendment B — declarative actors:** `EventSourcedBehavior::withEventPublisher()` upstreamed
  (engine publishes persisted events post-fold; replay-safety is structural — recovery uses a
  separate fold path — and package-test-pinned) + a route-map builder. `OrderActor`: 146 → 75 lines.
- **Amendment C — signature-driven dispatch:** the route-map array (rejected by the user as
  configuration) replaced by convention: aggregates implement `AggregateRoot` and expose intention
  methods taking the message object (`place(PlaceOrder $command): void`); `AggregateBehavior`
  reflects those signatures into the dispatch table (void-return-guarded after review, ambiguity-
  checked, cached). Zero configuration; the aggregate is the API. `Mixed*` suppressions eliminated.

**Major catches in this milestone's reviews:**
- **Rejection-races-ahead reservation leak** (Opus, saga review): `StockReservationRejected(B)`
  processed before `StockReserved(A)` compensated with an empty confirmed-set — A's hold leaked
  permanently. Fixed by releasing confirmed ∪ pending (idempotent), residual reserve-after-release
  sub-race documented; scenario-(d) inverted-delivery test pins it.
- **Duplicate-SKU under-reservation** (whole-branch review): the saga's pending map overwrote
  duplicate lines — an order billing 5 units held 3. Fixed as an `Order::place()` invariant.
- **User-cancel-during-reserving leak** (whole-branch review): a well-timed cancel strands holds with
  no crash needed. Documented; behavioral fix (OrderCancelled-into-saga) adjudicated to M4's opener.
- **Fence over-broadening** (Amendment B review): contexts could reach the whole composition root;
  narrowed to a `PlatformActor` sublayer with double red/green proof.
- **Discovery foot-gun** (Amendment C review): a future value-returning helper would be claimed as a
  handler; discovery now requires `: void` (all nine handlers already complied), test-pinned.

---

## 6. Framework changes upstreamed (nexus-persistence)

| Change | Why | Safety |
|---|---|---|
| `withSignalHandler()` | ActorCell strips outer setup wrappers — ES actors couldn't observe ReceiveTimeout; passivation impossible | Appended param (BC break caught & fixed in review), package tests incl. PostStop delivery |
| `withEventPublisher()` | Every actor hand-rolled publish loops in `thenRun` | Appended param, replay-safety structural (separate recovery fold), 4 package tests (order, replay-silence, none/reply-silence, immutability) |
| Typed `Closure` docblocks + style fix | The signalHandler param was untyped → root Psalm/PHPCS red (silent CI regression) | Full 894-file root battery verified |

Graduation candidates identified for a first-class Nexus DDD package: `AggregateRoot`,
`AggregateBehavior`, the pooled wire-name-translating stores, `ContextBus`, sharded ref factories.

---

## 7. The expert review (2026-07-05) and the action plan

Three parallel expert reviews (full reports in this directory; synthesis in
`2026-07-05-fulfillment-expert-review-synthesis.md`):

- **DDD architect** — verdict: better-than-average teaching codebase with two structural faults.
  Criticals: `record()`-without-`apply()` breaks decide-on-current-state (the saga's `count===1`
  dance is the visible crack); phantom rejection journals for never-created aggregates; the
  "Deptrac-proven Domain purity" claim unenforced (Domain whitelists `Nexus` wholesale). Plus:
  journal-persisted prose reasons, `FulfillmentCompleted` naming that M4 must rename before journals
  freeze, `Reason`/`StockLevel` VO gaps, contracts-as-journal coupling.
- **Distributed-systems expert** — verdict: teaches the safe 50%, inherits fail-silent defaults for
  the dangerous 50%. Criticals: **restart double-apply corruption** (mutable aggregate instance
  captured as emptyState + default restart supervision → journal re-folds onto mutated state →
  silent durable inventory corruption); projector death permanently freezing the read API; the
  single-writer story unwired (writerId churns; `ReplayFilter` defaults `off()` while the docblock
  claims `fail()`). Plus: restock not retry-safe, poison-message manager outage, `WORKERS=2`
  corruption underdocumented, no global event feed (blocks the M7 rebuild), and the M4 timer
  pattern (persist deadline + check-on-wake, NOT `scheduleOnce`).
- **DSL critic** — verdict: principled core, DSL 1–2 milestones short of production. 28 verified
  weaknesses (3 Critical, 11 High); dominant root cause: **fail-silent defaults contradicting the
  stated fail-fast philosophy** (`Effect::none()->thenRun` no-op is taught by the framework's own
  docblock; `Escalate` silently degrades to `Stop`; fail-open authorization on closure routes;
  in-memory stores never serialize so the wire-name trap is invisible to tests). Themes: union-
  protocol typing gap, missing last-mile compositions, leaky positional/wrapper composition, docs
  drift.

**Action plan (from the synthesis):**
- **Wave 1 (correctness, before/with M4):** framework emptyState-factory fix + restart-recovery
  test; wire real supervision/bounded mailboxes/stable writerId + `ReplayFilter::fail()`; stop
  persisting rejections for `NotCreated`; make restock retry-safe; enforce Domain purity in Deptrac;
  rename `FulfillmentCompleted` and de-prose journal reasons before streams freeze.
- **Wave 2 (M4 plan inputs):** the two pre-adjudicated openers (commands-as-contracts;
  OrderCancelled-into-saga compensation) + deadline pattern + contracts/journal split + README
  honesty additions + explicit `Decision` reply derivation.
- **Wave 3 (framework roadmap):** the DSL catalog's fail-fast defaults overhaul, union-protocol
  typing, last-mile composition graduation, engine API de-positionalization, docs reconciliation.

---

## 8. Defect ledger — every Critical/Important caught, and where it went

| # | Found by | Defect | Resolution |
|---|---|---|---|
| 1 | M1 branch review | pgsql connect-timeout inert (probe hang) | Fixed `2273286f` |
| 2 | M1 branch review | README standalone claim false | Fixed `2273286f` |
| 3 | M2 task review (Opus) | `withSignalHandler` mid-signature BC break | Fixed `d77f0541` (+ package tests) |
| 4 | M2 task review (Opus) | Passivation test asserted nothing | Fixed `d77f0541` |
| 5 | Controller | Registry-strict replay broken (FQCN journal); snapshots would fail | Fixed `b2058415`, live restart-proven |
| 6 | M2 branch review | Cross-tenant read-model overwrite | Fixed `ce60e8c0` (composite PK) |
| 7 | Controller | PR CI silently red (root gates on packages/) | Fixed `887d00ea` + standing rule |
| 8 | P3 task review | Over-reserve reply contract unpinned | Fixed `4b391014` |
| 9 | Amendment A review (Opus) | Unknown commands fabricated Accepted | Fixed `9a567e0c` (`Effect::unhandled`) |
| 10 | Saga review (Opus) | Rejection-races-ahead reservation leak | Fixed `dfc33039` (+ scenario-d test) |
| 11 | M3 branch review | Duplicate-SKU under-reservation | Fixed `01b6bac0` (aggregate invariant) |
| 12 | M3 branch review | User-cancel-during-reserving leak | Documented; behavioral fix = M4 opener |
| 13 | Amendment B review (Opus) | Deptrac fence over-broadened (context↔Platform cycle) | Fixed `03822fa0` (PlatformActor sublayer) |
| 14 | Amendment C review (Opus) | Discovery claims value-returning helpers | Fixed `df03d100` (void guard) |
| 15 | Expert review | Restart double-apply corruption (framework) | **OPEN — Wave 1, top priority** |
| 16 | Expert review | Supervision/mailbox/writer defaults unwired | **OPEN — Wave 1** |
| 17 | Expert review | Phantom rejection journals; restock retry-unsafe | **OPEN — Wave 1** |
| 18 | Expert review | Domain-purity fence unenforced | **OPEN — Wave 1** |

Accepted, documented limitations: ContextBus at-most-once (journal-backed delivery = broker
milestone); compensation reserve-after-release sub-race; saga stranding on crash windows;
single-worker deployment pending sticky entity routing.

---

## 9. Lessons learned

**Process:** run root monorepo gates whenever `packages/` changes (bit twice before becoming a
dispatch rule); controller shell cwd drifts after timeouts — cd explicitly; live E2E catches what
in-memory tests structurally cannot (the wire-name trap, replay, races); adversarial reviews on the
most capable models repaid their cost every time they ran (defects #3, #9–#14); a dead agent's work
is recoverable from its commits — verify, don't redo.

**Technical:** `Effect::none()->thenRun` drops side-effects; actor-name and PersistenceId grammars
differ (`|` vs `.`); Valinor needs public constructors (mapping target = validating constructor);
Deptrac evaluates layer pairs independently (dual membership is safe) but ignores bare `use`
statements (fence probes need typed references); arrow functions with `: void` misbehave returning
void calls; sqlite `:memory:` is per-connection — file-backed DBs for pool tests; PHP 8.4
`private(set)` hydrates fine under Doctrine ORM 3 and json-serializes as public state.
