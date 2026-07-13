# Actorized Async OTLP Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Route all OTLP export I/O (spans, metrics, logs) through a dedicated exporter actor with a bounded DropOldest mailbox, so a slow/stalled collector can never block application coroutines.

**Architecture:** The SDK pipeline (`BatchSpanProcessor` / `ExportingReader` / `BatchLogRecordProcessor`) is unchanged; only the terminal exporters are swapped for forwarding twins whose `export()` enqueues the ready batch to `OtlpExportActor` and returns immediately. The actor owns the real OTLP exporters and performs all HTTP I/O. Forwarders have three states: Buffering (before attach) → Live (offer to mailbox) → Direct (dead-ref fallback = today's sync path). Spec: `docs/superpowers/specs/2026-07-13-actorized-otlp-export-design.md`.

**Tech Stack:** PHP 8.5, `open-telemetry/sdk`, nexus-core actors, PHPUnit, Psalm level 1. Docker-only toolchain.

## Global Constraints

- **Docker only.** Every command via `docker compose exec -T php ...`. No host PHP.
- Commits: `git commit --no-gpg-sign --no-verify -m "..."`; run `git checkout -- .deptrac.cache 2>/dev/null` before every `git add`; NEVER stage `.deptrac.cache`; no `Co-Authored-By` trailer.
- Style: PER-CS2.0 + Slevomat — all classes `final`, messages `readonly`; string-keyed arrays sorted alphabetically; ordered imports (class/function/const, each alphabetical); trailing commas; blank line before control structures. Gates per task: psalm (`--no-cache`) clean on changed files, `phpcbf` then `php-cs-fixer fix` (cs-fixer LAST), idempotent on second run.
- Exact values from spec (verbatim): env flag `OTEL_NEXUS_ASYNC_EXPORT`, config field `asyncExport` default **false**; local buffer cap **64 batches per signal**; actor mailbox `MailboxConfig::bounded(256, OverflowStrategy::DropOldest)`; drop counter `nexus.observability.export.dropped` with attributes `signal` (`spans|metrics|logs`) and `reason` (`export_failed|mailbox_full|buffer_full`); attach API `OtelObservability::attachExportActor(ActorSystem $system, string $name = 'otlp-export')`; all messages implement `Monadial\Nexus\Core\Actor\UntracedMessage`.
- **Verify SDK interface signatures from vendor before implementing** (`vendor/open-telemetry/sdk/Trace/SpanExporterInterface.php`, `Metrics/PushMetricExporterInterface.php`, `Logs/LogRecordExporterInterface.php`) — the plan's code blocks reflect them but the vendor source is authoritative (e.g. `export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface` for spans/logs, `CompletedFuture(true)` as the non-blocking success return).
- `ObservabilityConfig` lives in `nexus-observability` (must stay dependency-free — a bool field is fine). Everything else lands in `nexus-observability-otel`.

---

### Task 1: `asyncExport` config flag

**Files:**
- Modify: `packages/nexus-observability/src/Config/ObservabilityConfig.php`
- Test: `packages/nexus-observability/tests/Unit/Config/ObservabilityConfigTest.php` (extend existing)

**Interfaces:**
- Produces: `ObservabilityConfig::$asyncExport` (public readonly bool, constructor param with `= false` default placed LAST), `withAsyncExport(bool): self` wither, `fromEnv()` reading `OTEL_NEXUS_ASYNC_EXPORT` (truthy: `'1'|'true'`, case-insensitive).

- [ ] **Step 1: Write the failing tests** — add to the existing config test class:

```php
    #[Test]
    public function asyncExportDefaultsToFalse(): void
    {
        self::assertFalse(ObservabilityConfig::enabled('svc')->asyncExport);
    }

    #[Test]
    public function withAsyncExportEnablesTheFlag(): void
    {
        $config = ObservabilityConfig::enabled('svc')->withAsyncExport(true);

        self::assertTrue($config->asyncExport);
    }

    #[Test]
    public function fromEnvReadsAsyncExportFlag(): void
    {
        $config = ObservabilityConfig::fromEnv([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://localhost:4318',
            'OTEL_NEXUS_ASYNC_EXPORT' => 'true',
            'OTEL_SERVICE_NAME' => 'svc',
        ]);

        self::assertTrue($config->asyncExport);
    }
```

- [ ] **Step 2: Run to verify failure** — `docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/Config/ObservabilityConfigTest.php` → FAIL (unknown property/method).

- [ ] **Step 3: Implement** — add `public bool $asyncExport = false` as the LAST constructor param; add the wither (clone-with, matching the existing withers' style exactly); in `fromEnv()` parse `OTEL_NEXUS_ASYNC_EXPORT` with the same truthy-parsing idiom the method already uses for boolean envs (read the method first; if none exists, use `in_array(strtolower((string)($env['OTEL_NEXUS_ASYNC_EXPORT'] ?? '')), ['1','true'], true)`). Update `disabled()`/`enabled()` only if they enumerate all params positionally.

- [ ] **Step 4: Run to verify pass** — same command → PASS. Also run the package suite: `docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests` → PASS.

- [ ] **Step 5: Gates + commit**

```bash
docker compose exec -T php vendor/bin/psalm --no-cache packages/nexus-observability/src/Config/ObservabilityConfig.php
docker compose exec -T php vendor/bin/php-cs-fixer fix packages/nexus-observability/src/Config packages/nexus-observability/tests
cd /Users/tomas/Work/Monadial/CodeOSS/nexus && git checkout -- .deptrac.cache 2>/dev/null
git add packages/nexus-observability && git commit --no-gpg-sign --no-verify -m "feat(observability): asyncExport config flag (OTEL_NEXUS_ASYNC_EXPORT)"
```

---

### Task 2: dependency wiring — composer, Deptrac, CLAUDE.md

**Files:**
- Modify: `packages/nexus-observability-otel/composer.json`
- Modify: `deptrac.yaml`
- Modify: `CLAUDE.md` (package graph line)

**Interfaces:**
- Produces: `nexus-observability-otel` may `use Monadial\Nexus\Core\*` and `Monadial\Nexus\Runtime\*` without Deptrac violations. All later tasks depend on this.

- [ ] **Step 1: composer.json** — in `packages/nexus-observability-otel/composer.json` add to `require` (copy the exact version-constraint style used by the existing `nexus-actors/observability` entry — likely `self.version` or `dev-main`): `"nexus-actors/core"` and `"nexus-actors/runtime"`. Keep the require map alphabetically sorted.

- [ ] **Step 2: deptrac.yaml** — find the ruleset entry for the ObservabilityOtel layer (grep `ObservabilityOtel` in `deptrac.yaml`) and add `Core` and `Runtime` to its allowed layers, matching the file's existing formatting.

- [ ] **Step 3: CLAUDE.md** — update the dependency-graph line `nexus-observability-otel → Observability, OTel SDK` to `→ Observability, Core, Runtime, OTel SDK (concrete OTel backend; actorized async export)`.

- [ ] **Step 4: Verify** — `docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --no-progress` → `Violations 0`.

- [ ] **Step 5: Commit**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus && git checkout -- .deptrac.cache 2>/dev/null
git add packages/nexus-observability-otel/composer.json deptrac.yaml CLAUDE.md
git commit --no-gpg-sign --no-verify -m "chore(observability-otel): allow core+runtime deps for actorized export"
```

---

### Task 3: export messages

**Files:**
- Create: `packages/nexus-observability-otel/src/Export/ExportSpans.php`, `ExportMetrics.php`, `ExportLogs.php`, `FlushNow.php`
- Test: `packages/nexus-observability-otel/tests/Unit/Export/ExportMessagesTest.php`

**Interfaces:**
- Produces: `Monadial\Nexus\Observability\Otel\Export\{ExportSpans,ExportMetrics,ExportLogs,FlushNow}` — each `final readonly`, implements `UntracedMessage`. Batch messages: `public function __construct(public array $batch) {}`. `FlushNow` has an empty body.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit\Export;

use Monadial\Nexus\Core\Actor\UntracedMessage;
use Monadial\Nexus\Observability\Otel\Export\ExportLogs;
use Monadial\Nexus\Observability\Otel\Export\ExportMetrics;
use Monadial\Nexus\Observability\Otel\Export\ExportSpans;
use Monadial\Nexus\Observability\Otel\Export\FlushNow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExportSpans::class)]
#[CoversClass(ExportMetrics::class)]
#[CoversClass(ExportLogs::class)]
#[CoversClass(FlushNow::class)]
final class ExportMessagesTest extends TestCase
{
    #[Test]
    public function allExportMessagesAreUntraced(): void
    {
        self::assertInstanceOf(UntracedMessage::class, new ExportSpans([]));
        self::assertInstanceOf(UntracedMessage::class, new ExportMetrics([]));
        self::assertInstanceOf(UntracedMessage::class, new ExportLogs([]));
        self::assertInstanceOf(UntracedMessage::class, new FlushNow());
    }

    #[Test]
    public function batchMessagesCarryTheirBatch(): void
    {
        $batch = ['a', 'b'];

        self::assertSame($batch, (new ExportSpans($batch))->batch);
        self::assertSame($batch, (new ExportMetrics($batch))->batch);
        self::assertSame($batch, (new ExportLogs($batch))->batch);
    }
}
```

- [ ] **Step 2: Run → FAIL** (classes not found). **Step 3: Implement** — four tiny classes, e.g.:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Export;

use Monadial\Nexus\Core\Actor\UntracedMessage;

/**
 * @psalm-api
 *
 * A batch of immutable SDK span data handed off to the OtlpExportActor. Untraced so the
 * export path generates no telemetry about itself.
 */
final readonly class ExportSpans implements UntracedMessage
{
    /** @param array<array-key, mixed> $batch */
    public function __construct(public array $batch) {}
}
```

(`ExportMetrics`, `ExportLogs` identical shape; `FlushNow` is `final readonly class FlushNow implements UntracedMessage {}`.)

- [ ] **Step 4: Run → PASS.** **Step 5: Gates + commit** (`feat(observability-otel): untraced export messages for the OTLP export actor`).

---

### Task 4: `OtlpExportActor` + recording test doubles

**Files:**
- Create: `packages/nexus-observability-otel/src/Export/OtlpExportActor.php`
- Create: `packages/nexus-observability-otel/tests/Support/RecordingSpanExporter.php` (implements `SpanExporterInterface`; records batches into a public array; configurable to throw), `RecordingMetricExporter.php` (implements `PushMetricExporterInterface`), `RecordingLogExporter.php` (implements `LogRecordExporterInterface`)
- Test: `packages/nexus-observability-otel/tests/Unit/Export/OtlpExportActorTest.php`

**Interfaces:**
- Consumes: Task 3 messages; nexus-core `Behavior`, `Props`, `MailboxConfig`, `OverflowStrategy`, `SupervisionStrategy`; `Monadial\Nexus\Observability\Metric\{Meter,Counter,NoopMeter}`.
- Produces: `OtlpExportActor::__construct(SpanExporterInterface $spans, ?PushMetricExporterInterface $metrics, ?LogRecordExporterInterface $logs, Meter $meter = new NoopMeter(), LoggerInterface $logger = new NullLogger())` and `public function props(): Props` — Props carry `MailboxConfig::bounded(256, OverflowStrategy::DropOldest)` and `SupervisionStrategy::exponentialBackoff(...)` (read that factory's signature in `packages/nexus-core/src/Supervision/SupervisionStrategy.php` first and use sensible args, e.g. initial 100 ms, max 5 s). Drop counter name/attributes per Global Constraints.

- [ ] **Step 1: Failing tests** — drive with `StepRuntime` exactly like `MembershipActorTest` does (spawn via `$system->spawn($actor->props(), 'otlp-export')`, `tell`, `$runtime->drain()`):

```php
    #[Test]
    public function spanBatchesReachTheInnerExporter(): void
    {
        $ref = $this->spawnActor();
        $ref->tell(new ExportSpans(['span-a', 'span-b']));
        $this->runtime->drain();

        self::assertSame([['span-a', 'span-b']], $this->spans->exported);
    }

    #[Test]
    public function aThrowingInnerExporterDropsOnlyThatBatchAndCounts(): void
    {
        $this->spans->throwOnExport = true;
        $ref = $this->spawnActor();
        $ref->tell(new ExportSpans(['bad']));
        $this->runtime->drain();

        $this->spans->throwOnExport = false;
        $ref->tell(new ExportSpans(['good']));
        $this->runtime->drain();

        self::assertSame([['good']], $this->spans->exported);
        self::assertSame(1.0, $this->meter->counterSum('nexus.observability.export.dropped'));
    }

    #[Test]
    public function flushNowForceFlushesAllInnerExporters(): void
    {
        $ref = $this->spawnActor();
        $ref->tell(new FlushNow());
        $this->runtime->drain();

        self::assertSame(1, $this->spans->forceFlushes);
        self::assertSame(1, $this->metrics->forceFlushes);
        self::assertSame(1, $this->logs->forceFlushes);
    }

    #[Test]
    public function postStopForceFlushesAllInnerExporters(): void
    {
        $ref = $this->spawnActor();
        $this->system->stop($ref);
        $this->runtime->drain();

        self::assertSame(1, $this->spans->forceFlushes);
    }
```

For the meter assertion, reuse the recording-meter double pattern from `packages/nexus-cluster-tcp/tests/Support/RecordingMeter.php` (copy the class into this package's `tests/Support/`, adjusting namespace — do NOT import across packages).

- [ ] **Step 2: Run → FAIL.** **Step 3: Implement** — behavior shape (mirror the framework's class-based actor idiom):

```php
public function props(): Props
{
    $behavior = Behavior::receive(fn(ActorContext $ctx, object $msg): Behavior => $this->handle($msg))
        ->onSignal(function (ActorContext $ctx, Signal $signal): Behavior {
            if ($signal instanceof PostStop) {
                $this->flushAll();
            }

            return Behavior::same();
        });

    return Props::fromBehavior($behavior)
        ->withMailbox(MailboxConfig::bounded(256, OverflowStrategy::DropOldest))
        ->withSupervision(SupervisionStrategy::exponentialBackoff(/* per Step 1 note */));
}
```

`handle()` matches the three batch messages + `FlushNow`; each batch handler wraps the inner `export()` in `try/catch Throwable` → on failure increment the drop counter with `['reason' => 'export_failed', 'signal' => '<signal>']` (array keys alphabetical) and `$this->logger->debug(...)`. Nullable metrics/logs exporters are skipped when null.

- [ ] **Step 4: Run → PASS** (whole package: `docker compose exec -T php vendor/bin/phpunit packages/nexus-observability-otel/tests`). **Step 5: Gates + commit** (`feat(observability-otel): OtlpExportActor owns all OTLP flush I/O`).

---

### Task 5: forwarding exporters (Buffering → Live → Direct)

**Files:**
- Create: `packages/nexus-observability-otel/src/Export/ForwardsBatchesToActor.php` (trait holding the shared state machine), `ActorForwardingSpanExporter.php`, `ActorForwardingMetricExporter.php`, `ActorForwardingLogRecordExporter.php`
- Test: `packages/nexus-observability-otel/tests/Unit/Export/ActorForwardingExportersTest.php`

**Interfaces:**
- Consumes: Task 3 messages; Task 4 doubles; `Monadial\Nexus\Core\Actor\{ActorRef,BackpressureCapable}`; `Monadial\Nexus\Runtime\Mailbox\EnqueueResult`.
- Produces: each class wraps its inner exporter: `__construct(<InnerInterface> $inner, Meter $meter = new NoopMeter())`; shared API from the trait: `attach(ActorRef $ref): void` (drains buffer in order via the ref, flips Live), plus the SDK interface methods. `ActorForwardingMetricExporter::temporality($metric)` delegates to `$this->inner->temporality($metric)`. Buffer cap 64 (`private const int BUFFER_LIMIT = 64;`), overflow drops OLDEST buffered batch and counts `reason=buffer_full`. Live-mode enqueue uses `offer()` when the ref is `BackpressureCapable` (count `mailbox_full` on non-Accepted) else plain `tell()`. Dead-ref detection: `!$ref->isAlive()` → Direct (synchronous inner delegate) from then on.

- [ ] **Step 1: Failing tests** (representative set — write all of these):

```php
    #[Test]
    public function bufferingModeAccumulatesAndAttachDrainsInOrder(): void { /* export 3 batches, attach to a recording ref, assert 3 messages received in order, then a 4th export goes to the ref not the buffer */ }

    #[Test]
    public function bufferOverflowDropsOldestAndCounts(): void { /* export 65 batches while buffering; attach; assert 64 delivered, first batch missing, dropped counter =1 with reason buffer_full */ }

    #[Test]
    public function liveModeCountsMailboxDrops(): void { /* ref double whose offer() returns EnqueueResult::Dropped; assert counter reason=mailbox_full and export still returns success */ }

    #[Test]
    public function deadRefFallsBackToDirectDelegation(): void { /* ref double isAlive()=false; export; assert inner exporter received the batch synchronously */ }

    #[Test]
    public function metricTemporalityDelegatesToInner(): void { /* inner double returns a sentinel; assert passthrough */ }

    #[Test]
    public function shutdownWhileBufferingFlushesBufferThroughInner(): void { /* export 2, shutdown(), assert inner got both */ }
```

Write each test body fully (the comments above describe the arrange/act/assert — expand them; the ref double is a tiny `final class RecordingRef implements ActorRef, BackpressureCapable` in `tests/Support/` capturing told/offered messages with configurable `EnqueueResult` and `isAlive`). Read `packages/nexus-core/src/Actor/ActorRef.php` and `BackpressureCapable.php` for the exact methods a double must implement.

- [ ] **Step 2: Run → FAIL.** **Step 3: Implement** trait + three classes. Span/log `export()` returns the SDK's completed-success future (check vendor for the exact class — `OpenTelemetry\SDK\Common\Future\CompletedFuture`); metric `export()` returns `true`. Each class's message wrapper differs (`ExportSpans` / `ExportMetrics` / `ExportLogs`) — the trait takes the message via an abstract `wrap(array $batch): object` method.

- [ ] **Step 4: Run → PASS** (package suite). **Step 5: Gates + commit** (`feat(observability-otel): actor-forwarding exporters with buffer/live/direct lifecycle`).

---

### Task 6: factory + `attachExportActor` wiring, end-to-end integration

**Files:**
- Modify: `packages/nexus-observability-otel/src/ObservabilityFactory.php`
- Modify: `packages/nexus-observability-otel/src/OtelObservability.php`
- Test: `packages/nexus-observability-otel/tests/Unit/ObservabilityFactoryTest.php` (extend), new `packages/nexus-observability-otel/tests/Unit/Export/AttachExportActorTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: when `$config->asyncExport` is true, the factory wraps each real exporter in its forwarding twin before handing it to `BatchSpanProcessor`/`ExportingReader`/`BatchLogRecordProcessor`, and passes the three forwarders (+ the real exporters, which the actor will own) into `OtelObservability` via a new optional constructor value object `AsyncExportHandles` (`final readonly class` in `src/Export/` holding `?ActorForwardingSpanExporter $spans`, `?ActorForwardingMetricExporter $metrics`, `?ActorForwardingLogRecordExporter $logs`, plus the three inner exporters). New method:

```php
public function attachExportActor(ActorSystem $system, string $name = 'otlp-export'): void
```

— throws `LogicException('asyncExport is not enabled...')` when handles are absent; idempotent (second call is a no-op); spawns `OtlpExportActor` with the inner exporters + `$this->meter()`, then calls `attach($ref)` on each forwarder.

- [ ] **Step 1: Failing tests**
  - Factory: `asyncExportWrapsExportersInForwardingTwins` — build with `withAsyncExport(true)` + a localhost endpoint, assert `OtelObservability` exposes non-null handles (add a test-visible accessor or assert via `attachExportActor` behavior); `asyncExportOffPreservesTodayWiring` — default config produces null handles and `attachExportActor` throws `LogicException`.
  - Attach: spawn on a `StepRuntime` system; create a span through the provider; `drain()`; assert the recording inner exporter got it AFTER attach (buffer-drain proof); `attachExportActorIsIdempotent` — second call doesn't spawn a second actor (`ActorNameExistsException` must NOT surface).
  - Integration: full pipeline on `FiberRuntime` — real `TracerProvider` + `BatchSpanProcessor(forwarder)` (use `maxExportBatchSize: 1` / `scheduledDelayMillis` small so batches trigger promptly — check the SDK constructor for exact param names), emit spans inside a scheduled callback, `$system->shutdown()`, assert the recording exporter received them (PostStop flush proof).

- [ ] **Step 2: Run → FAIL.** **Step 3: Implement.** In the factory, the wrap is mechanical:

```php
$spanExporter = new SpanExporter((new OtlpHttpTransportFactory())->create(...));

if ($config->asyncExport) {
    $forwardingSpans = new ActorForwardingSpanExporter($spanExporter);
    // BatchSpanProcessor receives $forwardingSpans instead of $spanExporter
}
```

(same for metrics/logs; collect into `AsyncExportHandles`; pass to `OtelObservability`). Keep the `!$config->enabled → NoopObservability` early-return untouched.

- [ ] **Step 4: Run → PASS** (package suite + `docker compose exec -T php vendor/bin/phpunit --testsuite=unit` for cross-package sanity). **Step 5: Gates + commit** (`feat(observability-otel): asyncExport wiring + attachExportActor`).

---

### Task 7: stalled-collector isolation test (Swoole)

**Files:**
- Create: `tests/Integration/Swoole/AsyncOtlpExportStallTest.php` (follow the existing Swoole integration test conventions in `tests/Integration/Swoole/` — read one first for the runtime/boot idiom; messages sent inside `scheduleOnce` callbacks)

**Interfaces:**
- Consumes: Tasks 3–6. A `StallingSpanExporter` test double whose `export()` does `Coroutine::sleep(30)` (far beyond the test window).

- [ ] **Step 1: Write the test** — arrange: SwooleRuntime system; `OtlpExportActor` wired with the stalling span exporter via a forwarding exporter attached to it; an application counter actor. Act: schedule a loop telling the app actor 1000 messages while also telling the forwarder several span batches. Assert: the app actor processed all 1000 within the deadline (the stalled export coroutine did not block the reactor), and the drop counter grew once the 256-mailbox filled. Bound the whole test at ~10 s wall clock.

- [ ] **Step 2: Run** — `docker compose exec -T php-swoole vendor/bin/phpunit tests/Integration/Swoole/AsyncOtlpExportStallTest.php` → PASS (first run may fail while implementing details; iterate).

- [ ] **Step 3: Gates + commit** (`test(observability-otel): stalled collector cannot block application actors`).

---

### Task 8: docs + acceptance run

**Files:**
- Modify: `website/docs/packages/observability-otel.md` (new "Async export (actor)" section: the flag, the attach call, shutdown ordering — system first, then provider; Fiber limitation stated plainly; cross-link from the existing Swoole/OTLP-curl note)
- Modify: `CLAUDE.md` (one line in the nexus-observability-otel bullet noting the async export actor + attach API)

- [ ] **Step 1: Write both doc edits.** Verify every claim against the implemented code (flag name, method signature, counter name, defaults).
- [ ] **Step 2: Commit** (`docs(observability-otel): async export actor guide`).
- [ ] **Step 3 (controller-run acceptance, NOT the implementer):** run the 16-container round-trip demo with `OTEL_NEXUS_ASYNC_EXPORT=1` wired through its compose env and `attachExportActor` added to `roundtrip_node.php` boot — 16/16 PASS with tracing on, and record the result in the plan/ledger. This step is executed by the controlling session (it owns soak/demo runs), not the task implementer.

---

## Baseline change since the spec (read before starting)

Commit `44e60023` (landed after the spec) rebuilt the OTLP transports on symfony
`NativeHttpClient` streams (coroutine-hooked under `SWOOLE_HOOK_ALL`, no curl/`CURLOPT_SHARE`
issue) and added `OTEL_EXPORTER_OTLP_TIMEOUT` / `ObservabilityConfig::$exporterTimeoutMillis`.
Consequences for this plan:

- The spec's worst failure mode (reactor FREEZE on a stalled collector) is already mitigated:
  stream exports yield to the scheduler and are deadline-bounded. The actor's remaining — still
  real — value: the SDK's Batch processors flush INLINE on whatever application coroutine
  triggers them, so that coroutine still waits up to the timeout; the actor moves every export
  wait onto a dedicated coroutine, adds the bounded queue + counted drops, supervision/restart,
  and PostStop flush semantics. Evidence: `44e60023`'s own proof run saw ask p99 jump 17 ms →
  649 ms with cooperative inline exports sharing the driver reactor.
- Task 1 is unaffected (`asyncExport` is a NEW field; `exporterTimeoutMillis` is a different,
  already-landed one — do not confuse them).
- Task 6's factory wrap is orthogonal to the transport change: forwarding exporters wrap the
  EXPORTER objects, whatever transport they were built on. Read the factory's current (stream-
  based) construction before editing; the wrap point is unchanged.
- Task 7's stall test should stall via a slow inner exporter double regardless of transport
  (do not rely on curl behavior).

## Notes for the implementer

- The exporter actor's diagnostic logger must NEVER be the OTel-backed PSR logger (feedback loop). Default `NullLogger`.
- `LocalActorRef::offer()` exists via `BackpressureCapable` — prefer it over `tell()` in Live mode precisely because it returns `EnqueueResult` for drop accounting.
- Do not touch `nexus-observability`'s dependency-free status beyond the bool config field (Task 1).
- Baseline suites before you start: observability-otel package tests green, full unit suite 1525+ green. Leave both green.
