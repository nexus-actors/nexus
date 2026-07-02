---
sidebar_position: 5
title: Metrics
---

# Metrics

Nexus OTel observability exposes metrics via the OpenTelemetry SDK. All metrics are exported via the configured OTLP exporter. Prometheus pull mode is also supported by running an `otel-collector` that scrapes the OTLP receiver and exposes a `/metrics` endpoint.

## Actor system

| Name | Type | Unit | Key Dimensions | Description |
|---|---|---|---|---|
| `nexus.actor.messages.processed` | Counter | `{message}` | `nexus.message.type` | Messages successfully processed by an actor |
| `nexus.actor.message.processing.duration` | Histogram | `ms` | `nexus.message.type` | Time from mailbox dequeue to handler return |

## Actor system state

Requires `nexus-observability-actor` with `ActorSystemMetrics` registered.

| Name | Type | Unit | Description |
|---|---|---|---|
| `nexus.actor_system.live_actors` | Observable Gauge | `{actor}` | Number of live root actors in the system |
| `nexus.actor_system.dead_letters` | Observable Gauge | `{message}` | Total dead-lettered messages captured by the system |
| `nexus.actor_system.running` | Observable Gauge | `{system}` | Whether the actor system runtime is running (1) or not (0) |

## HTTP server

Requires `nexus-observability-http` with `ServerSpanMiddleware` and `HttpMetricsListener` registered.

| Name | Type | Unit | Key Dimensions | Description |
|---|---|---|---|---|
| `http.server.request.duration` | Histogram | `s` | `http.request.method`, `http.response.status_code` | End-to-end HTTP request duration |
| `http.server.active_requests` | UpDownCounter | `{request}` | `http.request.method` | Number of in-flight HTTP server requests |

## Persistence

Requires `nexus-observability-persistence` with `TracingEventStore`, `TracingSnapshotStore`, and/or `TracingDurableStateStore` wrappers.

| Name | Type | Unit | Key Dimensions | Description |
|---|---|---|---|---|
| `nexus.persistence.events.persisted` | Counter | `{event}` | `nexus.persistence.entity.type` | Events written to the event store |
| `nexus.persistence.snapshots.saved` | Counter | `{snapshot}` | `nexus.persistence.entity.type` | Snapshots written to the snapshot store |
| `nexus.persistence.operation.duration` | Histogram | `s` | `nexus.persistence.entity.type`, `operation` | Duration of persistence store operations |

The `nexus.persistence.entity.type` dimension uses the entity type portion of the `PersistenceId` (e.g., `Order` from `PersistenceId::of('Order', $id)`), keeping cardinality fixed regardless of how many entity instances exist.

## Worker pool

Requires `nexus-observability-worker-pool` with `TracingWorkerTransport` installed.

| Name | Type | Unit | Key Dimensions | Description |
|---|---|---|---|---|
| `nexus.worker_pool.messages.sent` | Counter | `{message}` | `nexus.worker.target` | Messages sent across worker threads |
| `nexus.worker_pool.send.duration` | Histogram | `s` | `nexus.worker.target` | Duration of cross-worker transport sends |

## DBAL connection pool

Requires `nexus-observability-doctrine` with `DbalPoolMetricsListener` registered.

| Name | Type | Unit | Key Dimensions | Description |
|---|---|---|---|---|
| `nexus.dbal.pool.connections.created` | Counter | `{connection}` | `pool.name` | Connections created in the pool |
| `nexus.dbal.pool.connections.taken` | Counter | `{connection}` | `pool.name` | Connections checked out from the pool |
| `nexus.dbal.pool.connections.released` | Counter | `{connection}` | `pool.name` | Connections returned to the pool |
| `nexus.dbal.pool.connections.destroyed` | Counter | `{connection}` | `pool.name` | Connections removed from the pool |
| `nexus.dbal.pool.connections.poisoned` | Counter | `{connection}` | `pool.name` | Connections poisoned (unusable) and removed |
| `nexus.dbal.pool.exhausted` | Counter | `{connection}` | `pool.name` | Pool-exhausted events (acquire blocked) |
| `nexus.dbal.pool.acquire.wait` | Histogram | `s` | `pool.name` | Time spent waiting to acquire a pooled connection |

## Doctrine ORM pool

Requires `nexus-observability-doctrine` with `OrmPoolMetricsListener` registered.

| Name | Type | Unit | Key Dimensions | Description |
|---|---|---|---|---|
| `nexus.orm.pool.entity_managers.created` | Counter | `{entity_manager}` | `pool.name` | Entity managers created in the pool |
| `nexus.orm.pool.entity_managers.cleared` | Counter | `{entity_manager}` | `pool.name` | Entity managers cleared and returned to the pool |
| `nexus.orm.pool.entity_managers.evicted` | Counter | `{entity_manager}` | `pool.name` | Entity managers evicted from the pool |

## Swoole runtime

Requires `nexus-observability-swoole` with `SwooleAdminMetrics` registered in each worker.

| Name | Type | Unit | Description |
|---|---|---|---|
| `swoole.coroutine.count` | Observable Gauge | `{coroutine}` | Number of running Swoole coroutines |
| `swoole.coroutine.peak` | Observable Gauge | `{coroutine}` | Peak number of concurrent Swoole coroutines |
| `swoole.server.connections` | Observable Gauge | `{connection}` | Active Swoole server connections |
| `swoole.server.requests` | Observable Gauge | `{request}` | Total requests handled by the Swoole server |
| `swoole.server.workers.idle` | Observable Gauge | `{worker}` | Idle Swoole worker processes |

## Cardinality guidance

:::tip
The `nexus.message.type` dimension uses the message class short name (e.g., `PlaceOrder`), not the FQCN. Similarly, `nexus.persistence.entity.type` uses only the entity-type prefix of a `PersistenceId`, keeping cardinality bounded regardless of how many entity instances exist.

Never add user IDs, session IDs, or request IDs to built-in metric dimensions. Each unique combination of dimension values creates a new time series in your metrics backend. High-cardinality dimensions cause storage and query problems at scale.

For per-entity or per-user analysis, use traces (which carry high-cardinality data in span attributes) rather than metrics.
:::
