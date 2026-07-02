# Observability — Plan 5: Persistence (`nexus-observability-persistence`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Instrument the persistence layer with tracing spans + metrics via decorators over the `EventStore`, `SnapshotStore`, and `DurableStateStore` interfaces — driven by the injected `Observability` provider (no-op default), fail-isolated so telemetry never breaks a persistence operation.

**Architecture:** Three decorator classes wrap an inner store and add an Internal span per operation (persist/load/delete/save/get/upsert) plus metrics (events persisted, snapshots saved, per-operation duration). Store errors propagate (the persistence engine handles them); only telemetry is guarded. Disabled → pass-through to the inner store with zero overhead. These decorators are storage-implementation-agnostic; the SQL-level Client spans come later in Plan 7 (Doctrine).

**Tech Stack:** PHP 8.5.7, `nexus-observability` (+ `nexus-observability-otel` & `open-telemetry/sdk` dev-deps for tests, `nexus-persistence` for the interfaces + in-memory stores), PHPUnit 13, Psalm L1, PHPCS, Deptrac, Docker.

## Global Constraints

- **Docker only:** prefix every command with `docker compose exec -T php`. `composer dump-autoload` after adding classes.
- **Commit policy:** commit with `git -c commit.gpgsign=false commit --no-verify` (GrumPHP hook broken + worktree GPG times out). Before EVERY commit run `make cs-fix && make phpcs && make psalm` (all clean) + package suite. `make phpcs` enforces `ReferenceUsedNamesOnly` (no inline `\FQCN`). `Warning: JIT...` is env noise.
- **NEVER** add `Co-Authored-By: Claude`.
- **No singletons:** DI only.
- **Fail-isolation (§12):** telemetry calls guarded in a `safely()` try/catch; the inner store call is NOT guarded — store exceptions propagate (record on span first, then rethrow). Disabled fast-path delegates to the inner store with zero telemetry work.
- **Attributes (D5/D11):** span attributes: `nexus.persistence.id` (string), `nexus.persistence.entity.type`, and `nexus.persistence.event.count` (persist only) — metadata only. Metric dimensions: `nexus.persistence.entity.type` (bounded) + `operation` (bounded) — never the full persistence id (unbounded).
- **Code style:** `declare(strict_types=1);`; classes `final`; `/** @psalm-api */` on public API; alphabetical imports (+ `use function`); string-keyed arrays **alphabetical**; trailing commas in multiline; blank line before control structures; multi-line ternaries; `#[Override]` on interface methods.
- **Deptrac:** new layer `ObservabilityPersistence` may depend only on `Observability` + `Persistence`.
- **Tests:** decorate the real in-memory stores (`InMemoryEventStore`/`InMemorySnapshotStore`/`InMemoryDurableStateStore`) and assert exported spans/metrics via the OTEL bridge SDK in-memory exporters; also assert delegation (data actually persisted/loaded).

## Verified seams

- `Monadial\Nexus\Persistence\Event\EventStore`: `persist(PersistenceId $id, EventEnvelope ...$events): void`; `load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable`; `deleteUpTo(PersistenceId $id, int $toSequenceNr): void`; `highestSequenceNr(PersistenceId $id): int`.
- `Monadial\Nexus\Persistence\Snapshot\SnapshotStore`: `save(PersistenceId $id, SnapshotEnvelope $snapshot): void`; `load(PersistenceId $id): ?SnapshotEnvelope`; `delete(PersistenceId $id, int $maxSequenceNr): void`.
- `Monadial\Nexus\Persistence\State\DurableStateStore`: `get(PersistenceId $id): ?DurableStateEnvelope`; `upsert(PersistenceId $id, DurableStateEnvelope $state): void`; `delete(PersistenceId $id): void`.
- `PersistenceId`: public `string $entityType`, `string $entityId`; `toString(): string`; `__toString(): string`; `::of(type, id)`.
- `Observability`: `isEnabled()`, `tracer()`, `meter()`. `Tracer::startSpan(name, SpanKind, array<string,scalar>, ?Context)`. `Meter::counter/histogram`.

---

## File Structure

```
packages/nexus-observability-persistence/
  composer.json
  src/
    TracingEventStore.php
    TracingSnapshotStore.php
    TracingDurableStateStore.php
  tests/
    Unit/
      TracingEventStoreTest.php
      TracingSnapshotStoreTest.php
      TracingDurableStateStoreTest.php
```
Shared files modified by Task 1: root `composer.json`, `deptrac.yaml`, `phpunit.xml`.

---

## Task 1: Scaffold + `TracingEventStore`

**Files:**
- Create: `packages/nexus-observability-persistence/composer.json`
- Create: `packages/nexus-observability-persistence/src/TracingEventStore.php`
- Create: `packages/nexus-observability-persistence/tests/Unit/TracingEventStoreTest.php`
- Modify: root `composer.json`, `deptrac.yaml`, `phpunit.xml`

**Interfaces:**
- Produces: `final class TracingEventStore implements EventStore` — ctor `(EventStore $inner, Observability $observability)`; spans each operation (`EventStore.persist/load/deleteUpTo/highestSequenceNr`), `nexus.persistence.events.persisted` counter (+ event count), `nexus.persistence.operation.duration` histogram; disabled → delegate; store errors propagate.

- [ ] **Step 1: `packages/nexus-observability-persistence/composer.json`**
```json
{
    "name": "nexus-actors/observability-persistence",
    "description": "Nexus persistence observability — tracing decorators for event/snapshot/durable-state stores.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5.7",
        "nexus-actors/observability": "dev-main",
        "nexus-actors/persistence": "dev-main"
    },
    "require-dev": {
        "nexus-actors/observability-otel": "dev-main",
        "open-telemetry/sdk": "^1.14",
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Persistence\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Persistence\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Root `composer.json`** — add to `autoload.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\Persistence\\": "packages/nexus-observability-persistence/src/",
```
and to `autoload-dev.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\Persistence\\Tests\\": "packages/nexus-observability-persistence/tests/",
```
Run `docker compose exec -T php composer dump-autoload`.

- [ ] **Step 3: `deptrac.yaml`** — add layer:
```yaml
    - name: ObservabilityPersistence
      collectors:
        - type: directory
          value: packages/nexus-observability-persistence/src/.*
```
and ruleset:
```yaml
    ObservabilityPersistence:
      - Observability
      - Persistence
```

- [ ] **Step 4: `phpunit.xml`** — add to `<testsuite name="unit">`:
```xml
            <directory>packages/nexus-observability-persistence/tests/Unit</directory>
```
and to `<source><include>`:
```xml
            <directory>packages/nexus-observability-persistence/src</directory>
```

- [ ] **Step 5: Write the failing test**

`packages/nexus-observability-persistence/tests/Unit/TracingEventStoreTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Persistence\Tests\Unit;

use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Observability\Persistence\TracingEventStore;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function iterator_to_array;
use function is_array;

#[CoversClass(TracingEventStore::class)]
final class TracingEventStoreTest extends TestCase
{
    private InMemoryExporter $spanExporter;
    private TracerProvider $tracerProvider;
    private MetricInMemoryExporter $metricExporter;
    private ExportingReader $reader;
    private OtelObservability $observability;

    protected function setUp(): void
    {
        $this->spanExporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor($this->spanExporter));
        $this->metricExporter = new MetricInMemoryExporter();
        $this->reader = new ExportingReader($this->metricExporter);
        $this->observability = new OtelObservability(
            $this->tracerProvider,
            MeterProvider::builder()->addReader($this->reader)->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );
    }

    #[Test]
    public function persistSpansAndCountsEventsThenDelegates(): void
    {
        $inner = new InMemoryEventStore();
        $store = new TracingEventStore($inner, $this->observability);
        $id = PersistenceId::of('Order', 'order-1');

        $store->persist($id, EventEnvelope::of($id, 1, new EventStoreTestEvent('a')), EventEnvelope::of($id, 2, new EventStoreTestEvent('b')));

        // delegation: the inner store actually has the events
        $loaded = $store->load($id);
        $events = is_array($loaded)
            ? $loaded
            : iterator_to_array($loaded);
        self::assertCount(2, $events);

        $this->tracerProvider->forceFlush();
        $spans = $this->spanExporter->getSpans();
        $names = array_map(static fn ($span): string => $span->getName(), $spans);
        self::assertContains('EventStore.persist', $names);
        self::assertContains('EventStore.load', $names);

        $persistSpan = null;

        foreach ($spans as $span) {
            if ($span->getName() === 'EventStore.persist') {
                $persistSpan = $span;
            }
        }

        self::assertNotNull($persistSpan);
        self::assertSame('Order/order-1', $persistSpan->getAttributes()->get('nexus.persistence.id'));
        self::assertSame('Order', $persistSpan->getAttributes()->get('nexus.persistence.entity.type'));
        self::assertSame(2, $persistSpan->getAttributes()->get('nexus.persistence.event.count'));

        $this->reader->collect();
        $metricNames = array_map(static fn ($metric): string => $metric->name, $this->metricExporter->collect());
        self::assertContains('nexus.persistence.events.persisted', $metricNames);
        self::assertContains('nexus.persistence.operation.duration', $metricNames);
    }

    #[Test]
    public function disabledObservabilityDelegatesWithoutSpans(): void
    {
        $inner = new InMemoryEventStore();
        $store = new TracingEventStore($inner, new \Monadial\Nexus\Observability\NoopObservability());
        $id = PersistenceId::of('Order', 'order-2');

        $store->persist($id, EventEnvelope::of($id, 1, new EventStoreTestEvent('x')));

        self::assertSame(1, $store->highestSequenceNr($id));
        $this->tracerProvider->forceFlush();
        self::assertCount(0, $this->spanExporter->getSpans());
    }
}

final readonly class EventStoreTestEvent
{
    public function __construct(public string $value) {}
}
```
> Note: verify `EventEnvelope::of(...)`'s real signature against `packages/nexus-persistence/src/Event/EventEnvelope.php`; if it differs, adjust the test's envelope construction to the actual factory (the assertions on spans/metrics stay the same). If `load()` returns a `Generator`, `iterator_to_array` handles it; the `is_array` guard covers array returns.

- [ ] **Step 6: Run — expect FAIL** (`TracingEventStore` not found):
`docker compose exec -T php vendor/bin/phpunit packages/nexus-observability-persistence/tests/Unit/TracingEventStoreTest.php`

- [ ] **Step 7: Create `TracingEventStore`**

`packages/nexus-observability-persistence/src/TracingEventStore.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Persistence;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Override;
use Throwable;

use function count;
use function hrtime;

/**
 * @psalm-api
 *
 * Tracing decorator for an {@see EventStore}. Adds an Internal span and metrics
 * per operation. Store errors propagate (recorded on the span first); telemetry
 * errors never break the operation. Delegates directly when observability is
 * disabled.
 */
final class TracingEventStore implements EventStore
{
    private ?Counter $eventsPersisted = null;

    private ?Histogram $operationDuration = null;

    public function __construct(
        private readonly EventStore $inner,
        private readonly Observability $observability,
    ) {}

    #[Override]
    public function persist(PersistenceId $id, EventEnvelope ...$events): void
    {
        if (!$this->observability->isEnabled()) {
            $this->inner->persist($id, ...$events);

            return;
        }

        $span = $this->startSpan('EventStore.persist', $id, ['nexus.persistence.event.count' => count($events)]);
        $start = hrtime(true);

        try {
            $this->inner->persist($id, ...$events);
            $this->safely(fn (): mixed => $this->eventsPersistedCounter()->add(count($events), ['nexus.persistence.entity.type' => $id->entityType]));
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'persist', $id, $start);
        }
    }

    #[Override]
    public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable
    {
        if (!$this->observability->isEnabled()) {
            return $this->inner->load($id, $fromSequenceNr, $toSequenceNr);
        }

        $span = $this->startSpan('EventStore.load', $id, ['nexus.persistence.from_sequence_nr' => $fromSequenceNr]);
        $start = hrtime(true);

        try {
            return $this->inner->load($id, $fromSequenceNr, $toSequenceNr);
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'load', $id, $start);
        }
    }

    #[Override]
    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void
    {
        if (!$this->observability->isEnabled()) {
            $this->inner->deleteUpTo($id, $toSequenceNr);

            return;
        }

        $span = $this->startSpan('EventStore.deleteUpTo', $id, ['nexus.persistence.to_sequence_nr' => $toSequenceNr]);
        $start = hrtime(true);

        try {
            $this->inner->deleteUpTo($id, $toSequenceNr);
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'deleteUpTo', $id, $start);
        }
    }

    #[Override]
    public function highestSequenceNr(PersistenceId $id): int
    {
        if (!$this->observability->isEnabled()) {
            return $this->inner->highestSequenceNr($id);
        }

        $span = $this->startSpan('EventStore.highestSequenceNr', $id, []);
        $start = hrtime(true);

        try {
            return $this->inner->highestSequenceNr($id);
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'highestSequenceNr', $id, $start);
        }
    }

    /**
     * @param array<string, scalar> $extra
     */
    private function startSpan(string $name, PersistenceId $id, array $extra): ?Span
    {
        try {
            return $this->observability->tracer()->startSpan(
                $name,
                SpanKind::Internal,
                [
                    'nexus.persistence.entity.type' => $id->entityType,
                    'nexus.persistence.id' => $id->toString(),
                    ...$extra,
                ],
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function finishSpan(?Span $span, string $operation, PersistenceId $id, int $startNanos): void
    {
        $this->safely(function () use ($span, $operation, $id, $startNanos): void {
            $this->operationDurationHistogram()->record(
                (hrtime(true) - $startNanos) / 1_000_000_000,
                ['nexus.persistence.entity.type' => $id->entityType, 'operation' => $operation],
            );
            $span?->end();
        });
    }

    private function recordError(?Span $span, Throwable $e): void
    {
        $this->safely(static function () use ($span, $e): void {
            $span?->recordException($e);
            $span?->setStatus(StatusCode::Error, $e->getMessage());
        });
    }

    private function eventsPersistedCounter(): Counter
    {
        return $this->eventsPersisted ??= $this->observability->meter()->counter(
            'nexus.persistence.events.persisted',
            '{event}',
            'Events persisted to the event store',
        );
    }

    private function operationDurationHistogram(): Histogram
    {
        return $this->operationDuration ??= $this->observability->meter()->histogram(
            'nexus.persistence.operation.duration',
            's',
            'Duration of persistence store operations',
        );
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break persistence.
        }
    }
}
```

- [ ] **Step 8: Run — expect PASS.** Then `make cs-fix && make phpcs && make psalm` (clean).
> Psalm note: `(hrtime(true) - $startNanos) / 1_000_000_000` is int/int → `record()` accepts int|float. If Psalm flags the `[...] , ...$extra` spread of `array<string,scalar>`, keep the base keys alphabetical and confirm; if it objects, merge via a local `$attributes` variable instead of the spread.

- [ ] **Step 9: Commit**
```bash
git add packages/nexus-observability-persistence composer.json composer.lock deptrac.yaml phpunit.xml
git -c commit.gpgsign=false commit --no-verify -m "feat(observability-persistence): scaffold + TracingEventStore"
```

---

## Task 2: `TracingSnapshotStore` + `TracingDurableStateStore`

**Files:**
- Create: `packages/nexus-observability-persistence/src/TracingSnapshotStore.php`
- Create: `packages/nexus-observability-persistence/src/TracingDurableStateStore.php`
- Create: `packages/nexus-observability-persistence/tests/Unit/TracingSnapshotStoreTest.php`
- Create: `packages/nexus-observability-persistence/tests/Unit/TracingDurableStateStoreTest.php`

**Interfaces:**
- Produces:
  - `final class TracingSnapshotStore implements SnapshotStore` — ctor `(SnapshotStore $inner, Observability $observability)`; spans `SnapshotStore.save/load/delete`; `nexus.persistence.snapshots.saved` counter; operation duration histogram.
  - `final class TracingDurableStateStore implements DurableStateStore` — ctor `(DurableStateStore $inner, Observability $observability)`; spans `DurableStateStore.get/upsert/delete`; operation duration histogram.

- [ ] **Step 1: Write the failing tests**

`packages/nexus-observability-persistence/tests/Unit/TracingSnapshotStoreTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Persistence\Tests\Unit;

use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Observability\Persistence\TracingSnapshotStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\InMemorySnapshotStore;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(TracingSnapshotStore::class)]
final class TracingSnapshotStoreTest extends TestCase
{
    #[Test]
    public function saveSpansAndCountsThenDelegates(): void
    {
        $spanExporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($spanExporter));
        $metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($metricExporter);
        $observability = new OtelObservability(
            $tracerProvider,
            MeterProvider::builder()->addReader($reader)->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $inner = new InMemorySnapshotStore();
        $store = new TracingSnapshotStore($inner, $observability);
        $id = PersistenceId::of('Order', 'order-1');
        $store->save($id, SnapshotEnvelope::of($id, 5, new SnapshotStoreTestState('done')));

        self::assertNotNull($store->load($id)); // delegation

        $tracerProvider->forceFlush();
        $names = array_map(static fn ($span): string => $span->getName(), $spanExporter->getSpans());
        self::assertContains('SnapshotStore.save', $names);
        self::assertContains('SnapshotStore.load', $names);

        $reader->collect();
        $metricNames = array_map(static fn ($metric): string => $metric->name, $metricExporter->collect());
        self::assertContains('nexus.persistence.snapshots.saved', $metricNames);
    }
}

final readonly class SnapshotStoreTestState
{
    public function __construct(public string $value) {}
}
```

`packages/nexus-observability-persistence/tests/Unit/TracingDurableStateStoreTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Persistence\Tests\Unit;

use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Observability\Persistence\TracingDurableStateStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableStateEnvelope;
use Monadial\Nexus\Persistence\State\InMemoryDurableStateStore;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(TracingDurableStateStore::class)]
final class TracingDurableStateStoreTest extends TestCase
{
    #[Test]
    public function upsertAndGetAreSpannedAndDelegated(): void
    {
        $spanExporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($spanExporter));
        $observability = new OtelObservability(
            $tracerProvider,
            MeterProvider::builder()->addReader(new ExportingReader(new MetricInMemoryExporter()))->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $inner = new InMemoryDurableStateStore();
        $store = new TracingDurableStateStore($inner, $observability);
        $id = PersistenceId::of('Cart', 'cart-1');
        $store->upsert($id, DurableStateEnvelope::of($id, 1, new DurableStateTestState('full')));

        self::assertNotNull($store->get($id)); // delegation

        $tracerProvider->forceFlush();
        $names = array_map(static fn ($span): string => $span->getName(), $spanExporter->getSpans());
        self::assertContains('DurableStateStore.upsert', $names);
        self::assertContains('DurableStateStore.get', $names);
    }
}

final readonly class DurableStateTestState
{
    public function __construct(public string $value) {}
}
```
> Verify `SnapshotEnvelope::of(...)` and `DurableStateEnvelope::of(...)` signatures against their source files; adjust construction if the factories differ (assertions stay the same).

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Create `TracingSnapshotStore`** — same pattern as `TracingEventStore` (disabled fast-path, `safely()` telemetry, store errors propagate, per-op duration histogram `nexus.persistence.operation.duration`). `save()` additionally increments `nexus.persistence.snapshots.saved` counter (attr `nexus.persistence.entity.type`). Span names `SnapshotStore.save/load/delete`, `SpanKind::Internal`, attributes `nexus.persistence.entity.type` + `nexus.persistence.id`.

`packages/nexus-observability-persistence/src/TracingSnapshotStore.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Persistence;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Override;
use Throwable;

use function hrtime;

/**
 * @psalm-api
 *
 * Tracing decorator for a {@see SnapshotStore}. Store errors propagate;
 * telemetry never breaks the operation; delegates when disabled.
 */
final class TracingSnapshotStore implements SnapshotStore
{
    private ?Counter $snapshotsSaved = null;

    private ?Histogram $operationDuration = null;

    public function __construct(
        private readonly SnapshotStore $inner,
        private readonly Observability $observability,
    ) {}

    #[Override]
    public function save(PersistenceId $id, SnapshotEnvelope $snapshot): void
    {
        if (!$this->observability->isEnabled()) {
            $this->inner->save($id, $snapshot);

            return;
        }

        $span = $this->startSpan('SnapshotStore.save', $id);
        $start = hrtime(true);

        try {
            $this->inner->save($id, $snapshot);
            $this->safely(fn (): mixed => $this->snapshotsSavedCounter()->add(1, ['nexus.persistence.entity.type' => $id->entityType]));
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'save', $id, $start);
        }
    }

    #[Override]
    public function load(PersistenceId $id): ?SnapshotEnvelope
    {
        if (!$this->observability->isEnabled()) {
            return $this->inner->load($id);
        }

        $span = $this->startSpan('SnapshotStore.load', $id);
        $start = hrtime(true);

        try {
            return $this->inner->load($id);
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'load', $id, $start);
        }
    }

    #[Override]
    public function delete(PersistenceId $id, int $maxSequenceNr): void
    {
        if (!$this->observability->isEnabled()) {
            $this->inner->delete($id, $maxSequenceNr);

            return;
        }

        $span = $this->startSpan('SnapshotStore.delete', $id);
        $start = hrtime(true);

        try {
            $this->inner->delete($id, $maxSequenceNr);
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'delete', $id, $start);
        }
    }

    private function startSpan(string $name, PersistenceId $id): ?Span
    {
        try {
            return $this->observability->tracer()->startSpan(
                $name,
                SpanKind::Internal,
                [
                    'nexus.persistence.entity.type' => $id->entityType,
                    'nexus.persistence.id' => $id->toString(),
                ],
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function finishSpan(?Span $span, string $operation, PersistenceId $id, int $startNanos): void
    {
        $this->safely(function () use ($span, $operation, $id, $startNanos): void {
            $this->operationDurationHistogram()->record(
                (hrtime(true) - $startNanos) / 1_000_000_000,
                ['nexus.persistence.entity.type' => $id->entityType, 'operation' => $operation],
            );
            $span?->end();
        });
    }

    private function recordError(?Span $span, Throwable $e): void
    {
        $this->safely(static function () use ($span, $e): void {
            $span?->recordException($e);
            $span?->setStatus(StatusCode::Error, $e->getMessage());
        });
    }

    private function snapshotsSavedCounter(): Counter
    {
        return $this->snapshotsSaved ??= $this->observability->meter()->counter(
            'nexus.persistence.snapshots.saved',
            '{snapshot}',
            'Snapshots written to the snapshot store',
        );
    }

    private function operationDurationHistogram(): Histogram
    {
        return $this->operationDuration ??= $this->observability->meter()->histogram(
            'nexus.persistence.operation.duration',
            's',
            'Duration of persistence store operations',
        );
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break persistence.
        }
    }
}
```

- [ ] **Step 4: Create `TracingDurableStateStore`** — same pattern; span names `DurableStateStore.get/upsert/delete`, operation duration histogram; no dedicated counter (duration histogram's count suffices). `get()` returns `?DurableStateEnvelope`, `upsert()`/`delete()` return void.

`packages/nexus-observability-persistence/src/TracingDurableStateStore.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Persistence;

use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableStateEnvelope;
use Monadial\Nexus\Persistence\State\DurableStateStore;
use Override;
use Throwable;

use function hrtime;

/**
 * @psalm-api
 *
 * Tracing decorator for a {@see DurableStateStore}. Store errors propagate;
 * telemetry never breaks the operation; delegates when disabled.
 */
final class TracingDurableStateStore implements DurableStateStore
{
    private ?Histogram $operationDuration = null;

    public function __construct(
        private readonly DurableStateStore $inner,
        private readonly Observability $observability,
    ) {}

    #[Override]
    public function get(PersistenceId $id): ?DurableStateEnvelope
    {
        if (!$this->observability->isEnabled()) {
            return $this->inner->get($id);
        }

        $span = $this->startSpan('DurableStateStore.get', $id);
        $start = hrtime(true);

        try {
            return $this->inner->get($id);
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'get', $id, $start);
        }
    }

    #[Override]
    public function upsert(PersistenceId $id, DurableStateEnvelope $state): void
    {
        if (!$this->observability->isEnabled()) {
            $this->inner->upsert($id, $state);

            return;
        }

        $span = $this->startSpan('DurableStateStore.upsert', $id);
        $start = hrtime(true);

        try {
            $this->inner->upsert($id, $state);
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'upsert', $id, $start);
        }
    }

    #[Override]
    public function delete(PersistenceId $id): void
    {
        if (!$this->observability->isEnabled()) {
            $this->inner->delete($id);

            return;
        }

        $span = $this->startSpan('DurableStateStore.delete', $id);
        $start = hrtime(true);

        try {
            $this->inner->delete($id);
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, 'delete', $id, $start);
        }
    }

    private function startSpan(string $name, PersistenceId $id): ?Span
    {
        try {
            return $this->observability->tracer()->startSpan(
                $name,
                SpanKind::Internal,
                [
                    'nexus.persistence.entity.type' => $id->entityType,
                    'nexus.persistence.id' => $id->toString(),
                ],
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function finishSpan(?Span $span, string $operation, PersistenceId $id, int $startNanos): void
    {
        $this->safely(function () use ($span, $operation, $id, $startNanos): void {
            $this->operationDurationHistogram()->record(
                (hrtime(true) - $startNanos) / 1_000_000_000,
                ['nexus.persistence.entity.type' => $id->entityType, 'operation' => $operation],
            );
            $span?->end();
        });
    }

    private function recordError(?Span $span, Throwable $e): void
    {
        $this->safely(static function () use ($span, $e): void {
            $span?->recordException($e);
            $span?->setStatus(StatusCode::Error, $e->getMessage());
        });
    }

    private function operationDurationHistogram(): Histogram
    {
        return $this->operationDuration ??= $this->observability->meter()->histogram(
            'nexus.persistence.operation.duration',
            's',
            'Duration of persistence store operations',
        );
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break persistence.
        }
    }
}
```

- [ ] **Step 5: Run — expect PASS.** Then full gate + deptrac:
```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-observability-persistence/tests/Unit
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
make cs-fix && make phpcs && make psalm
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --config-file=deptrac.yaml
```
Expected: all green; deptrac shows `ObservabilityPersistence → {Observability, Persistence}`, 0 violations.

- [ ] **Step 6: Commit**
```bash
git add packages/nexus-observability-persistence
git -c commit.gpgsign=false commit --no-verify -m "feat(observability-persistence): TracingSnapshotStore + TracingDurableStateStore"
```

---

## Self-Review (plan author)

- **Spec coverage (Plan 5 slice — §7 persistence, §8 metrics, D5/D11, §12):** decorators over EventStore/SnapshotStore/DurableStateStore ✓; Internal spans per operation with `persistence.id`/`entity.type`/`event.count` (metadata only, D5) ✓; metrics: events persisted, snapshots saved, per-operation duration with low-cardinality dims (entity.type + operation, D11) ✓; fail-isolation + store-errors-propagate + disabled fast-path ✓ (§12). **Out of scope (documented):** SQL-level Client spans (Plan 7 Doctrine); wiring the decorators into the persistence engine (Plan 9); recovery/replay engine spans if `PersistenceEngine` is a separate seam (note: the load span already covers replay reads).
- **Placeholder scan:** none — complete code or exact commands; two envelope-factory signatures flagged to verify against source (assertions unaffected).
- **Type consistency:** all three decorators share the ctor shape `(Inner $inner, Observability $observability)` and the `safely`/`recordError`/`finishSpan`/`startSpan` helper set. Span names and metric names used in code match the test assertions. `SpanKind::Internal` throughout (matches spec §5 example).

## Downstream: Plan 6 = worker-pool (`nexus-observability-worker-pool`: transport/cluster metrics + routing attributes; note actor-boundary propagation already works via Envelope::metadata from Plan 3). Deferred from here: persistence-engine/recovery-orchestration spans + decorator wiring (Plan 9); Doctrine SQL spans (Plan 7).
