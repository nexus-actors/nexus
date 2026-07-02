---
sidebar_position: 5
title: Metrics
---

# Metrics

Nexus OTel observability exposes metrics via the OpenTelemetry SDK. All metrics are exported via the configured OTLP exporter. Prometheus pull mode is also supported by running an `otel-collector` that scrapes the OTLP receiver and exposes a `/metrics` endpoint.

## Actor system

| Name | Type | Unit | Key Dimensions | Description |
|---|---|---|---|---|
| `nexus.actor.messages.processed` | Counter | `{message}` | `actor_path`, `message_type` | Messages successfully processed by an actor |
| `nexus.actor.messages.failed` | Counter | `{message}` | `actor_path`, `message_type`, `exception` | Messages that caused an unhandled exception |
| `nexus.actor.mailbox.size` | UpDownCounter | `{message}` | `actor_path` | Current number of messages in an actor's mailbox |
| `nexus.actor.processing.duration` | Histogram | `ms` | `actor_path`, `message_type` | Time from mailbox dequeue to handler return |
| `nexus.actor_system.actors.active` | Observable Gauge | `{actor}` | `system_name` | Number of live actors in the system |

## HTTP server

Requires `nexus-observability-http` with `ServerSpanMiddleware` and `HttpMetricsListener` registered.

| Name | Type | Unit | Key Dimensions | Description |
|---|---|---|---|---|
| `http.server.request.duration` | Histogram | `ms` | `http.method`, `http.route`, `http.status_code` | End-to-end HTTP request duration |
| `http.server.request.body.size` | Histogram | `By` | `http.method`, `http.route` | Size of the incoming request body |
| `http.server.response.body.size` | Histogram | `By` | `http.method`, `http.route`, `http.status_code` | Size of the outgoing response body |

## Persistence

Requires `nexus-observability-persistence` with `TracingEventStore` and `TracingSnapshotStore` wrappers.

| Name | Type | Unit | Key Dimensions | Description |
|---|---|---|---|---|
| `nexus.persistence.events.persisted` | Counter | `{event}` | `persistence_id_type` | Events written to the event store |
| `nexus.persistence.snapshots.taken` | Counter | `{snapshot}` | `persistence_id_type` | Snapshots written to the snapshot store |
| `nexus.persistence.recovery.duration` | Histogram | `ms` | `persistence_id_type` | Time to replay events and restore actor state on startup |

The `persistence_id_type` dimension uses the entity type portion of the `PersistenceId` (e.g., `Order` from `PersistenceId::of('Order', $id)`), keeping cardinality fixed regardless of how many entity instances exist.

## Worker pool

Requires `nexus-observability` with `TracingWorkerTransport` installed.

| Name | Type | Unit | Key Dimensions | Description |
|---|---|---|---|---|
| `nexus.worker_pool.messages.routed` | Counter | `{message}` | `source_worker`, `target_worker` | Messages routed between worker threads |

## DBAL connection pool

Requires `nexus-observability-dbal` with `DbalPoolMetricsListener` registered.

| Name | Type | Unit | Key Dimensions | Description |
|---|---|---|---|---|
| `nexus.dbal.pool.connections.active` | Observable Gauge | `{connection}` | `pool_name` | Connections currently checked out from the pool |
| `nexus.dbal.pool.connections.idle` | Observable Gauge | `{connection}` | `pool_name` | Connections idle in the pool |
| `nexus.dbal.pool.wait.duration` | Histogram | `ms` | `pool_name` | Time waiting for a connection to become available |

## Doctrine ORM pool

Requires `nexus-observability-dbal` with `OrmPoolMetricsListener` registered.

| Name | Type | Unit | Key Dimensions | Description |
|---|---|---|---|---|
| `nexus.orm.pool.managers.active` | Observable Gauge | `{manager}` | `pool_name` | Entity managers currently checked out from the pool |

## Swoole runtime

Requires `nexus-observability-swoole` with `SwooleAdminMetrics` registered in each worker.

| Name | Type | Unit | Key Dimensions | Description |
|---|---|---|---|---|
| `swoole.coroutine.count` | Observable Gauge | `{coroutine}` | `worker_id` | Active coroutines in the Swoole worker |
| `swoole.server.connections` | Observable Gauge | `{connection}` | `worker_id` | Open connections handled by the Swoole worker |
| `swoole.server.requests.total` | Counter | `{request}` | `worker_id` | Total requests processed by the Swoole worker |

## Cardinality guidance

:::tip
The `actor_path` dimension uses the actor's **fixed structural path** (e.g., `/orders`), not a path containing dynamic IDs. The Nexus runtime strips dynamic segments automatically. Similarly, `message_type` uses the class short name (e.g., `PlaceOrder`), not the FQCN.

Never add user IDs, session IDs, or request IDs to built-in metric dimensions. Each unique combination of dimension values creates a new time series in your metrics backend. High-cardinality dimensions cause storage and query problems at scale.

For per-entity or per-user analysis, use traces (which carry high-cardinality data in span attributes) rather than metrics.
:::
