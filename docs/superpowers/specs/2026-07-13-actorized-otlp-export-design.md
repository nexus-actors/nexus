# Design: actorized async OTLP export

**Date:** 2026-07-13
**Package:** `nexus-observability-otel` (user decision: extend this package rather than
create a new one; it gains `nexus-actors/core` + `nexus-actors/runtime` dependencies)
**Status:** approved for implementation planning

## Problem

Every OTLP export in `nexus-observability-otel` flushes synchronously on the calling
coroutine: `BatchSpanProcessor`, `ExportingReader`, and `BatchLogRecordProcessor` all
invoke the OTLP HTTP transport (ext-curl) inline when their batch/interval triggers. On a
single-reactor Swoole process, a slow or stalled collector blocks that coroutine — in the
16-container round-trip demo a stalled collector froze the reactor and hung the post-run
flush until a 5 s socket timeout was added (see
`docs/superpowers/reviews/2026-07-10-cluster-tcp-pr/roundtrip-demo-findings.md` and the
Swoole note in `website/docs/packages/observability-otel.md`). Bounded timeouts cap the
damage; they do not remove the coupling between application progress and collector health.

## Goal

Telemetry export must never block or destabilize the application. Spans, metrics, and logs
(all three signals — user decision) are handed to a dedicated **export actor** through a
bounded mailbox; only that actor performs OTLP I/O. A stalled collector affects nothing but
the exporter actor's own coroutine and, under sustained overload, causes *counted, bounded*
telemetry loss instead of application backpressure. Dogfooding bonus: the actor system's
own telemetry ships through an actor.

## Non-goals

- Reimplementing SDK batching (the SDK's processors keep doing size/interval batching).
- Durable/spooled telemetry (loss under overload is acceptable and counted).
- Fixing the Fiber runtime's blocking curl (documented limitation, see §Honest limits).
- Changing the default behavior: async export is opt-in.

## Architecture (Approach A: actor-forwarding exporters behind SDK batching)

The SDK pipeline is unchanged. Only the terminal *exporter* objects are swapped: each real
OTLP exporter is wrapped in a forwarding twin whose `export()` enqueues the ready-made
batch to the export actor and returns immediately. The actor owns the real exporters and
performs all HTTP I/O.

```
span ends ─► BatchSpanProcessor (SDK, unchanged)
                 │ size/interval trigger
                 ▼
      ActorForwardingSpanExporter::export(batch)     [NO I/O]
                 │ offer(new ExportSpans($batch))    bounded mailbox, DropOldest
                 ▼
           OtlpExportActor                           [ALL I/O lives here]
                 │ inner SpanExporter::export()
                 ▼
              collector
(identical shape for metrics via ExportingReader and logs via BatchLogRecordProcessor)
```

### Components (new `src/Export/` namespace)

**Messages** — `final readonly`, every one implements
`Monadial\Nexus\Core\Actor\UntracedMessage` so the export path generates no telemetry
about itself:

- `ExportSpans(array $batch)` — batch of `SpanDataInterface` (immutable SDK data objects;
  in-process tell, no serialization involved).
- `ExportMetrics(array $batch)` — batch collected by `ExportingReader`.
- `ExportLogs(array $batch)` — batch of readable log records.
- `FlushNow()` — force-flush request (used by `forceFlush()` bridging and tests).

**Forwarding exporters** — one per signal, each implementing the SDK interface its
processor expects:

- `ActorForwardingSpanExporter implements SpanExporterInterface`
- `ActorForwardingMetricExporter implements PushMetricExporterInterface` — MUST delegate
  `temporality()` to the inner exporter (preserves the Temporality::CUMULATIVE behavior
  that makes synchronous instruments exportable).
- `ActorForwardingLogRecordExporter implements LogRecordExporterInterface`

Shared three-state lifecycle (small shared trait or per-class duplication — implementer's
choice, favor a trait):

1. **Buffering** (from construction until the actor attaches): batches accumulate in a
   bounded local queue (cap: 64 batches per signal; overflow drops oldest and counts).
2. **Live** (after `attachExportActor()`): `export()` wraps the batch in its message and
   calls `LocalActorRef::offer()` (the existing `BackpressureCapable` seam).
   `EnqueueResult::Dropped`/`Backpressured` increments the drop counter. Returns a
   completed success future either way — the SDK caller never blocks.
3. **Direct** (fallback): if the actor ref is dead (`!isAlive()` or enqueue on a closed
   mailbox), delegate synchronously to the inner exporter — behavior degrades to exactly
   today's bounded-timeout sync path, never worse.

`shutdown()`: Buffering → flush the local buffer synchronously through the inner exporter;
Live → best-effort `FlushNow` tell (the authoritative flush is the actor's `PostStop`);
Direct → delegate. `forceFlush()`: Live → `FlushNow` tell + return true; otherwise delegate.

**`OtlpExportActor`** — a `StatefulActorHandler`-style actor (match `MembershipActor`'s
conventions) constructor-injected with the three real OTLP exporters:

- Handlers for the three batch messages call the corresponding inner `export()` inside a
  per-batch `try/catch Throwable` — a failed export drops that batch, increments
  `nexus.observability.export.dropped` (attribute `signal=spans|metrics|logs`,
  `reason=export_failed|mailbox_full|buffer_full`), and logs at debug via a NON-OTel
  logger. Under Swoole hooks the inner HTTP call coroutine-yields, so the reactor keeps
  scheduling application actors while a flush is in flight.
- `FlushNow` → `forceFlush()` on all inners.
- `PostStop` → final `forceFlush()` on all inners (the shutdown flush).
- Props: `->withMailbox(MailboxConfig::bounded(256, OverflowStrategy::DropOldest))`
  `->withSupervision(SupervisionStrategy::exponentialBackoff(...))` — a crashing exporter
  restarts with backoff; telemetry must never take the system down.

**Wiring**

- `ObservabilityConfig` gains `bool $asyncExport` (env `OTEL_NEXUS_ASYNC_EXPORT`,
  default **false** — fully backward compatible; docs recommend enabling on Swoole).
- When true, `ObservabilityFactory` wraps each real exporter in its forwarding twin and
  `OtelObservability` retains references to the three forwarders + the real exporters.
- New method: `OtelObservability::attachExportActor(ActorSystem $system, string $name =
  'otlp-export')` — spawns `OtlpExportActor` on the system, hands the ref to the three
  forwarders (each drains its Buffering queue into the mailbox in order), flips them Live.
  Idempotent; throws `LogicException` if `asyncExport` was not enabled.
- Creation-order is thereby resolved explicitly: `ObservabilityFactory::fromConfig()` →
  `ActorSystem::create(..., observability: $obs)` → `$obs->attachExportActor($system)` →
  `$system->run()`. If attach is never called, Buffering-mode shutdown flushes
  synchronously — nothing is lost silently.

### Feedback-loop prevention

- All export messages implement `UntracedMessage` → `ActorCell` skips per-message spans
  and processing metrics for the exporter actor.
- The actor's own diagnostic logging uses an injected plain logger (default `NullLogger`;
  never the OTel-backed PSR logger) so a failing collector cannot generate log records
  that re-enter the export pipeline.
- Residual accepted loops, both low-frequency and bounded: actor lifecycle debug logs from
  `ActorCell` (spawn/restart only) and the dropped-counter metric itself flowing through
  the metrics pipeline. Documented, not engineered away.

### Lifecycle & ordering

Documented shutdown order: **`$system->shutdown(...)` first** (PoisonPill drains the
export mailbox; `PostStop` force-flushes), **then** OTel provider shutdown. If the order
is reversed or the actor is already gone, forwarders detect the dead ref and fall back to
Direct — synchronous but correct. `ActorSystem::shutdown`'s deadline bounds how long a
stalled collector can delay shutdown (force-close after deadline = counted loss).

## Honest limits

- True isolation requires Swoole (coroutine-hooked curl). On the Fiber runtime an inner
  `export()` still blocks the process for up to the transport timeout; the actor still
  provides bounded queues, drop accounting, batching isolation, and identical semantics.
  Stated plainly in the package doc.
- `SWOOLE_HOOK_ALL` × `CURLOPT_SHARE` incompatibility (documented in
  `website/docs/packages/observability-otel.md`) applies to the actor's inner exporters
  exactly as before — the actor does not fix transport-level issues, it contains them.

## Dependency & docs changes

- `packages/nexus-observability-otel/composer.json`: add `nexus-actors/core` and
  `nexus-actors/runtime` to `require` (mirror the version style of sibling packages).
- `deptrac.yaml`: allow observability-otel → Core, Runtime.
- `CLAUDE.md` package graph line for nexus-observability-otel updated.
- `website/docs/packages/observability-otel.md`: new "Async export (actor)" section —
  config flag, attach call, ordering, limits; cross-link from the Swoole/OTLP note.

## Testing

**Unit** (`packages/nexus-observability-otel/tests/Unit/Export/`):
- Forwarding state machine per signal: Buffering accumulates + bounded + drains in order
  on attach; Live offers and counts drops on a full mailbox; Direct delegates when the ref
  is dead; `shutdown()`/`forceFlush()` per state.
- `ActorForwardingMetricExporter::temporality()` delegates to inner.
- Messages are `final readonly` + `instanceof UntracedMessage`.
- `OtlpExportActor`: batches reach recording inner exporters; a throwing inner exporter
  drops only that batch and increments the counter with `reason=export_failed`; `FlushNow`
  and `PostStop` force-flush inners.

**Integration** (Fiber + StepRuntime, no network):
- End-to-end: real SDK `TracerProvider` + `BatchSpanProcessor` over the forwarding
  exporter → actor → recording exporter; spans created before `attachExportActor` arrive
  after attach (buffer drain proof); `$system->shutdown()` flushes the tail.

**The decisive test — stalled collector (Swoole):**
- Inner span exporter whose `export()` sleeps far beyond the test window. Assert: an
  application actor keeps processing messages at full rate while flushes are stuck; the
  export mailbox sheds oldest batches; `nexus.observability.export.dropped` grows;
  shutdown completes within its deadline.

**Acceptance (system level):** run the 16-container round-trip demo with
`OTEL_NEXUS_ASYNC_EXPORT=1` — mesh stability (16/16 PASS, flat suspicion) with tracing on,
and the stalled-collector pathology (previously froze the reactor before the 5 s bound)
demonstrably cannot stall application actors.

## Success criteria

1. Live-mode `export()` cost ≈ one mailbox enqueue (no I/O on the caller).
2. Stalled-collector test passes: application throughput unaffected, loss counted.
3. Zero telemetry feedback storm (export path emits no per-message telemetry about itself).
4. `asyncExport=false` (default) byte-for-byte preserves today's wiring and behavior.
5. Demo acceptance run passes.
