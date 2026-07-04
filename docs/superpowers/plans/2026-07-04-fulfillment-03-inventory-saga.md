# Nexus Fulfillment — Plan 3: Inventory + Fulfillment Saga

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Inventory bounded context (event-sourced `InventoryItem` per SKU with reservation semantics) and the `FulfillmentProcess` saga — an event-sourced process manager that reserves stock for placed orders and compensates (releases + cancels) on shortage — plus the milestone-2 review follow-ups (per-context Deptrac isolation, `#[RequiresRole]` migration, JSON error mapper).

**Architecture:** `src/Inventory/{Domain,Application,Infrastructure}` and `src/Fulfillment/{Domain,Application}` following the exact patterns milestone 2 proved: pure DECIDE/EVOLVE domains, `EventSourcedBehavior` entity shells with `withSignalHandler` passivation, ref factories, registry-strict persistence (wire names `inventory.*.v1` / `fulfillment.*.v1`), bus publication after persist. **Saga replies travel over the ContextBus, not `sender()`**: inventory persists AND publishes `StockReserved`/`StockReservationRejected` contracts; a stateless `FulfillmentManagerActor` (bus subscriber) routes events to per-order saga actors. Orchestration commands (`ReserveStock`, `ReleaseReservation`, `MarkStockReserved`) go as `tell()`s to entity refs.

**Tech Stack:** Everything already on the branch — no new Composer dependencies.

**Spec:** `docs/superpowers/specs/2026-07-03-nexus-fulfillment-example-design.md` — milestone 3 of 8. **Plan-of-record for context:** milestone 2's ledger notes in `.superpowers/sdd/progress.md` (teaching gotchas + review follow-ups) bind this plan.

## Global Constraints

- **Branch:** continue on `feat/fulfillment-example` in worktree `.claude/worktrees/fulfillment` (PR #51). All prior constraints hold: commands only via `cd examples/nexus-fulfillment && make <target>`, GrumPHP worktree fallback `--no-verify` with `make ci` as the true gate, no Claude attribution, style rules per milestone 1/2.
- **Mirror milestone 2, don't invent.** The reference implementations on this branch are authoritative: `src/Orders/Domain/*` (decision-table style), `src/Orders/Application/OrderActor.php` + `OrderRefFactory.php` (ES shell, passivation, publication, reply idiom incl. the null-sender guard and `Effect::reply` for no-persist replies), `src/Orders/Infrastructure/ReadModel/*` (private(set) entity, composite tenant PK, projector), `src/Platform/*`. When this plan's sketches disagree with those files, THE BRANCH WINS.
- **Modern PHP throughout:** promoted properties, `public private(set)` over getters, no getter boilerplate.
- **Registry-strict:** every persisted event/state class gets a wire name. Published contracts live in `SharedKernel/Contracts/{Inventory,Orders}/` with `#[MessageType]`; saga-internal events live in `src/Fulfillment/Domain/Event/` and are registered EXPLICITLY in `MessageTypes` (the `OrderState` pattern — Domain stays attribute-free). New wire names: `inventory.reserve_stock.v1`, `inventory.release_reservation.v1`, `inventory.restock.v1` (published commands), `inventory.stock_reserved.v1`, `inventory.stock_reservation_rejected.v1`, `inventory.stock_released.v1`, `inventory.restocked.v1`, `inventory.item_state.v1`, `orders.order_stock_reserved.v1`, `fulfillment.started.v1`, `fulfillment.reservation_confirmed.v1`, `fulfillment.reservation_failed.v1`, `fulfillment.completed.v1`, `fulfillment.compensated.v1`, `fulfillment.process_state.v1`.
- **VOs on the wire**: request/response DTOs carry SharedKernel VOs; route params cast via `VoParamResolver`.
- **Known limitation to document, not solve:** the ContextBus is at-most-once, in-process. A crash between an entity's persist and its bus publish loses the event for live subscribers (journal keeps it). The saga therefore can strand mid-flight in rare crash windows. Milestone 3 documents this honestly (README + code comment on the manager); journal-backed subscriptions/outbox arrive with the broker milestone. Do NOT build an outbox now.
- **TDD**; per-task gates green (`make test`, `make psalm`, `make cs`, `make phpcs`; `make deptrac` after Task 5).

---

### Task 1: Inventory domain — pure DECIDE/EVOLVE (TDD)

**Files:**
- Create: `examples/nexus-fulfillment/src/SharedKernel/Contracts/Inventory/{StockReserved,StockReservationRejected,StockReleased,Restocked}.php`
- Create: `examples/nexus-fulfillment/src/SharedKernel/Contracts/Inventory/{ReserveStock,ReleaseReservation,Restock}.php` (published COMMANDS — the saga, another context, sends them)
- Create: `examples/nexus-fulfillment/src/Inventory/Domain/{ItemState,InventoryRules,ReservationPolicy}.php`
- Modify: `examples/nexus-fulfillment/src/Platform/Serialization/MessageTypes.php`
- Test: `examples/nexus-fulfillment/tests/Unit/Inventory/Domain/{InventoryRulesTest,ItemStateTest,ReservationPolicyTest}.php`

**Interfaces (load-bearing for Tasks 2/4/6):**
- Contracts (all `final readonly`, attribute-registered):
  - `ReserveStock(TenantId $tenantId, Sku $sku, OrderId $orderId, Quantity $quantity)` — `#[MessageType('inventory.reserve_stock.v1')]`
  - `ReleaseReservation(TenantId $tenantId, Sku $sku, OrderId $orderId)` — `inventory.release_reservation.v1`
  - `Restock(TenantId $tenantId, Sku $sku, Quantity $quantity)` — `inventory.restock.v1`
  - `StockReserved(TenantId $tenantId, Sku $sku, OrderId $orderId, Quantity $quantity)` — `inventory.stock_reserved.v1`
  - `StockReservationRejected(TenantId $tenantId, Sku $sku, OrderId $orderId, Quantity $requested, int $available, string $reason)` — `inventory.stock_reservation_rejected.v1` (`$available` is a plain int — it can be 0, `Quantity` cannot)
  - `StockReleased(TenantId $tenantId, Sku $sku, OrderId $orderId, Quantity $quantity)` — `inventory.stock_released.v1`
  - `Restocked(TenantId $tenantId, Sku $sku, Quantity $quantity)` — `inventory.restocked.v1`
- `ItemState`: `public private(set)`? No — value object, fully readonly like OrderState: `ItemState::empty(TenantId, Sku): self`; `public int $onHand`; `/** @var array<string, int> orderId->qty */ public array $reservations`; derived `public function reserved(): int` (sum) and `available(): int` (onHand − reserved); static `evolve(self, object): self` folding `StockReserved` (add reservation), `StockReleased` (remove), `Restocked` (onHand += qty), `StockReservationRejected` (NO state change — recorded fact), unknown → same instance.
- `ReservationPolicy::allows(ItemState $state, Quantity $requested): bool` — the named domain concept (Evans): `$requested->value <= $state->available()`.
- `InventoryRules::decide(ItemState, object): list<object>|Rejection` (reuse `Monadial\Nexus\Example\Fulfillment\Orders\Domain\Rejection`? NO — cross-context Domain import. Create `src/Inventory/Domain/Rejection.php`, identical shape; a shared one can graduate to SharedKernel when a third context needs it — note this duplication is deliberate, record for the tutorial).

**Decision table (each row = one test):**

| State | Command | Outcome |
|---|---|---|
| any | `Restock(qty)` | `[Restocked]` |
| available ≥ qty, no reservation for orderId | `ReserveStock` | `[StockReserved]` |
| reservation already exists for orderId (any qty) | `ReserveStock` | `[]` (idempotent — retry-safe) |
| available < qty | `ReserveStock` | `[StockReservationRejected(requested, available, 'insufficient stock')]` — **an event, persisted**: the rejection is a domain fact (audit trail) whose fold is a no-op |
| reservation exists | `ReleaseReservation` | `[StockReleased(original qty)]` |
| no reservation for orderId | `ReleaseReservation` | `[]` (idempotent) |
| any | unknown command | `Rejection('Unknown command …')` |

- [ ] **Step 1:** Write the three failing test files covering every row above plus: `evolve` folds each event correctly (reservations map add/remove, onHand accumulation, rejected = same-instance identity via `assertSame`), `available()` math, `ReservationPolicy` boundary (exactly-available allowed, one-more rejected). Mirror `OrderRulesTest`/`OrderStateTest` structure. Run `make test` → RED.
- [ ] **Step 2:** Implement contracts, `ItemState`, `ReservationPolicy`, `InventoryRules`, Inventory `Rejection`. Add all seven contract classes to `MessageTypes::CONTRACTS` (keep alphabetical; commands and events both registered) and `ItemState` to the explicit-STATES section as `inventory.item_state.v1`. Run `make test` → GREEN.
- [ ] **Step 3:** Gates (`make psalm`, `make cs`, `make phpcs`) clean. Commit: `feat(fulfillment): Inventory domain — reservation policy, idempotent decide/evolve`

---

### Task 2: InventoryItemActor + ref factory (TDD)

**Files:**
- Create: `examples/nexus-fulfillment/src/Inventory/Application/{InventoryItemActor,InventoryRefFactory}.php`
- Create: `examples/nexus-fulfillment/src/Inventory/Application/Reply/{StockCommandAccepted,StockCommandRejected}.php`
- Test: `examples/nexus-fulfillment/tests/Integration/Inventory/InventoryItemActorTest.php`

**Interfaces (Tasks 4/6 consume verbatim):**
- `InventoryItemActor::behavior(TenantId, Sku, EventStore, SnapshotStore, ActorRef $bus, Duration $passivateAfter): Behavior` — MIRROR `OrderActor::behavior` exactly: `PersistenceId::of('InventoryItem', "{tenant}|{sku}")`, snapshots everyN(50), retention keeps full log, `withSignalHandler` passivation, `Effect::persist(...)->thenRun` publishing EVERY persisted event to the bus (including `StockReservationRejected` — that's how the saga learns of failure), `Effect::reply` for Rejection/idempotent paths with the null-sender guard.
- `InventoryRefFactory(ActorSystem, EventStore, SnapshotStore, ActorRef $bus, Duration $passivateAfter)` + `of(TenantId, Sku): ActorRef` — actor name `item-{tenant}.{sku}` (dot separator, established convention).
- Replies (for the HTTP restock path; the saga never asks): `StockCommandAccepted(Sku $sku, int $onHand, int $available)`, `StockCommandRejected(Sku $sku, string $reason)`.

- [ ] **Step 1:** Write failing integration tests (Fiber runtime + in-memory stores + real bus, mirroring `OrderActorTest`'s driver idiom): (a) restock→reserve→reply accepted with correct available; (b) over-reserve → the BUS receives `StockReservationRejected` (probe subscriber) AND the journal-visible fold leaves state unchanged; (c) reserve idempotent retry → no duplicate bus event; (d) release → available restored; (e) replay: stop entity, respawn via factory, reserve remaining — proves reservations map recovered. Run → RED.
- [ ] **Step 2:** Implement actor + factory + replies (transcribe the OrderActor pattern; the command handler maps `InventoryRules::decide`). GREEN.
- [ ] **Step 3:** Gates clean. Commit: `feat(fulfillment): InventoryItem entity — reservations survive replay, rejections published`

---

### Task 3: Orders extension — stock-reserved status (TDD)

**Files:**
- Create: `examples/nexus-fulfillment/src/SharedKernel/Contracts/Orders/OrderStockReserved.php` — `OrderStockReserved(TenantId $tenantId, OrderId $orderId)`, `#[MessageType('orders.order_stock_reserved.v1')]`
- Create: `examples/nexus-fulfillment/src/Orders/Domain/Command/MarkStockReserved.php` — `MarkStockReserved(TenantId $tenantId, OrderId $orderId)`
- Modify: `examples/nexus-fulfillment/src/Orders/Domain/{OrderStatus,OrderState,OrderRules}.php`
- Modify: `examples/nexus-fulfillment/src/Orders/Infrastructure/ReadModel/{OrderView,OrdersViewProjector,OrdersReadModel}.php` (fold the new event into the view)
- Modify: `examples/nexus-fulfillment/src/Platform/Serialization/MessageTypes.php`
- Test: extend `OrderRulesTest`, `OrderStateTest`, `OrdersProjectionTest`

**Interfaces:** `OrderStatus::StockReserved` (`'stock_reserved'`). New decision rows: `MarkStockReserved` on `Placed` → `[OrderStockReserved]`; on `StockReserved` → `[]` (idempotent); on `NotCreated`/`Cancelled` → `Rejection`. **Changed row:** `CancelOrder` on `StockReserved` → `Rejection('cancellation after stock reservation arrives in milestone 4')` — reserved stock must not leak while the saga can't yet compensate user cancels; document in README. `PlaceOrder` on `StockReserved` → `[]` (still idempotent-placed). Evolve: `OrderStockReserved` → status `StockReserved`, everything else preserved. Projector/read model: handle the event (status update).

- [ ] **Step 1:** Failing tests for every new/changed row + evolve + projection fold. RED.
- [ ] **Step 2:** Implement; register the contract in `MessageTypes` (alphabetical). GREEN. Gates clean.
- [ ] **Step 3:** Commit: `feat(fulfillment): order stock-reserved status — saga-driven transition, cancel guarded`

---

### Task 4: FulfillmentProcess saga + manager (TDD) — the centerpiece

**Files:**
- Create: `examples/nexus-fulfillment/src/Fulfillment/Domain/{FulfillmentState,FulfillmentPhase,SagaRules}.php`
- Create: `examples/nexus-fulfillment/src/Fulfillment/Domain/Event/{FulfillmentStarted,ReservationConfirmed,ReservationFailed,FulfillmentCompleted,FulfillmentCompensated}.php` (NO attributes — Domain purity; explicit registry entries)
- Create: `examples/nexus-fulfillment/src/Fulfillment/Application/{FulfillmentProcessActor,ProcessRefFactory,FulfillmentManagerActor}.php`
- Modify: `examples/nexus-fulfillment/src/Platform/Serialization/MessageTypes.php`
- Test: `examples/nexus-fulfillment/tests/Unit/Fulfillment/Domain/SagaRulesTest.php`, `examples/nexus-fulfillment/tests/Integration/Fulfillment/FulfillmentSagaTest.php`

**Design (the saga in one screen):**

```
OrderPlaced ──bus──▶ FulfillmentManagerActor ──▶ ProcessRefFactory::of(tenant, orderId) ──tell──▶ saga
saga: persist FulfillmentStarted{lines} ──thenRun──▶ tell InventoryRef::of(tenant, sku) ReserveStock  (per line)
inventory: persist StockReserved | StockReservationRejected ──publishes to bus──▶ manager routes by orderId ──▶ saga
saga on StockReserved:            persist ReservationConfirmed{sku}; all confirmed?
                                    └─ yes → persist FulfillmentCompleted, thenRun: tell OrderRef MarkStockReserved
saga on StockReservationRejected: persist ReservationFailed{sku, reason} + FulfillmentCompensated{reason},
                                    thenRun: tell InventoryRef ReleaseReservation for EVERY sku in confirmed set,
                                             tell OrderRef CancelOrder('insufficient stock: {sku}')
duplicates / late events on terminal phases → [] (idempotent)
```

**Interfaces:**
- `FulfillmentPhase` enum: `Reserving`, `Completed`, `Compensated` (string-backed).
- `FulfillmentState`: `empty(TenantId, OrderId): self`; `/** @var array<string, int> sku->qty */ public array $pending`; `/** @var list<string> */ public array $confirmed`; `public FulfillmentPhase $phase`; static `evolve` (Started fills pending from lines; Confirmed moves sku pending→confirmed; Failed just records nothing extra; Completed/Compensated set phase); pure.
- Saga events (all `final readonly`, plain — registered explicitly): `FulfillmentStarted(TenantId $tenantId, OrderId $orderId, array $lines /* @param non-empty-list<OrderLine> */)`, `ReservationConfirmed(TenantId $tenantId, OrderId $orderId, Sku $sku)`, `ReservationFailed(TenantId $tenantId, OrderId $orderId, Sku $sku, string $reason)`, `FulfillmentCompleted(TenantId $tenantId, OrderId $orderId)`, `FulfillmentCompensated(TenantId $tenantId, OrderId $orderId, string $reason)`. Registry names per Global Constraints; `FulfillmentState` registered as `fulfillment.process_state.v1`.
- `SagaRules::decide(FulfillmentState, object): list<object>` — NOTE: no Rejection type; a saga never replies. Unknown/late/duplicate → `[]`. Decision table (each row a unit test):
  - `OrderPlaced` on phase Reserving with empty pending (fresh) → `[FulfillmentStarted]`; on non-empty pending or terminal → `[]` (duplicate delivery)
  - `StockReserved` for a pending sku → `[ReservationConfirmed]` … and if it was the LAST pending sku → `[ReservationConfirmed, FulfillmentCompleted]` (two events, one persist)
  - `StockReserved` for non-pending sku (duplicate/late) → `[]`
  - `StockReservationRejected` for a pending sku on Reserving → `[ReservationFailed, FulfillmentCompensated]`
  - anything on `Completed`/`Compensated` → `[]`
- `FulfillmentProcessActor::behavior(TenantId, OrderId, EventStore, SnapshotStore, OrderRefFactory $orders, InventoryRefFactory $inventory, Duration $passivateAfter): Behavior` — ES shell mirroring OrderActor BUT: no bus ref (saga publishes nothing in M3 — YAGNI), side-effects in `thenRun` dispatch on which events were persisted: after `FulfillmentStarted` → tell ReserveStock per pending line; after `FulfillmentCompleted` → tell `MarkStockReserved`; after `FulfillmentCompensated` → tell `ReleaseReservation` per confirmed sku + `CancelOrder`. `PersistenceId::of('FulfillmentProcess', "{tenant}|{orderId}")`; actor name `process-{tenant}.{orderId}`; passivation as usual (terminal sagas passivate away; replay is cheap).
- `ProcessRefFactory(ActorSystem, EventStore, SnapshotStore, OrderRefFactory, InventoryRefFactory, Duration $passivateAfter)` + `of(TenantId, OrderId): ActorRef`.
- `FulfillmentManagerActor::behavior(ProcessRefFactory): Behavior` — stateless `Behavior::receive`: `OrderPlaced|StockReserved|StockReservationRejected` → `of(tenant, orderId)->tell($event)`, everything else ignored (`Behavior::same()`). Carries the at-most-once limitation comment (Global Constraints wording). `public const string ACTOR_NAME = 'fulfillment-manager';`

**CRITICAL side-effect subtlety** (from milestone 2's hard-won facts): `Effect::none()` drops `thenRun`. Since duplicate deliveries decide to `[]`, they take the reply-less no-persist path — use plain `Effect::none()` (no side effects wanted) — correct here. Side effects must fire ONLY off persisted events (at-least-once local semantics on replayed side effects are NOT wanted: `thenRun` does not re-run on replay — recovery rebuilds state only; a saga that crashed after persist-before-tell strands until redelivery. Document this in the class docblock — it's the same at-most-once seam as the bus, resolved by the broker milestone.)

- [ ] **Step 1:** `SagaRulesTest` — every decision row (pure, fast). RED → implement domain → GREEN.
- [ ] **Step 2:** `FulfillmentSagaTest` (Fiber, in-memory stores, REAL bus + manager + inventory + order actors — a mini end-to-end without HTTP):
  - (a) happy path: restock 2 SKUs, publish `OrderPlaced` (2 lines) to the bus → assert by asking the ORDER entity with a duplicate `PlaceOrder` (idempotent ask returns `OrderAccepted{status}`) → status `stock_reserved`; also ask inventory (duplicate `ReserveStock` for a fresh probe orderId sized to remaining stock) or read its reply path to confirm availability reduced.
  - (b) compensation: 1 SKU sufficient + 1 insufficient → order status becomes `cancelled` (reason contains 'insufficient stock'), and the sufficient SKU's available is RESTORED (release worked).
  - (c) saga replay: drive (a) but stop the saga actor after `FulfillmentStarted` persisted (short passivation), then deliver the `StockReserved` events via the manager — respawned saga completes. RED → implement actors/factories/manager → GREEN.
- [ ] **Step 3:** Gates clean. Commit: `feat(fulfillment): fulfillment saga — orchestrated reservation with compensation`

---

### Task 5: Review follow-ups — context isolation, RequiresRole, JSON errors

**Files:**
- Modify: `examples/nexus-fulfillment/deptrac.yaml`
- Modify: `examples/nexus-fulfillment/src/Orders/Infrastructure/Http/{PlaceOrderHandler,ListOrdersHandler,GetOrderHandler,CancelOrderHandler}.php`
- Modify: `examples/nexus-fulfillment/src/Platform/Boot/App.php`
- Test: adjust affected tests

- [ ] **Step 1 — per-context Deptrac isolation:** add context layers (`Orders` → `src/Orders/.*`, `Inventory` → `src/Inventory/.*`, `Fulfillment` → `src/Fulfillment/.*`) alongside the existing structural layers, with a ruleset allowing each context → `[SharedKernel, Nexus]` only (plus the structural layers they already sit in — iterate against real deptrac output; a class belongs to both its context and its structural layer, so get the cross-product allowances right by RUNNING `make deptrac` and adjusting; the acceptance test is Step 2).
- [ ] **Step 2 — prove the fence:** temporarily add `use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderRules;` to an Inventory Domain file → `make deptrac` MUST report a violation; remove it → 0 violations. Record both outputs.
- [ ] **Step 3 — RequiresRole migration:** read `packages/nexus-http-auth/src/Attribute/RequiresRole.php` + `Middleware/AuthorizationMiddleware.php` (+ how wallet-app or package tests register it). Replace all four manual `hasRole('ops')` guards + 403 literals with `#[RequiresRole('ops')]` on the handlers and register `AuthorizationMiddleware` in `App.php` (order: after AuthenticationMiddleware). Verify 403 behavior live in Task 6's transcript.
- [ ] **Step 4 — JSON error mapper:** add `onException` mapper in `App.php` so `HttpException` (the `GenericHttpException(400)` path from `VoParamResolver`) renders `{"error": "..."}` JSON like the other error bodies — check `packages/nexus-http/src/Exception/` for the base class to map.
- [ ] **Step 5:** Full gates incl. `make deptrac`. Commit: `refactor(fulfillment): context fences in deptrac, framework role guards, JSON errors`

---

### Task 6: Inventory read model, HTTP endpoints, wiring, live E2E

**Files:**
- Create: `examples/nexus-fulfillment/src/Inventory/Infrastructure/ReadModel/{InventoryLevel,InventoryReadModel,InventoryLevelsProjector}.php`
- Create: `examples/nexus-fulfillment/src/Inventory/Infrastructure/Http/{RestockRequest,RestockHandler,ListInventoryHandler,InventoryResource}.php`
- Modify: `examples/nexus-fulfillment/src/Platform/Boot/{App,SchemaBootstrap,DoctrineKit}.php`, `src/Platform/Http/Routes.php`, `README.md`
- Test: `examples/nexus-fulfillment/tests/Integration/Inventory/InventoryProjectionTest.php`

**Interfaces:**
- `InventoryLevel` — Doctrine entity, table `inventory_levels`, composite PK `(tenant_id, sku)` (promoted `#[Id]` params — the OrderView pattern), columns `on_hand` int, `reserved` int, `updated_at`; `public private(set)`; mutators `applyRestocked/applyReserved/applyReleased`.
- `InventoryReadModel(EntityManagerPool)` + `apply(object): void` — folds `Restocked`/`StockReserved`/`StockReleased` (rejected = no-op skip); composite-key find; upsert.
- `InventoryLevelsProjector::behavior(InventoryReadModel)` + `ACTOR_NAME = 'inventory-projector'`.
- HTTP: `POST /api/inventory/{sku}/restock` — `Sku $sku` via VoParamResolver, `#[FromBody] RestockRequest(Quantity $quantity)`, `#[RequiresRole('ops')]`; ask entity `Restock`, reply → `InventoryResource(Sku $sku, int $onHand, int $available)` 200. `GET /api/inventory` — tenant-scoped list from `inventory_levels` → `['items' => InventoryResource[]]`.
- Wiring in `App.php`: spawn `inventory-projector` + `fulfillment-manager`, `Subscribe` BOTH to the bus (manager needs OrderPlaced + inventory events; projector needs inventory events); build `InventoryRefFactory` and `ProcessRefFactory`; extend `Routes::register` signature with `InventoryRefFactory`. `SchemaBootstrap` syncs `InventoryLevel::class` too.

- [ ] **Step 1:** Projection test (sqlite file-backed, the OrdersProjectionTest harness): restock → reserve → release sequence yields correct on_hand/reserved; duplicate delivery idempotent; composite tenant isolation (same sku, two tenants). RED → implement → GREEN.
- [ ] **Step 2:** Handlers + resources + wiring + routes. Gates clean.
- [ ] **Step 3 — live E2E (the milestone proof), fresh volumes:**
  ```bash
  docker compose down --volumes && make up && sleep 6
  T="Authorization: Bearer acme-ops-token"; ULID1=01K1B2C3D4E5F6G7H8J9K0M1N2; ULID2=01K1B2C3D4E5F6G7H8J9K0M1N3
  # seed stock
  curl -fsS -X POST -H "$T" -H 'Content-Type: application/json' -d '{"quantity":10}' http://localhost:9090/api/inventory/WIDGET-42/restock
  curl -fsS -H "$T" http://localhost:9090/api/inventory                    # on_hand 10, available 10
  # happy path: place 2 → saga reserves → order becomes stock_reserved
  curl -fsS -X POST -H "$T" -H 'Content-Type: application/json' \
    -d '{"orderId":"'$ULID1'","lines":[{"sku":"WIDGET-42","quantity":2,"unitPrice":{"amount":1999,"currency":"EUR"}}]}' \
    http://localhost:9090/api/orders
  sleep 2 && curl -fsS -H "$T" http://localhost:9090/api/orders/$ULID1     # status stock_reserved
  curl -fsS -H "$T" http://localhost:9090/api/inventory                    # available 8, reserved 2
  # compensation: order 20 → insufficient → cancelled, stock untouched
  curl -fsS -X POST -H "$T" -H 'Content-Type: application/json' \
    -d '{"orderId":"'$ULID2'","lines":[{"sku":"WIDGET-42","quantity":20,"unitPrice":{"amount":1999,"currency":"EUR"}}]}' \
    http://localhost:9090/api/orders
  sleep 2 && curl -fsS -H "$T" http://localhost:9090/api/orders/$ULID2     # status cancelled, reason insufficient stock
  curl -fsS -H "$T" http://localhost:9090/api/inventory                    # still available 8
  # cancel-after-reservation guarded (M4 note)
  curl -s -o /dev/null -w '%{http_code}\n' -X DELETE -H "$T" http://localhost:9090/api/orders/$ULID1   # 409
  # saga survives restart mid-state: journal shows the full story
  docker compose exec db psql -U fulfillment -d fulfillment -c \
    "select persistence_id, sequence_nr, event_type from nexus_event_journal order by persistence_id, sequence_nr;"
  # expect: Order|..., InventoryItem|acme|WIDGET-42 (restocked, reserved x2 incl. one rejected), FulfillmentProcess|... (started, confirmed, completed / started, failed, compensated)
  make down
  ```
  Capture every output. Any `sleep 2` proving insufficient → increase, don't fake.
- [ ] **Step 4:** README — milestone 3 status, inventory endpoints, saga description incl. the at-most-once limitation note and the cancel-after-reservation 409. `make ci` full battery. Commit: `feat(fulfillment): inventory vertical + saga wiring — live reserve/compensate flows`

---

## Done means

- Live, fresh volumes: restock → place → order `stock_reserved` + inventory decremented; oversized order → `cancelled` with insufficient-stock reason + previously reserved stock released/untouched; journal shows the three-way conversation (Order, InventoryItem, FulfillmentProcess streams) in wire names.
- Saga proven recoverable: replay test (Task 4c) green — a saga stopped after `FulfillmentStarted` resumes and completes when its expected events arrive.
- Deptrac context fences proven by the red/green Step-2 demonstration; role guards are framework-attribute-based; VO-param 400s render JSON.
- All gates green; every persisted class registry-named; milestone 4 (Warehouse/Shipping + timers) can consume `OrderStockReserved` from the bus and the same saga file.

---

## AMENDMENT A (user directive, 2026-07-04): aggregate-style domains, all rejections as events

Supersedes the functional DECIDE/EVOLVE style in Tasks 1–4 and the milestone-2 Orders domain. Inserted as **Task 4** (refactor); former Tasks 4/5/6 become 5/6/7 unchanged except where noted.

**Target design (binds Task 4 and the saga):**
- Rich aggregate roots (`Order`, `InventoryItem`, later `FulfillmentProcess`) replace `{OrderState+OrderRules}` / `{ItemState+InventoryRules}`. Behavior methods on the aggregate (`place()`, `cancel()`, `markStockReserved()`, `restock()`, `reserve()`, `release()`) enforce invariants and RECORD events; the actor is a thin orchestrator: match command → call method → drain `releaseEvents()` → `Effect::persist`/reply. No decision logic in actors.
- **All rejections are persisted events** (full Verraes): new marker interface `SharedKernel\Contracts\RejectionEvent` (`public string $reason { get; }` — or plain readonly property via interface constant shape; implementer picks the cleanest PHP 8.5 interface form). New Orders rejection contracts: `OrderPlacementRejected`, `OrderCancellationRejected`, `MarkStockReservedRejected` (wire `orders.order_placement_rejected.v1` etc.). Inventory's existing `StockReservationRejected` implements the marker. Rejection events fold as no-ops. Idempotent repeats record NOTHING (a repeat is not a business fact).
- Replies derived from recorded events: any drained event `instanceof RejectionEvent` → reply Rejected(reason); none recorded → reply Accepted(current state) via `Effect::reply`; else persist + thenRun(publish all + reply Accepted from `$next`). Unknown commands → the actor's match default returns the framework's unhandled path (dead letters), NOT a persisted event.
- **Aggregate mechanics (CRITICAL):** mutable aggregate, `public private(set)` state (no getters), PUBLIC constructor over state fields (Valinor snapshot target; `private array $recorded = []` stays out of serialization), `apply(object $event): void` mutates state per event, `record(object $event): void` APPENDS ONLY — it must NOT self-apply, because `PersistenceEngine` folds persisted events through the event handler (`fn(Order $o, object $e): Order => { $o->apply($e); return $o; }`); self-applying would double-apply. Methods therefore read PRE-command state — identical semantics to the old decide().
- Wire-name continuity: keep `orders.order_state.v1` / `inventory.item_state.v1` mapped to the new aggregate classes with UNCHANGED public field names/shapes (snapshot compatibility); note it in MessageTypes comments.
- Decision semantics are FROZEN: every row of the existing OrderRulesTest/InventoryRulesTest tables carries over 1:1 into aggregate-method tests (rejection rows now assert a recorded RejectionEvent instead of a returned Rejection). The `Rejection` value classes are deleted.
- The former Task 3's review was folded into Task 4's review (the code it validated is restructured here; the reviewer must confirm all Task 3 semantics survived).

### Task 4 (NEW): Aggregate refactor — Orders + Inventory (TDD-preserving)

Files: rewrite `src/Orders/Domain/` (`Order` aggregate replaces OrderState/OrderRules; delete Rejection), `src/Inventory/Domain/` (`InventoryItem` aggregate replaces ItemState/InventoryRules; delete its Rejection), add the marker + three Orders rejection contracts, rewrite both actors' command handlers as thin orchestrators, convert both domain test suites, update MessageTypes, keep projection/HTTP behavior identical (HTTP replies unchanged in shape). Steps: convert tests first (RED against missing aggregate), implement aggregates, rewire actors, all integration tests must pass UNMODIFIED except imports/reply plumbing, four gates green. Commit: `refactor(fulfillment): rich aggregates — thin actors, rejections as recorded events`

### Deltas to renumbered tasks
- **Task 5 (saga)**: `FulfillmentProcess` is an aggregate in the same style (`start()`, `confirmReservation()`, `rejectReservation()` recording the saga events; `SagaRules` is not built). The manager and side-effect wiring are unchanged. Saga has no rejection events (it never says no — late/duplicate messages record nothing).
- **Task 6/7**: unchanged.
