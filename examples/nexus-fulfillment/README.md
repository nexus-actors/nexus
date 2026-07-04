# nexus-fulfillment

Order-fulfillment reference application for the Nexus actor system —
event-sourced entity actors, a fulfillment saga with compensation,
WebSockets, and full OpenTelemetry observability. Built step by step;
the tutorial lives in `docs/tutorial/` (arrives with later milestones).

**Status: milestone 3 — Inventory + fulfillment saga.** Adds event-sourced
InventoryItem entities, an inventory read model + HTTP vertical, and a
per-order fulfillment saga that reserves stock across contexts and
compensates (releases holds, cancels the order) on insufficient stock —
all proven by a fresh-volume live end-to-end drive.

## Run it

    make build      # PHP 8.5 ZTS + Swoole 6.2 image
    make install    # composer install inside the container
    make up         # server on http://localhost:9090
    curl localhost:9090/healthz
    curl localhost:9090/readyz

## Auth tokens (milestone 2)

Static bearer tokens are wired in `DemoTokens` for local dev. Each token
carries a `sub` (user), `role` (ops|picker), and `tenant` claim:

| Token                 | sub              | role   | tenant   |
|-----------------------|------------------|--------|----------|
| `acme-ops-token`      | ops@acme         | ops    | acme     |
| `acme-picker-token`   | picker@acme      | picker | acme     |
| `umbrella-ops-token`  | ops@umbrella     | ops    | umbrella |

## Orders API (milestone 2)

All endpoints require `Authorization: Bearer <token>` and `role = ops`.

### Place order — `POST /api/orders`

```bash
curl -X POST http://localhost:9090/api/orders \
  -H "Authorization: Bearer acme-ops-token" \
  -H "Content-Type: application/json" \
  -d '{"orderId":"01K...","lines":[{"sku":"WIDGET-42","quantity":2,"unitPrice":{"amount":1999,"currency":"EUR"}}]}'
# → 201 {"orderId":{"value":"01K..."},"status":"placed","total":{"amount":3998,"currency":"EUR"}}
```

**Idempotency model:** `orderId` is caller-assigned (a ULID). Sending the
same `orderId` a second time returns `201` again — the actor returns the
existing state without persisting a new event. The retry body is ignored;
the original order's data is returned unchanged regardless of what the
retry carries. Placing a cancelled order returns `409`.

### List orders — `GET /api/orders`

```bash
curl http://localhost:9090/api/orders \
  -H "Authorization: Bearer acme-ops-token"
# → 200 {"orders":[{"cancelReason":null,"lineCount":2,"orderId":{"value":"01K..."},"status":"placed","total":{"amount":3998,"currency":"EUR"},"updatedAt":"2024-01-01T00:00:00+00:00"},...]}
```

Results are scoped to the caller's tenant and sorted by `updatedAt DESC`
(up to 100 rows). Cross-tenant isolation is enforced: `umbrella-ops-token`
sees only umbrella orders.

### Get order — `GET /api/orders/{id}`

```bash
curl http://localhost:9090/api/orders/<ULID> \
  -H "Authorization: Bearer acme-ops-token"
# → 200 {"cancelReason":null,"lineCount":2,"orderId":{"value":"01K..."},"status":"placed","total":{"amount":3998,"currency":"EUR"},"updatedAt":"2024-01-01T00:00:00+00:00"}
# → 404 if the order does not exist or belongs to a different tenant
```

### Cancel order — `DELETE /api/orders/{id}`

```bash
curl -X DELETE http://localhost:9090/api/orders/<ULID> \
  -H "Authorization: Bearer acme-ops-token"
# → 200 {"orderId":{"value":"01K..."},"status":"cancelled","total":{"amount":3998,"currency":"EUR"}}
# → 409 {"orderId":"...","reason":"Order does not exist"}  (unknown id)
```

Cancelling an already-cancelled order is idempotent (returns `200`).

## Inventory API (milestone 3)

All endpoints require `Authorization: Bearer <token>` and `role = ops`.
Stock is an event-sourced `InventoryItem` entity per `(tenant, sku)`; the
`inventory_levels` read model is a tenant-scoped projection folded from the
context bus.

### Restock — `POST /api/inventory/{sku}/restock`

```bash
curl -X POST http://localhost:9090/api/inventory/WIDGET-42/restock \
  -H "Authorization: Bearer acme-ops-token" \
  -H "Content-Type: application/json" \
  -d '{"quantity":10}'
# → 200 {"sku":{"value":"WIDGET-42"},"onHand":10,"available":10}
```

`{sku}` is resolved from the path into a `Sku` value object; a malformed SKU
returns `400`. `quantity` must be a positive integer.

### List inventory — `GET /api/inventory`

```bash
curl http://localhost:9090/api/inventory \
  -H "Authorization: Bearer acme-ops-token"
# → 200 {"items":[{"sku":{"value":"WIDGET-42"},"onHand":10,"available":8}]}
```

Results are scoped to the caller's tenant and sorted by `sku ASC`. `available`
is `on_hand − reserved`; the same SKU under two tenants yields two distinct
rows (composite `(tenant_id, sku)` key).

## Fulfillment saga

Placing an order publishes `OrderPlaced` on the in-process context bus. The
`fulfillment-manager` routes it to a per-order `FulfillmentProcess` saga, which
persists `FulfillmentStarted` and sends `ReserveStock` to each line's inventory
entity:

- **Happy path** — every line reserves. The saga confirms each reservation,
  persists `FulfillmentCompleted`, and tells the order `MarkStockReserved`; the
  order becomes `stock_reserved` and inventory `reserved` rises.
- **Compensation** — any line rejects (insufficient stock). The saga persists
  `FulfillmentCompensated`, releases the union of confirmed + in-flight holds
  (`ReleaseReservation`), and cancels the order (`CancelOrder`) with the
  `insufficient stock` reason. Previously reserved stock is returned; already
  reserved orders in the same tenant are untouched.

The saga is journal-backed and passivates after idle; a saga stopped mid-flight
resumes from its journal when the expected events arrive (covered by the
replay test). Cancelling an order after its stock is reserved is guarded and
returns `409` (the reservation lifecycle owns that transition — a milestone-4
concern).

## Known limitations

- **At-most-once bus seam**: the `ContextBus` delivers events in-process only. A crash
  between an entity persisting an event and publishing it to the bus loses that delivery
  for live saga subscribers. The event journal preserves the fact, but the saga does not
  replay from the journal. Journal-backed subscriptions and an outbox pattern resolve this
  in the broker milestone.
- **Compensation sub-race**: when a reservation rejection races ahead of a confirmation
  (`StockReservationRejected(B)` reaches the saga before `StockReserved(A)`), the
  `FulfillmentProcessActor` releases the union of confirmed + in-flight (pending) SKUs so
  that A's hold is freed. A residual sub-race remains: if `ReleaseReservation(A)` reaches
  the inventory entity *before* the original `ReserveStock(A)` command is processed, the
  release lands first and the reserve creates a hold afterwards — A leaks permanently.
  Journal-backed delivery (broker milestone) closes this by ensuring the release is only
  dispatched once the full reserve round-trip completes.

## Quality gates

    make ci         # phpunit + psalm + deptrac + php-cs-fixer + phpcs

This is a standalone Composer project inside the Nexus monorepo. To use it
as a starter outside the monorepo, copy the folder out and either keep a
sibling checkout of the Nexus `packages/` tree (adjusting the
`../../packages` mount in `compose.yaml`) or replace the `/nexus-packages`
autoload paths in `composer.json` with the published `nexus-actors/*`
Packagist packages.
