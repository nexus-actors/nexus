# nexus-fulfillment

Order-fulfillment reference application for the Nexus actor system —
event-sourced entity actors, a fulfillment saga with compensation,
WebSockets, and full OpenTelemetry observability. Built step by step;
the tutorial lives in `docs/tutorial/` (arrives with later milestones).

**Status: milestone 2 — Orders HTTP vertical.** Event-sourced Order
entity actors, tenant-scoped read models, idempotent place/cancel API,
static bearer-token auth, and a full live end-to-end test drive.

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
  -d '{"orderId":"<ULID>","lines":[{"sku":"WIDGET-01","quantity":2,"unitPriceCents":1999,"currency":"USD"}]}'
# → 201 {"orderId":"...","status":"placed","totalCents":3998}
```

**Idempotency model:** `orderId` is caller-assigned (a ULID). Sending the
same `orderId` a second time returns `201` again — the actor returns the
existing state without persisting a new event. Placing a cancelled order
returns `409`.

### List orders — `GET /api/orders`

```bash
curl http://localhost:9090/api/orders \
  -H "Authorization: Bearer acme-ops-token"
# → 200 {"orders":[...]}   ← only the calling tenant's orders
```

Results are scoped to the caller's tenant and sorted by `updatedAt DESC`
(up to 100 rows). Cross-tenant isolation is enforced: `umbrella-ops-token`
sees only umbrella orders.

### Get order — `GET /api/orders/{id}`

```bash
curl http://localhost:9090/api/orders/<ULID> \
  -H "Authorization: Bearer acme-ops-token"
# → 200 {"orderId":"...","status":"placed","totalCents":3998,"cancelReason":null,...}
# → 404 if the order does not exist or belongs to a different tenant
```

### Cancel order — `DELETE /api/orders/{id}`

```bash
curl -X DELETE http://localhost:9090/api/orders/<ULID> \
  -H "Authorization: Bearer acme-ops-token"
# → 200 {"orderId":"...","status":"cancelled","totalCents":3998}
# → 409 {"orderId":"...","reason":"Order does not exist"}  (unknown id)
```

Cancelling an already-cancelled order is idempotent (returns `200`).

## Quality gates

    make ci         # phpunit + psalm + deptrac + php-cs-fixer + phpcs

This is a standalone Composer project inside the Nexus monorepo. To use it
as a starter outside the monorepo, copy the folder out and either keep a
sibling checkout of the Nexus `packages/` tree (adjusting the
`../../packages` mount in `compose.yaml`) or replace the `/nexus-packages`
autoload paths in `composer.json` with the published `nexus-actors/*`
Packagist packages.
