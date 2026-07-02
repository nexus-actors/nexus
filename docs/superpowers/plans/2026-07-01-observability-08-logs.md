# Observability — Plan 8: Logs Correlation (`nexus-observability-logger`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Correlate logs with traces — a `nexus-logger` `RecordProcessor` that stamps `trace_id`/`span_id`/`trace_flags` from the **active OTel span** onto every log record, so log lines join their trace in the backend (D13). This is the OTel-driven source of truth, reconciling the existing `TraceContextMiddleware`'s independently-minted MDC span id.

**Architecture:** `TraceCorrelationProcessor implements RecordProcessor` reads the ambient context via `Observability::currentContext()` at log-time (the processor runs synchronously on the caller's thread/fiber, so the actor's Consumer span or HTTP Server span is current) and merges the span's ids into `Record::$extra`. No-op when observability is disabled or no span is active. Driven by the injected `Observability` provider (no-op default).

**Scope note:** OTLP **log-record export** (shipping logs via the OTEL logs SDK) is a larger follow-up (needs `LoggerProvider` + a nexus-logger `Handler` bridge) and is **deferred** — the correlation processor delivers the essential D13 value (logs carry the real span's ids; backends correlate by `trace_id`). The **reconciliation** point (Plan 4 finding): when observability is enabled, wiring (Plan 9) registers this processor as the span-id source and should not rely on `TraceContextMiddleware`'s MDC span id (which mints its own) for trace↔log correlation.

**Tech Stack:** PHP 8.5.7, `nexus-observability` (+ OTEL bridge dev-deps for tests), `nexus-logger` (RecordProcessor/Record), PHPUnit 13, Psalm L1, PHPCS, Deptrac, Docker.

## Global Constraints

- **Docker only:** prefix with `docker compose exec -T php`. `composer dump-autoload` after adding classes.
- **Commit policy:** `git -c commit.gpgsign=false commit --no-verify` (GrumPHP hook broken + worktree GPG times out). Before commit: `make cs-fix && make phpcs && make psalm` (clean) + package suite + `docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --config-file=deptrac.yaml`. `make phpcs` enforces `ReferenceUsedNamesOnly`. `Warning: JIT...` is env noise.
- **NEVER** add `Co-Authored-By: Claude`. **No singletons.**
- **Fail-isolation (§12):** the processor must NEVER throw out of `process()` — a telemetry read failure returns the record unchanged.
- **Disabled fast-path:** `!isEnabled()` → return the record unchanged, zero work.
- **Code style:** `declare(strict_types=1);`; `final readonly` (the processor is stateless); `/** @psalm-api */`; alphabetical imports; string-keyed arrays **alphabetical**; trailing commas; blank line before control structures; `#[Override]`.
- **Deptrac:** new layer `ObservabilityLogger` may depend only on `Logger` + `Observability`.
- **Tests:** use the real OTEL bridge (dev-dep) with an in-memory tracer; start+activate a span and assert the processed record's `extra` carries the span's ids; assert no-op when disabled and when no span is active.

## Verified seams

- `Monadial\Nexus\Logger\RecordProcessor`: `process(Record $record): Record` (runs synchronously on the caller's thread — so the ambient OTel span is current).
- `Monadial\Nexus\Logger\Record`: `final readonly`, public `Level $level, string $message, array $context, string $channel, float $timestamp, array $extra = []`; `withExtra(array $extra): self` **merges** (`[...$this->extra, ...$extra]`); `static create(...)` factory (verify its exact signature in source before using in the test — otherwise construct via `new Record(Level::Info, 'msg', [], 'app', <timestamp>)`).
- `Observability`: `isEnabled(): bool`, `currentContext(): Context`; `Context::$spanContext` → `SpanContext { traceId, spanId, traceFlags, isValid() }`.

---

## File Structure

```
packages/nexus-observability-logger/
  composer.json
  src/
    TraceCorrelationProcessor.php
  tests/
    Unit/
      TraceCorrelationProcessorTest.php
```
Shared files modified: root `composer.json`, `deptrac.yaml`, `phpunit.xml`.

---

## Task 1: Scaffold + `TraceCorrelationProcessor`

**Files:**
- Create: `packages/nexus-observability-logger/composer.json`
- Create: `packages/nexus-observability-logger/src/TraceCorrelationProcessor.php`
- Create: `packages/nexus-observability-logger/tests/Unit/TraceCorrelationProcessorTest.php`
- Modify: root `composer.json`, `deptrac.yaml`, `phpunit.xml`

**Interfaces:**
- Produces: `final readonly class TraceCorrelationProcessor implements RecordProcessor` — ctor `(Observability $observability)`; `process()` merges `span_id`/`trace_flags`/`trace_id` into `Record::$extra` from the active span; no-op when disabled / no active span / on any telemetry error.

- [ ] **Step 1: `packages/nexus-observability-logger/composer.json`**
```json
{
    "name": "nexus-actors/observability-logger",
    "description": "Nexus logs↔traces correlation — a RecordProcessor that stamps active-span trace/span ids onto log records.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5.7",
        "nexus-actors/logger": "dev-main",
        "nexus-actors/observability": "dev-main"
    },
    "require-dev": {
        "nexus-actors/observability-otel": "dev-main",
        "open-telemetry/sdk": "^1.14",
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Logger\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Logger\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Root `composer.json`** — add (in alphabetical position within the `Observability\\*` entries) to `autoload.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\Logger\\": "packages/nexus-observability-logger/src/",
```
and to `autoload-dev.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\Logger\\Tests\\": "packages/nexus-observability-logger/tests/",
```
Run `docker compose exec -T php composer dump-autoload`.

- [ ] **Step 3: `deptrac.yaml`** — add layer:
```yaml
    - name: ObservabilityLogger
      collectors:
        - type: directory
          value: packages/nexus-observability-logger/src/.*
```
and ruleset:
```yaml
    ObservabilityLogger:
      - Logger
      - Observability
```

- [ ] **Step 4: `phpunit.xml`** — add to `<testsuite name="unit">`:
```xml
            <directory>packages/nexus-observability-logger/tests/Unit</directory>
```
and to `<source><include>`:
```xml
            <directory>packages/nexus-observability-logger/src</directory>
```

- [ ] **Step 5: Confirm `Record::create()` signature** — `docker compose exec -T php grep -n -A10 "public static function create" packages/nexus-logger/src/Record.php`. Use the real factory (or the public constructor `new Record(Level::Info, 'msg', [], 'app', <float timestamp>)`) in the test.

- [ ] **Step 6: Write the failing test**

`packages/nexus-observability-logger/tests/Unit/TraceCorrelationProcessorTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Logger\Tests\Unit;

use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Record;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Logger\TraceCorrelationProcessor;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Observability\Trace\SpanKind;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function microtime;

#[CoversClass(TraceCorrelationProcessor::class)]
final class TraceCorrelationProcessorTest extends TestCase
{
    private function observability(): OtelObservability
    {
        return new OtelObservability(
            new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter())),
            MeterProvider::builder()->addReader(new ExportingReader(new MetricInMemoryExporter()))->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );
    }

    private function record(): Record
    {
        return new Record(Level::Info, 'hello', [], 'app', microtime(true));
    }

    #[Test]
    public function stampsActiveSpanIdsIntoExtra(): void
    {
        $observability = $this->observability();
        $processor = new TraceCorrelationProcessor($observability);

        $span = $observability->tracer()->startSpan('op', SpanKind::Internal);
        $processed = $processor->process($this->record());
        $expected = $span->context();
        $span->end();

        self::assertSame($expected->traceId, $processed->extra['trace_id']);
        self::assertSame($expected->spanId, $processed->extra['span_id']);
    }

    #[Test]
    public function noOpWhenNoActiveSpan(): void
    {
        $processed = (new TraceCorrelationProcessor($this->observability()))->process($this->record());

        self::assertArrayNotHasKey('trace_id', $processed->extra);
    }

    #[Test]
    public function noOpWhenDisabled(): void
    {
        $processed = (new TraceCorrelationProcessor(new NoopObservability()))->process($this->record());

        self::assertArrayNotHasKey('trace_id', $processed->extra);
    }
}
```
> Adjust `new Record(...)` to the real `Record` constructor/`create()` signature confirmed in Step 5.

- [ ] **Step 7: Run — expect FAIL** (`TraceCorrelationProcessor` not found).

- [ ] **Step 8: Create `TraceCorrelationProcessor`**

`packages/nexus-observability-logger/src/TraceCorrelationProcessor.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Logger;

use Monadial\Nexus\Logger\Record;
use Monadial\Nexus\Logger\RecordProcessor;
use Monadial\Nexus\Observability\Observability;
use Override;
use Throwable;

/**
 * @psalm-api
 *
 * Stamps the active OpenTelemetry span's `trace_id`, `span_id`, and
 * `trace_flags` onto each log record's `extra`, so log lines correlate with
 * their trace. Runs synchronously on the caller's thread, so the ambient span
 * (the actor's Consumer span, or the HTTP Server span) is current. No-op when
 * observability is disabled, when no span is active, or on any telemetry error.
 */
final readonly class TraceCorrelationProcessor implements RecordProcessor
{
    public function __construct(
        private Observability $observability,
    ) {}

    #[Override]
    public function process(Record $record): Record
    {
        if (!$this->observability->isEnabled()) {
            return $record;
        }

        try {
            $spanContext = $this->observability->currentContext()->spanContext;

            if (!$spanContext->isValid()) {
                return $record;
            }

            return $record->withExtra([
                'span_id' => $spanContext->spanId,
                'trace_flags' => $spanContext->traceFlags,
                'trace_id' => $spanContext->traceId,
            ]);
        } catch (Throwable) {
            // Telemetry must never break logging.
            return $record;
        }
    }
}
```

- [ ] **Step 9: Run — expect PASS.** Then full gate + deptrac:
```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-observability-logger/tests/Unit
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
make cs-fix && make phpcs && make psalm
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --config-file=deptrac.yaml
```
Expected: all green; deptrac `ObservabilityLogger → {Logger, Observability}`, 0 violations.

- [ ] **Step 10: Commit**
```bash
git add packages/nexus-observability-logger composer.json composer.lock deptrac.yaml phpunit.xml
git -c commit.gpgsign=false commit --no-verify -m "feat(observability-logger): TraceCorrelationProcessor (logs↔traces via active span ids)"
```

---

## Self-Review (plan author)

- **Spec coverage (Plan 8 slice — §9 logs, D13, §12):** logs carry `trace_id`/`span_id`/`trace_flags` from the active OTel span (MDC-equivalent correlation) ✓; OTel-driven source of truth reconciling the `TraceContextMiddleware` MDC span-id divergence (D13) ✓ (documented; Plan 9 wires this processor as the source of truth); fail-isolation + disabled fast-path ✓ (§12). **Deferred (documented):** OTLP log-record export (OTEL logs SDK + nexus-logger Handler bridge) — follow-up; the correlation processor is the essential D13 value.
- **Placeholder scan:** none — complete code or exact commands; `Record::create` signature flagged to verify.
- **Type consistency:** `TraceCorrelationProcessor(Observability)` implements `RecordProcessor::process(Record): Record`; reads `currentContext()->spanContext->{traceId,spanId,traceFlags,isValid}` (Plan 1/3 API); `withExtra()` merge semantics confirmed; extra keys alphabetical (`span_id` < `trace_flags` < `trace_id`).

## Downstream: Plan 9 = config wiring (NexusApp builder + env vars + skeleton wizard; register the ServerSpanMiddleware / HttpMetricsListener / pool listeners / TraceCorrelationProcessor / transport & store decorators; make TraceCorrelationProcessor the log span-id source when observability is enabled). Deferred here: OTLP log-record export.
