# Nexus Fulfillment — Comprehensive Example Application

**Date:** 2026-07-03
**Status:** Approved design
**Base:** current `main` (no dependency on unmerged branches)
**Location:** `examples/nexus-fulfillment/` — standalone Composer project, copy-out-able like `nexus-wallet-app`

## Purpose

A production-grade order-fulfillment system that is both the reference
architecture for building enterprise applications on Nexus and a step-by-step
tutorial for learning Nexus + DDD. Companies should be able to copy the folder
out, `git init` it, and start from it. It showcases, in one coherent app:

- Event-sourced actors (`EventSourcedBehavior`) and durable-state actors
  (`DurableStateBehavior`) — and when to choose which
- Entity-based actors: spawn-on-demand, replay recovery, receive-timeout
  passivation
- A long-running process manager (saga) with compensation and deadlines
- HTTP routing directly into actors; CQRS reads from Doctrine read models
- WebSockets: live ops dashboard + bidirectional warehouse picker console
- Scaling: Swoole-threads worker pool with hash-ring entity sharding
- Full observability into `grafana/otel-lgtm` (traces, metrics, logs,
  provisioned dashboards)
- Clean DDD: modular monolith by bounded context, Domain/Application/
  Infrastructure layers, Deptrac-enforced
- A live-data simulator so dashboards move the moment the stack is up

Out of scope (documented as deliberate): OpenAPI generation, secrets
management beyond env vars, the outbox pattern (belongs to the future
Messenger split), multi-node clustering (nexus-cluster is contracts-only
today). The Messenger bridge is NOT used — the in-process `ContextBus` is
designed as the seam it will later replace ("single service now, broker
later"; documented via Fowler's Strangler Fig).

## Bounded contexts and domain model

Modular monolith: bounded contexts at the top, layers inside each.

### Orders (event-sourced)

`Order` aggregate. Lifecycle: `Placed → AwaitingStock → ReadyToPick →
Picking → Packed → Shipped`, or `Cancelled` (with reason). Pure DECIDE
(`OrderRules`: command → events or domain rejection) and EVOLVE
(`OrderState`: fold). Commands: `PlaceOrder`, `CancelOrder`, plus
saga-driven transitions. Events: `OrderPlaced`, `OrderStockReserved`,
`OrderReadyToPick`, `OrderPicked`, `OrderPacked`, `OrderShipped`,
`OrderCancelled`.

### Inventory (event-sourced)

`InventoryItem` aggregate per SKU. Reservation semantics: `ReserveStock` →
`StockReserved` or `StockReservationRejected` (a first-class domain event,
not an exception — Verraes), `CommitReservation` → `StockCommitted` (on
shipment), `ReleaseReservation` → `StockReleased` (on cancellation),
`Restock` → `Restocked`. Contention on hot SKUs is serialized naturally by
one actor per SKU. `ReservationPolicy` is a named domain concept (make the
implicit explicit — Evans).

### Warehouse (event-sourced)

`PickTask` aggregate, created when an order's stock is fully reserved.
Workers claim and complete tasks over the WS picker console. Events:
`PickTaskCreated`, `TaskClaimed`, `LinePicked`, `TaskCompleted`,
`TaskDeadlineExpired`. **Deadline:** a pick task not completed within a
configurable window fires a timer (`scheduleOnce`) → `TaskDeadlineExpired`
→ task is released for re-claim and an alert event is published. Time is
modeled as a domain concern, not infrastructure.

### Shipping (durable-state)

`Shipment` aggregate on `DurableStateBehavior` — deliberately not
event-sourced, to teach the contrast (shipment history has no value;
current status does). States: `Preparing → Dispatched → Delivered`.

### Fulfillment (event-sourced process manager)

`FulfillmentProcess` — one saga actor per order, event-sourced so it
survives restarts mid-flow. Orchestration (not choreography; trade-off
documented per Fowler):

1. `OrderPlaced` → send `ReserveStock` per line to Inventory
2. All lines reserved → tell Orders (`OrderStockReserved`), create `PickTask`
3. `TaskCompleted` → tell Orders (`OrderPacked`), create `Shipment`
4. `ShipmentDispatched` → tell Orders (`OrderShipped`) → process completes

Compensation: any `StockReservationRejected` → `ReleaseReservation` for
already-reserved lines → `CancelOrder(reason: OutOfStock)`. Deadline
escalation: order stuck in a stage beyond a threshold publishes
`FulfillmentStalled` (visible on dashboards).

### SharedKernel

`TenantId`, `OrderId`, `Sku`, `Money`, `Quantity` value objects and
`Contracts/` — the published language each context exposes (events/commands
other contexts may consume). Cross-context communication happens ONLY via
these contracts over the ContextBus. No other cross-context imports;
Deptrac enforces this.

### Multi-tenancy

Every command and event carries `TenantId`. Entity actors are addressed as
`{tenant}|{entity-id}`, isolating tenants at the actor level. Read models
carry a tenant column; all queries scope by principal tenant.

## Actor topology

```
/user
├── orders          (guardian) ── Order-{tenant|id}…          ES entities
├── inventory       (guardian) ── Item-{tenant|sku}…          ES entities
├── warehouse       (guardian) ── PickTask-{tenant|id}…       ES entities
├── shipping        (guardian) ── Shipment-{tenant|id}…       durable-state
├── fulfillment     (guardian) ── Process-{tenant|orderId}…   ES sagas
├── context-bus     event fan-out between contexts (future Messenger seam)
├── projections     one projector actor per read model
└── ws              channels: dashboard-{tenant}, order-{tenant|id}, warehouse-{tenant}
```

- **Entity lifecycle:** ref factories (`OrderRef::of($tenant, $id)`) spawn
  on demand; a dead child is pruned and respawned; the persistence engine
  replays events (or loads state) to recover. Idle entities passivate via
  `setReceiveTimeout` → `Behavior::stopped()`. Memory stays bounded
  regardless of total entity count.
- **Sharding:** Swoole-threads worker pool; hash ring maps entity names to
  worker threads; `WorkerActorRef` routes cross-thread; Postgres pool and
  read models are shared. Scale = threads = cores.
- **Supervision:** guardians use `exponentialBackoff` (a DB blip must not
  hot-loop restarts). The fulfillment guardian escalates after max retries
  so a poisoned saga surfaces loudly. Dead letters are counted in metrics.
- **Mailboxes:** entities use bounded mailboxes with an explicit overflow
  strategy; saturation is observable (metrics) and surfaces as HTTP 429/503
  at the edge.

## HTTP and WebSocket surface

REST handlers are thin adapters: `#[FromPrincipal]` (tenant + role),
`#[FromActor]` (refs), `ask()` into the entity, map domain reply → HTTP
response. Reads NEVER hit actors — they query Doctrine read models (CQRS,
explicitly taught).

- `POST /api/orders` (idempotency key required), `GET /api/orders`,
  `GET /api/orders/{id}`, `DELETE /api/orders/{id}` (cancel)
- `GET /api/inventory`, `POST /api/inventory/{sku}/restock`
- `GET /api/warehouse/tasks`
- `GET /healthz` (liveness), `GET /readyz` (DB + actor system readiness)

WebSockets (channel actors subscribe to the ContextBus):

- `/ws/dashboard` — live order state changes + inventory levels (ops SPA)
- `/ws/orders/{id}` — single-order timeline stream
- `/ws/warehouse` — bidirectional picker console: task offers pushed down;
  `claim` / `picked` commands sent up, routed into PickTask entities

**Auth:** static bearer tokens (wallet-app pattern) → `Principal` with
`TenantId` + role. Roles: `ops` (dashboard, order management), `picker`
(warehouse console). Every handler and channel scopes by principal tenant.

**Idempotency:** `POST /api/orders` requires an idempotency key; the Order
entity treats `PlaceOrder` idempotently (duplicate key → same result, no
second order). Documented as the general pattern for ingress retries.

**Frontend:** single-file React SPA (tictactoe pattern) in `public/`:
ops dashboard (live orders board, inventory levels) + warehouse picker
console (claim/complete tasks). Server-issued tokens; role picker on load.

## Persistence

- Postgres 17. `DbalEventStore` + `DbalSnapshotStore` (snapshot every 50
  events, retention keeps 3) for ES entities; `DbalStateStore` for
  Shipments. Replay filter `Fail` mode; single-writer ULID documented.
- **Serialization: Valinor-backed `MessageSerializer`.** Events, commands,
  and state carry value objects (`Money`, `Sku`, `Quantity`, `TenantId`) —
  never primitives — and Valinor (de)hydrates them. No primitive obsession,
  even on the wire. This is a teaching point.
- **Event schema evolution:** versioning + upcasting convention (event
  carries a schema version; upcasters transform old payloads before Valinor
  mapping). One tutorial part covers changing `OrderPlaced`'s shape safely.
- **Read models** (Doctrine, maintained by projector actors consuming the
  ContextBus): `orders_view`, `inventory_levels`, `pick_tasks_view`,
  `order_timeline`. Projectors are restartable; a **rebuild command**
  (`make rebuild-projections` / CLI script) truncates read models and
  replays the event log through the projectors — the operational payoff of
  ES, exercised in the runbook.
- Migrations under `db/`; initial **seed catalog** of SKUs so the system
  isn't empty before the simulator starts.

## Observability (grafana/otel-lgtm)

Wired packages: `nexus-observability-otel` (OTLP export),
`-actor`, `-http`, `-persistence`, `-logger`, `-swoole`.

- **Traces (Tempo):** one trace from `POST /api/orders` through the saga —
  HTTP span → Order entity → FulfillmentProcess → per-SKU reservations →
  PickTask creation — across worker threads.
- **Metrics (Mimir):** mailbox depth, throughput, persist latency, entity
  spawn/passivation counts, WS connections, dead letters. Three provisioned
  Grafana dashboards: **System** (runtime/actors), **Fulfillment Ops**
  (orders funnel, stalled sagas, stock rejections), **Entities**
  (spawn/passivate/replay). Trace exemplars enabled.
- **Logs (Loki):** PSR-3 through the trace-correlation processor; log line
  → trace navigation works out of the box.

## Simulator (live data generator)

`Simulator/` context: a `TrafficGeneratorActor` places orders with weighted
SKU baskets at a configurable rate, occasionally cancels, and periodically
restocks; **picker bots** claim and complete warehouse tasks with human-like
delays. Toggleable and tunable via env (`SIMULATOR_ENABLED`,
`SIMULATOR_ORDERS_PER_MINUTE`, `SIMULATOR_PICKER_BOTS`). At high rates it
doubles as an in-process load driver. Dashboards and Grafana show live
movement immediately after `make up`.

## Operations

- **Compose topology:** `app` (Swoole threads) + `postgres` + `lgtm`
  (grafana/otel-lgtm) + optional `k6` profile. `make up` → SPA on :9080,
  Grafana on :3000, simulator running.
- **Resilience runbook** (`docs/runbook.md`, every scenario runnable):
  kill an entity mid-saga → replay recovers; exhaust stock → compensation
  cancels the order; `docker stop` under load → graceful drain, no lost
  events on restart; poison message → backoff → escalate → dead letters in
  Grafana; deadline expiry → task re-offered; projection rebuild.
- **Load story** (`load/`): k6 scenarios — steady-state, spike, saturation
  — against the REST API. Saturation demonstrates bounded-mailbox
  backpressure (429s) rather than collapse. README documents throughput vs
  `WORKER_THREADS` scaling.

## Testing and CI

Noback-style pyramid — test doubles only at architectural boundaries:

1. **Pure domain unit tests** — DECIDE/EVOLVE + value objects; zero
   actor/framework imports. The bulk.
2. **Actor tests on StepRuntime** — deterministic saga tests (feed events,
   `step()`/`drain()`, assert emitted commands/state), passivation,
   supervision, deadline timers.
3. **Integration tests** — real Postgres: persist → kill → replay;
   projections catch up; HTTP end-to-end on Fiber runtime; upcasting.
4. **Swoole smoke tests** — WS connect/claim/broadcast on the real server.

CI (GitHub Actions in the example): lint (PHPCS + CS-Fixer), Psalm level 1
with the nexus-psalm plugin, Deptrac (layer + context boundaries), unit +
integration jobs, k6 smoke nightly.

## Repository layout

```
examples/nexus-fulfillment/
├── src/
│   ├── Orders/ Inventory/ Warehouse/ Shipping/ Fulfillment/
│   │     └── Domain/  Application/  Infrastructure/   (each context)
│   ├── SharedKernel/        value objects + Contracts/ (published language)
│   ├── Simulator/           traffic generator + picker bots
│   └── Platform/            bootstrap, auth, WS wiring, OTel wiring, ContextBus
├── public/                  server.php + single-file React SPA
├── db/                      migrations + seed catalog
├── observability/           Grafana dashboards + OTel config (provisioned)
├── load/                    k6 scenarios
├── docs/
│   ├── tutorial/            the multi-part tutorial (see below)
│   ├── ddd-influences.md    Evans / Verraes / Noback / Fowler → code map
│   ├── architecture.md      diagrams + decisions
│   ├── runbook.md           runnable resilience scenarios
│   └── scaling.md           threads today, Messenger split tomorrow (Strangler Fig)
├── tests/                   Unit/ Actor/ Integration/ Smoke/
├── compose.yaml  Dockerfile  Makefile  deptrac.yaml  psalm.xml
├── .github/workflows/ci.yml
└── README.md                front door: what it shows, quickstart, guided tour
```

Layering rules (Deptrac): Domain imports nothing but PHP + SharedKernel
value objects. Application imports Domain + Nexus actor APIs + port
interfaces. Infrastructure implements ports (stores, HTTP, WS, OTel).
Cross-context: only `SharedKernel/Contracts`.

## Tutorial (docs/tutorial/)

The app is taught as a numbered series; each part builds on the last,
names the patterns it introduces (ubiquitous language per context; commands
imperative, events past tense; behavior-rich models, no getter/setter bags;
named constructors; small aggregates), and ends with the tests that prove
the step. Planned parts:

1. Why actors for enterprise PHP; bootstrap, first actor, first test
2. Value objects and the SharedKernel (Evans; Money/Sku/Quantity; Valinor)
3. The Order aggregate: DECIDE/EVOLVE, event sourcing, pure domain tests
4. Entity actors: ref factories, replay recovery, passivation
5. Inventory: contention, domain rejections as events, ReservationPolicy
6. The saga: FulfillmentProcess, orchestration, compensation (StepRuntime tests)
7. Time in the domain: deadlines, timers, escalation
8. HTTP → actors: routing, auth, idempotency, CQRS read models
9. WebSockets: dashboard channels + the bidirectional picker console
10. Durable state: Shipping, and when NOT to event-source
11. Projections: read models, rebuilds
12. Event schema evolution: versioning + upcasting
13. Observability: tracing the saga, dashboards, log correlation
14. Scaling: worker threads, sharding, backpressure, load testing
15. Production posture: supervision, runbook, graceful shutdown, CI

Tutorial prose follows the docs style guide; each part links to the exact
files/commits in the example.

## Success criteria

- `make build && make install && make up` from a clean checkout brings up
  app + Postgres + LGTM; SPA and Grafana show live simulator traffic within
  a minute.
- A single trace visibly spans HTTP → saga → inventory → warehouse.
- Every runbook scenario is runnable as documented.
- CI green: lint, Psalm (level 1 + plugin), Deptrac, unit, integration.
- Domain layer has zero framework imports (Deptrac-proven).
- The folder can be copied out of the monorepo and built standalone.
