# Nexus Observability — Design Specification

**Date:** 2026-07-01
**Branch:** `feat/observability`
**Status:** Approved design, pre-implementation

## 1. Goal

Add first-class OpenTelemetry (OTEL) support to Nexus across all three pillars —
**traces, metrics, and logs** — covering every surface: actors, HTTP (server,
client, WebSocket), persistence (event/snapshot/state stores), and the
worker-pool / cluster transport. Distributed tracing must propagate context
across local, cross-thread (worker-pool), and cross-node (cluster) boundaries,
and across HTTP. A single HTTP request produces **one connected end-to-end trace**
spanning request → actor(s) → response (§7). Users can add **custom spans and
metrics** anywhere via a first-class API (§5.1). Telemetry is **disabled by
default** (no-op, zero overhead) and opt-in via standard OTEL env vars, a
programmatic builder, or the skeleton wizard.

## 2. Design Decisions (locked)

| # | Decision | Choice |
|---|---|---|
| D1 | Scope | One comprehensive spec, **phased** implementation (foundation + actors first, then satellites). |
| D2 | Integration boundary | **Nexus-native vendor-neutral interfaces** + no-op default; separate OTEL bridge package. `nexus-core` never depends on the OTEL SDK. |
| D3 | Export model | **OTLP → OpenTelemetry Collector**, batch processors, **Swoole-coroutine-aware non-blocking export** in production. |
| D4 | Configuration | All of: **standard OTEL env vars**, **programmatic builder** on `NexusApp`, **skeleton wizard** step, **disabled by default** (zero-overhead no-op). |
| D5 | Span/log attributes | **Metadata only** by default (class names, paths, sizes, status, durations). **Never** message/request payloads. Opt-in payload capture per-type. |
| D6 | Instrumentation granularity | **Satellite packages per pluggable surface** (HTTP, persistence, worker-pool). **Actors instrument inside core** (no per-message extension seam exists outside core). |
| D7 | Propagation format | **W3C Trace Context** (`traceparent` / `tracestate`). Not B3/Jaeger. |
| D8 | Default protocol | **OTLP/HTTP** (gRPC optional). |
| D9 | Meta-package | New packages are **not** bundled into the `nexus` meta-package — observability stays opt-in. |
| D10 | Default sampler | **ParentBased(AlwaysOn)** when enabled; overridable via `OTEL_TRACES_SAMPLER`. Head/tail sampling delegated to the Collector. |
| D11 | Metric cardinality | **Low-cardinality dimensions only** (actor behavior/class name, message class). **Not** per-instance `actor.path` on metrics. Spans keep full `actor.path`. |
| D12 | `correlationId` ↔ trace-id | **Keep independent, stamp both.** No change to existing envelope-ID semantics; `traceparent` added alongside. Logs/spans carry both. |
| D13 | Logs pillar scope (v1) | **MDC correlation + OTLP log export.** `trace_id`/`span_id`/`correlation_id` on every record *and* an optional OTLP log-record exporter. |
| D14 | Traced messages | **User messages only** get Consumer spans. System messages + lifecycle signals emit metrics only (no spans). |
| D15 | Baggage | **W3C Baggage in scope.** Propagate the `baggage` header alongside trace context via a composite propagator; `Context` carries a `Baggage` map; user-settable from actor handlers. |
| D16 | `service.name` default | Defaults to the `ActorSystem` name when `OTEL_SERVICE_NAME` is unset. Resource also carries runtime type, `writer_id`, `worker.id`. |
| D17 | Payload capture opt-in | Only when the message class carries a **`#[TracePayload]`** attribute (mirrors the existing `#[MessageType]` convention). Default off. |
| D18 | Semantic conventions | Target current **stable** OTEL semconv (HTTP, messaging) via `open-telemetry/sem-conv`; Nexus-specific attributes under the `nexus.*` namespace. |
| D19 | End-to-end HTTP trace | A single request yields **one connected trace** HTTP SERVER → ask CLIENT → actor CONSUMER → downstream, crossing HTTP/thread/node boundaries transparently (§7 "End-to-end trace"). |
| D20 | Custom instrumentation | **First-class, documented API** for custom spans + metrics from actor handlers (`ActorContext`) and elsewhere (`Observability` provider); no-op when disabled (§5.1). |
| D21 | Doctrine instrumentation | New satellite `nexus-observability-doctrine`: DBAL pool + SQL spans, ORM EM-pool + transaction spans, and **entity actor-repository** (`EntityRefFactory`) spans/metrics. Parameterized SQL text only (no bound values). |
| D22 | Documentation | In scope: **Docusaurus** guide + package pages, **phpDocumentor** API wiring + PHPDoc, **Astro landing** page (coordinate with `astro-landing-1`). Per-package READMEs out of scope (§15). |
| D23 | Swoole admin metrics | Expose **Swoole server/admin stats** as OTEL observable gauges (connections, requests, active workers, coroutine count, etc. from `Swoole\Server::stats()` / `Coroutine::stats()`), in the `nexus-observability-swoole` package alongside async export. |
| D24 | Internal actor-system metrics | Expose **actor-system internal state** as OTEL observable gauges (live actor count, dead-letter total, scheduled-timer count, and runtime fiber/coroutine count where available) via a registrar bound to an `ActorSystem` + the `Observability` meter. |
| D25 | Entity actor-repository spans | `EntityRefFactory` is a `final`, builder-constructed class with no interface/events — entity `resolve/replay/persist` spans require a **base-package seam** (extract an interface or add PSR-14 lifecycle events). Deferred to a dedicated follow-up; DBAL SQL spans + pool metrics (Plan 7) cover the bulk of Doctrine tracing meanwhile. |

## 3. Package Layout

Seven new packages (26 → 33), each split to its own repo via `split.yml`.

### Foundation

| Package | Depends on | Purpose |
|---|---|---|
| `nexus-observability` | *nothing* (foundational, like core) | Vendor-neutral interfaces (`Tracer`, `Span`, `Meter`, instruments, `ContextPropagator`, `Context`), **no-op default** implementations, `ObservabilityConfig`, `Observability` provider. |
| `nexus-observability-otel` | `nexus-observability`, `open-telemetry/api`, `open-telemetry/sdk`, `open-telemetry/exporter-otlp` | Bridge: builds real Tracer/Meter from the OTEL SDK, OTLP exporters, W3C propagator, samplers, resource detection, env-var config. |
| `nexus-observability-swoole` | `nexus-observability-otel`, `nexus-runtime-swoole`, `open-telemetry/context-swoole` | Swoole-coroutine OTLP transport (non-blocking export) built on `SwooleContextStorage` + `OtlpHttpTransportFactory`, background flush coroutine, shutdown force-flush hook. |

### Satellite instrumentation

| Package | Depends on | Purpose |
|---|---|---|
| `nexus-observability-http` | `nexus-observability`, `nexus-http`, `nexus-http-toolkit` | Server + client + WS middleware; HTTP semconv spans + RED metrics. |
| `nexus-observability-persistence` | `nexus-observability`, `nexus-persistence` | Event/snapshot/state store decorators; replay/recovery spans + metrics. |
| `nexus-observability-worker-pool` | `nexus-observability`, `nexus-worker-pool` | Transport/cluster metrics, hash-ring/node-address attributes. |
| `nexus-observability-doctrine` | `nexus-observability`, `nexus-doctrine-dbal`, `nexus-doctrine-orm` | DBAL connection-pool + SQL spans, ORM EntityManager-pool + transaction spans, and **entity actor-repository** (`EntityRefFactory`) spans/metrics. |

### In core

`nexus-core` gains an allowed deptrac edge to `nexus-observability` **only**. Actor
message-loop tracing and core actor metrics live in core, driven by the injected
`Observability` provider (no-op default → zero cost when disabled).

**PHP 8.5 compatibility (assessed):** `open-telemetry/sdk` (1.14.x) requires
`php: ^8.1` with an open upper bound, so Composer installs it on the project's
PHP 8.5.7 image; the project's support policy tracks all supported PHP versions.
A phase-1 smoke spike still runs `open-telemetry/sdk` + a real OTLP export under
8.5 to catch deprecation noise, but no blocking incompatibility is expected. The
native interface layer insulates all other packages regardless. Swoole trace
delivery has been reported as occasionally flaky in the ecosystem — this
reinforces the explicit-propagation + coroutine-storage + shutdown-force-flush
approach and mandates dedicated Swoole integration tests.

## 4. The Abstraction API (`nexus-observability`)

A minimal subset of the OTEL data model — enough to instrument, small enough to
no-op cheaply.

### Tracing
- `Tracer::startSpan(string $name, SpanKind $kind, array $attributes = [], ?Context $parent = null): Span`
- `Span`: `setAttribute()`, `setAttributes()`, `addEvent()`, `recordException()`,
  `setStatus(StatusCode, ?string)`, `end()`, `context(): SpanContext`
- `SpanKind` enum: `Internal | Server | Client | Producer | Consumer`
- `StatusCode` enum: `Unset | Ok | Error`
- `SpanContext`: trace id, span id, sampled flag, remote flag

### Metrics
- `Meter`: `counter()`, `upDownCounter()`, `histogram()`, `observableGauge()`
- Instruments: `Counter::add(int|float, array $attributes)`,
  `UpDownCounter::add(...)`, `Histogram::record(...)`,
  `ObservableGauge` (callback-based)

### Context propagation
- `ContextPropagator`: `inject(Context $ctx, array &$carrier): void` /
  `extract(array $carrier, ?Context $context = null): Context` (the optional
  accumulator lets propagators compose).
- Implements **W3C Trace Context** (`traceparent`/`tracestate`) **and W3C
  Baggage** (`baggage`), combined via a `CompositePropagator`. Carrier is
  `array<string,string>`, which maps 1:1 onto both `Envelope::$metadata` and HTTP
  headers — one mechanism for all boundaries.
- `Baggage`: immutable `array<string,string>` map on `Context`
  (`get`/`with`/`all`/`isEmpty`), set from handlers to carry cross-cutting values
  (e.g. `tenant.id`) across service/actor boundaries. Baggage propagates even when
  a span is not sampled.

### Provider
- `Observability` bundles `tracer(): Tracer`, `meter(): Meter`,
  `propagator(): ContextPropagator`.
- Default `NoopObservability` returns shared no-op singletons (no allocation on
  hot paths).

### Context handling
Context is **propagated explicitly** through envelopes/headers — actors are
message-driven, so ambient thread-locals do not survive async hops. Ambient
"current span" is used only *within* a single synchronous handler execution, via
the OTEL bridge's Fiber/Swoole-aware context storage.

## 5. Actor Instrumentation (in core)

Single chokepoint: `ActorCell::processMessage()`.

Only **user messages** are traced. System messages (`PoisonPill`, `Watch`,
`Suspend`, …) and lifecycle signals emit metrics/events but no spans (D14).
Message payload contents are added to span attributes only when the message class
carries a `#[TracePayload]` attribute (D17); otherwise metadata only (D5).

- **On receive (user message):**
  1. Extract parent context from `$envelope->metadata` via the propagator.
  2. Start a **Consumer** span `process {MessageClass}`.
  3. Activate it for the handler duration (nested sends become children).
  4. Attributes: `messaging.system=nexus`, `messaging.operation=process`,
     `nexus.actor.path`, `nexus.message.type`, `nexus.mailbox.depth`,
     `nexus.correlation_id`.
  5. Exception → `recordException()` + `ERROR` status (supervision still runs).
  6. `end()` in `finally`.
- **On send (`tell` / `ask`):** inject the current context into the new
  `Envelope::$metadata` so the downstream Consumer span links as a child. `ask()`
  also gets a **Client** span timing the round-trip. A `tell` from outside any
  actor starts a new trace root.
- **`ActorContext` gains** `tracer()`, `meter()`, `currentSpan()` so user handlers
  add custom spans/metrics (see §5.1).

Existing `requestId` / `correlationId` / `causationId` remain (useful for logs);
`traceparent` becomes the OTEL-native carrier alongside them.

### 5.1 Custom instrumentation (first-class, user-facing)

Adding custom spans and metrics is a supported, documented capability — not an
internal detail:

- **Inside actor handlers** via `ActorContext`:
  - `$ctx->tracer()->startSpan('charge-card', SpanKind::Client, [...])` → child of
    the current message span automatically (correct parent context is active).
  - `$ctx->meter()->counter('orders.placed')->add(1, ['tier' => $tier])` — instruments
    are created/cached by name per the OTEL spec, so repeated calls are cheap.
  - `$ctx->currentSpan()->setAttribute(...)` / `addEvent(...)` to enrich the active
    message span.
- **Outside actors** (HTTP handlers, services, jobs) via the injected
  `Observability` provider (`$observability->tracer()` / `->meter()`), obtained
  from the container or `NexusApp`.
- When observability is disabled, all of the above hit the no-op fast path — user
  instrumentation code stays in place with zero cost and no conditionals.
- Custom metric dimension guidance (bounded cardinality) is documented alongside
  the API to steer users away from cardinality blow-ups (D11).

`ActorSystem::create()` gains an optional `?Observability $observability = null`
parameter (defaults to `NoopObservability`). `ActorCell` receives it via
constructor, like `ClockInterface` / `LoggerInterface` today.

## 6. Propagation Across Every Boundary — One Mechanism

`Envelope::$metadata` is already serialized by the worker-pool transport
(`ThreadQueueTransport`) and the cluster transport. Injecting `traceparent` there
means **local → cross-thread → cross-node** propagation all flow through the same
code path. HTTP uses the same propagator against request/response headers. No
boundary-specific propagation code.

## 7. Satellite Instrumentation Details

### `nexus-observability-http`
- **Server** (used by `nexus-http-server-swoole` / `-threads` via middleware):
  extract `traceparent` from request headers → **Server** span
  `HTTP {method} {route}` with `http.*` semconv attributes + status/duration;
  bridge context into downstream actor sends.
  RED metrics: `http.server.request.duration` (histogram),
  `http.server.active_requests` (up/down counter), request counter.
- **Client** (`nexus-http`): **Client** span, inject `traceparent` into outgoing
  headers, `http.client.request.duration` + counters.
- **WS** (`nexus-http-ws`): connection-lifecycle + frame spans (lighter).

### End-to-end trace: HTTP request → actor → response

The flagship distributed trace. A single request produces one connected trace:

```
Span: HTTP POST /orders                        (SERVER,  nexus-observability-http)
 ├─ Span: ask PlaceOrder → orders              (CLIENT,  core — ask() round-trip)
 │   └─ Span: process PlaceOrder               (CONSUMER, core — in the actor)
 │       ├─ Span: append events                (INTERNAL, nexus-observability-persistence)
 │       └─ Span: charge-card                  (CLIENT,  user custom span via §5.1)
 └─ (response written; SERVER span ends with http.status_code + duration)
```

Mechanics, all via the one propagation mechanism (§4/§6):
1. Server middleware extracts `traceparent` from request headers (or starts a new
   trace root), opens the **SERVER** span, and activates it for the handler.
2. The handler calls `actorRef->ask(new PlaceOrder(...), $timeout)`. `ask()` opens
   a **CLIENT** span and injects the active context into the request
   `Envelope::metadata`.
3. `ActorCell::processMessage()` extracts that context and opens the **CONSUMER**
   span as a child — the trace crosses the HTTP→actor boundary with no special
   code. Nested sends, persistence ops, and custom spans nest under it.
4. The reply flows back to the ask; the CLIENT span ends; the handler writes the
   response; the SERVER span ends with `http.*` attributes + status + duration.

If the actor hop crosses a worker thread or cluster node, step 3 is unchanged —
the same `Envelope::metadata` carries `traceparent` across those boundaries too,
so the trace stays connected end-to-end regardless of where the actor lives.

### `nexus-observability-persistence`
- Decorators around `EventStore`, `SnapshotStore`, `StateStore`.
- Spans around append/read, snapshot save/load, recovery/replay; attributes
  `persistence.id`, event counts, `db.system`.
- Metrics: events persisted (counter), replay duration (histogram), snapshots
  (counter), recovery duration.
- DBAL/Doctrine stores: spans around SQL ops with **operation name only** — no
  full SQL text by default (PII).

### `nexus-observability-worker-pool`
- Transport metrics: queue depth (gauge), send latency (histogram), cross-worker
  hop count (counter).
- Hash-ring routing + node-address attributes on spans (the spans themselves come
  from core's envelope propagation; this package adds transport-level metrics and
  attributes).

### `nexus-observability-doctrine`

Instruments the Doctrine data-access layer and the entity actor-repository. It
subscribes to the pools' existing PSR-14 events for metrics and adds spans via
Doctrine's own extension seams (a DBAL `Middleware` for SQL, decorators for the
leases/transactional paths). Where the ORM `EntityRefFactory` lacks a suitable
event, the base package gains a **neutral PSR-14 lifecycle event** (not an
observability dependency) that this satellite subscribes to — consistent with §8.

- **DBAL connection pool** (`nexus-doctrine-dbal`):
  - Spans: `db.pool.acquire` (records wait/lease time), `db.transaction`
    (begin → commit/rollback around the transactional decorator), SQL statement
    spans via a tracing DBAL middleware.
  - SQL span attributes: `db.system`, `db.namespace`, and **parameterized query
    text only** (`db.query.text` with placeholders — never bound parameter
    values, per D5). `db.operation.name` (SELECT/INSERT/…).
  - Metrics (from pool events + `PoolStats`): connections created/taken/released/
    destroyed/poisoned (counters), pool exhausted (counter), pool size / in-use /
    idle / waiters (gauges), acquire wait time (histogram).
- **ORM EntityManager pool** (`nexus-doctrine-orm`):
  - Spans: `orm.em.acquire`, optional `orm.em.flush`.
  - Metrics (from EM events + `EmPoolStats`): EMs created/cleared/evicted
    (counters), pool gauges.
- **Entity actor-repository** (`EntityRefFactory` / `EntityBehaviorRunner` /
  `EntityEffect`):
  - Spans: `entity.resolve` (`of($id)` → cache hit vs spawn), `entity.replay`
    (recovery under the `ReplayPolicy`), `entity.persist` (`EntityEffect`), with
    `entity.conflict` recorded as span error.
  - Attributes: `nexus.entity.type`, `nexus.entity.id` (**span only** — `entity.id`
    is never a metric dimension, per D11).
  - Metrics: entities spawned / passivated / evicted (counters), replay duration
    (histogram), entity conflicts (counter), cache hit ratio.

## 8. Metrics Catalog (initial)

- **Actor:** mailbox size (gauge), messages processed (counter), processing
  duration (histogram), spawned / stopped (counter), restarts (supervision),
  dead-letters (counter), ask duration (histogram), ask timeouts (counter).
- **Runtime:** scheduled tasks (gauge/counter), active fibers/coroutines (gauge).
- **Actor-system internals (D24):** observable gauges — live actor count, dead-letter
  total, scheduled-timer count, runtime fiber/coroutine count — registered against
  a running `ActorSystem`.
- **Swoole admin (D23):** observable gauges from `Swoole\Server::stats()` /
  `Coroutine::stats()` — connections, accepted/handled requests, active/idle
  workers, coroutine count, task queue — exposed by `nexus-observability-swoole`.
- **HTTP / Persistence / Worker-pool:** as above.
- **Doctrine:** DBAL connection-pool counters/gauges + acquire-wait + SQL
  durations; ORM EM-pool counters/gauges; entity actor-repository counters
  (spawned/passivated/evicted/conflicts) + replay duration (see §7).

**Cardinality (D11):** metric dimensions are bounded — actor behavior/class name
and message class only. Per-instance `actor.path` is **never** a metric dimension
(it is unbounded in dynamic/entity systems and would explode the metrics
backend); it lives on spans only.

Sources: a mix of the **PSR-14 `EventDispatcher`** (currently wired but unused —
natural hook for lifecycle/supervision events) and direct meter calls on hot
paths. Where lifecycle events do not yet exist, emit them through the dispatcher
so metrics and user code can both subscribe.

## 9. Logs Pillar (hybrid)

- Keep `nexus-logger` / PSR-3 as the logging API.
- Add a **trace-context processor / MDC** that stamps `trace_id`, `span_id`,
  `correlation_id` onto every log record so logs correlate with traces in the
  backend.
- The OTEL bridge optionally adds an OTLP **log-record exporter** to ship logs to
  the same collector.

## 10. Configuration

- `ObservabilityConfig` value object (in `nexus-observability`): service name,
  resource attributes, sampler, exporter endpoint/protocol, enabled pillars,
  propagators.
- **Defaults:** sampler = `ParentBased(AlwaysOn)` (D10); `service.name` falls back
  to the `ActorSystem` name when `OTEL_SERVICE_NAME` is unset (D16); resource
  carries runtime type, `writer_id`, and `worker.id` where applicable.
- **Standard OTEL env vars** honored by the bridge: `OTEL_SDK_DISABLED`,
  `OTEL_SERVICE_NAME`, `OTEL_EXPORTER_OTLP_ENDPOINT`,
  `OTEL_EXPORTER_OTLP_PROTOCOL`, `OTEL_TRACES_SAMPLER`,
  `OTEL_RESOURCE_ATTRIBUTES`, … with programmatic overrides taking precedence.
- **Programmatic builder:**
  `NexusApp::create('app')->withObservability(ObservabilityConfig::otlp(...)->withSampler(...))`.
- `ActorSystem::create(..., ?Observability $observability = null)`.
- **Skeleton wizard step** scaffolds config + a `docker-compose` OTel Collector
  service.
- **Disabled by default** ⇒ `NoopObservability`.

## 11. Runtime Model & Async Export

- Bridge default: BatchSpanProcessor + OTLP exporter; **force-flush on
  `ActorSystem::shutdown()`** (hooks the existing graceful-shutdown deadline path).
- `nexus-observability-swoole`: coroutine HTTP transport so export I/O never
  blocks the reactor; background flush coroutine; flush before Swoole reactor exit
  (reuses the existing `BeforeShutdown` watchdog path in `SwooleThreadServer`).
- Fiber runtime (dev): stock exporter; force-flush on shutdown.
- Worker-pool (ZTS): each worker initializes its **own** SDK provider in
  `WorkerRunnable::onWorkerStart`; resource carries `worker.id`. OTEL SDK objects
  are not shared across threads.

## 12. Error Handling

Telemetry must **never** break the application:
- All instrumentation swallows/logs its own errors; failures in the telemetry path
  never propagate into actor handlers or HTTP requests.
- No-op fast path when disabled (shared singletons, no allocation).
- Spans always `end()` in `finally`.
- Shutdown flush is best-effort within the existing shutdown deadline.

## 13. Testing Strategy

- `nexus-observability`: unit tests for no-op implementations and the W3C
  propagator (inject/extract round-trip, malformed `traceparent` handling).
- Test doubles: `InMemoryTracer` + in-memory span/metric exporters in a `Support/`
  dir (coverage-included per `phpunit.xml`).
- **Fiber integration tests:** send a message chain across actors; assert
  parent↔child span linkage, span attributes, and metric values via the in-memory
  exporter.
- **Swoole integration test:** assert non-blocking export + force-flush on
  shutdown.
- Cross-boundary: worker-pool and cluster propagation tests assert `traceparent`
  survives the transport.
- **Deptrac:** add `nexus-observability` as a foundational layer; rule asserts
  `nexus-core` depends on it and nothing else new; satellites depend on their
  surface + observability.
- **Psalm Level 1** across all new packages; consider a `nexus-psalm` rule that
  span/metric attribute values are scalars.
- CI: new packages added to the unit/integration matrices; coverage-guard (90%
  method coverage) applies.

## 14. Implementation Phasing (within this spec)

1. **Foundation** — `nexus-observability` (interfaces, no-op, config, propagator)
   + OTEL SDK 8.5 compatibility spike + `nexus-observability-otel` bridge.
2. **Actors** — core instrumentation (`ActorCell`, `ActorContext`,
   `ActorSystem`), core metrics, dispatcher-sourced lifecycle metrics.
3. **Async export** — `nexus-observability-swoole` + shutdown flush integration.
4. **HTTP** — `nexus-observability-http` (server, client, WS).
5. **Persistence** — `nexus-observability-persistence`.
6. **Worker-pool / cluster** — `nexus-observability-worker-pool`.
6b. **Doctrine** — `nexus-observability-doctrine` (DBAL pool + SQL, ORM EM-pool +
   transactions, entity actor-repository). Depends on the persistence phase for
   shared store attributes.
7. **Logs correlation** — MDC processor in `nexus-logger` + optional OTLP log
   exporter in the bridge.
8. **Config surfaces** — `NexusApp` builder, env-var wiring, skeleton wizard step,
   docker-compose collector.
9. **Docs** — Docusaurus guide, phpDocumentor API wiring, and landing page (§15).

## 15. Documentation Deliverables

Documentation is part of "done." Three tiers on this branch must be updated
(per-package READMEs are out of scope by decision, though a minimal README per new
package is advisable since each splits to its own repo).

### 15.1 Docusaurus (`website/docs/`)
- New **Observability** section covering: overview & concepts; quick-start
  (enable in 5 minutes with a local Collector); configuration (env vars, `NexusApp`
  builder, wizard); tracing (actor message spans, propagation, end-to-end
  HTTP→actor→response with the §7 span tree); metrics catalog + cardinality
  guidance; custom instrumentation (§5.1); logs correlation; Doctrine (pools, SQL,
  entity actor-repository); deployment (docker-compose Collector, OTLP endpoints);
  troubleshooting (Swoole delivery, force-flush, disabling).
- A page per new package under `website/docs/packages/`.
- Cross-links from existing `http/`, `doctrine/`, `persistence/`, `runtimes/`,
  `operations/` sections; register everything in `website/sidebars.js`.
- **All ```php snippets must pass `make docs-verify`.**

### 15.2 phpDocumentor API (`phpdoc.dist.xml`, `bin/build-api-docs.sh`)
- Add the 7 new packages' `src` paths to `phpdoc.dist.xml`.
- Add them to the hardcoded package list in `bin/build-api-docs.sh` and update the
  hardcoded counts ("22 packages" / "22 of 25") accordingly.
- **Full PHPDoc on every new public class** from the outset (the project holds a
  high PHPDoc bar; new API ships documented, not backfilled).
- Verify with `make docs-api`.

### 15.3 Astro landing (`landing/`)
- A marketing `observability.astro` page (parallel to `doctrine.astro`) + a feature
  mention on `index.astro`.
- **Coordination risk:** `landing/` is under active ultrareview on the
  `astro-landing-1` branch. Land these edits last and rebase/coordinate with that
  branch to avoid merge conflicts; keep the diff small and self-contained.

### 15.4 Root docs
- Update `CLAUDE.md` package dependency graph + package list with the 7 new
  packages and the `nexus-core → nexus-observability` edge.
- Add any new `make` targets (e.g. `test-observability`) to the Makefile `.PHONY`
  list and CI matrix.

## 16. Out of Scope (v1)

- B3 / Jaeger / other propagation formats (W3C Trace Context + Baggage only).
- gRPC OTLP transport (HTTP only initially; pluggable later).
- Bundling observability into the `nexus` meta-package.
- Capturing message/request payload contents by default (opt-in only).
- Profiling / continuous-profiling pillar.
