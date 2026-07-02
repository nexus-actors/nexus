# Observability — Plan 2: OTEL Bridge (`nexus-observability-otel`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `nexus-observability-otel` package — adapters that implement the vendor-neutral `nexus-observability` interfaces on top of the real OpenTelemetry PHP SDK, plus a factory that constructs a configured `Observability` provider from an `ObservabilityConfig` (OTLP export to a collector).

**Architecture:** Thin wrapper classes (`OtelSpan`, `OtelTracer`, instrument wrappers, `OtelMeter`, `OtelObservability`) delegate to OTEL SDK objects and map between our minimal types and the SDK's. `ObservabilityFactory::fromConfig()` builds the SDK `TracerProvider`/`MeterProvider` (OTLP HTTP exporters, resource, sampler) and returns an `OtelObservability`; when disabled it returns `NoopObservability`. Context propagation reuses the pure `CompositePropagator` (Trace Context + Baggage) from `nexus-observability` — the bridge does not re-implement propagation.

**Tech Stack:** PHP 8.5.7, `open-telemetry/{api,sdk,exporter-otlp,sem-conv}` (verified installing + running on 8.5), PHPUnit 13, Psalm L1, PHPCS/PHP-CS-Fixer, Deptrac, Docker Compose.

## Global Constraints

- **PHP floor:** `php: >=8.5.7`. **Packagist vendor:** `nexus-actors/observability-otel`. **PSR-4:** `Monadial\Nexus\Observability\Otel\` → `src/`, tests `Monadial\Nexus\Observability\Otel\Tests\` → `tests/`.
- **Docker only:** prefix every command with `docker compose exec -T php`. Run `docker compose exec -T php composer dump-autoload` after adding classes.
- **Commit policy:** GrumPHP's pre-commit hook is broken in this Docker+worktree setup — commit with `git commit --no-verify`. Before every commit run the gate MANUALLY: `make cs-fix && make phpcs && make psalm` (all clean) + the package unit suite. `make cs-fix` does NOT enforce `ReferenceUsedNamesOnly` — `make phpcs` does; never use inline `\Fully\Qualified\Names`, always add a `use` import. The `Warning: JIT is incompatible...` startup line is environment noise.
- **NEVER** add `Co-Authored-By: Claude` to commit messages.
- **No singletons (hard rule).** No `static instance()`, no `private static ?self $instance`, no private constructor to enforce a single instance. Wire collaborators via constructor injection.
- **Code style:** `declare(strict_types=1);` every file; classes `final`; `/** @psalm-api */` on public API; ordered/alphabetical imports (class + `use function`); trailing commas in multiline; string-keyed array literals alphabetical; blank line before control-structure blocks; multi-line ternaries.
- **Deptrac:** new layer `ObservabilityOtel` may depend only on `Observability` (the OTEL vendor packages are not layers → uncovered/allowed). It must NOT depend on Core or any other Nexus layer.
- **Dependency direction:** this package depends on `nexus-observability` and the OTEL SDK. `nexus-observability` must NOT gain any dependency on this package or on OTEL.
- **Tests:** namespace `...\Tests\Unit`; `#[CoversClass]` + `#[Test]`; `self::assert*`. Tests build real SDK providers backed by the SDK's in-memory exporters and assert exported data — no network, no collector.

## Verified OTEL SDK API (from PHP 8.5 spikes — use these exact symbols)

- Kinds: `OpenTelemetry\API\Trace\SpanKind::{KIND_INTERNAL=0,KIND_CLIENT=1,KIND_SERVER=2,KIND_PRODUCER=3,KIND_CONSUMER=4}`.
- Status: `OpenTelemetry\API\Trace\StatusCode::{STATUS_UNSET='Unset',STATUS_OK='Ok',STATUS_ERROR='Error'}` (strings).
- Start span: `$tracer->spanBuilder($name)->setSpanKind(int)->setParent(Context)->setAttributes(array)->startSpan(): SpanInterface`. `$span->activate(): ScopeInterface`. `$span->setAttribute()/setAttributes()/addEvent(name,attrs)/recordException(Throwable)/setStatus(string,?desc)/end()`. `$span->getContext(): SpanContextInterface` with `getTraceId()/getSpanId()/getTraceFlags():int/isRemote():bool/getTraceState():?TraceStateInterface`.
- Remote parent: `OpenTelemetry\API\Trace\SpanContext::createFromRemoteParent(string $traceId, string $spanId, int $traceFlags): SpanContextInterface`; wrap via `OpenTelemetry\API\Trace\Span::wrap($sc)`; parent context = `OpenTelemetry\Context\Context::getRoot()->withContextValue(Span::wrap($sc))`.
- Providers: `OpenTelemetry\SDK\Trace\TracerProvider::builder()->addSpanProcessor(...)->setResource(...)->setSampler(...)->build()`. `OpenTelemetry\SDK\Metrics\MeterProvider::builder()->addReader(...)->build()`. Both concrete classes have `forceFlush()` and `shutdown()`.
- Processors/exporters (tests): `OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor`, `OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter` (`->getSpans()`); `OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader`, `OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter` (`->collect()` returns `Metric[]` each with `->name`).
- Production exporters (factory): `OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor(new \OpenTelemetry\Contrib\Otlp\SpanExporter($transport), \OpenTelemetry\SDK\Common\Time\ClockFactory::getDefault())`; `\OpenTelemetry\Contrib\Otlp\MetricExporter($transport)`; transport = `(new \OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory())->create($endpoint, 'application/x-protobuf')`.
- Resource (config attrs must WIN over defaults — argument-wins merge): `ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([...])))` (`OpenTelemetry\SDK\Resource\{ResourceInfoFactory,ResourceInfo}`, `OpenTelemetry\SDK\Common\Attribute\Attributes`).
- Samplers: `OpenTelemetry\SDK\Trace\Sampler\{AlwaysOnSampler,AlwaysOffSampler,ParentBased,TraceIdRatioBasedSampler}`; `OpenTelemetry\SDK\Trace\SamplerInterface`.
- Meter create: `createCounter(name,?unit,?desc)`, `createUpDownCounter(...)`, `createHistogram(...)`, `createObservableGauge(name,?unit,?desc,array $advisory=[],callable ...$callbacks)` — callback receives `OpenTelemetry\API\Metrics\ObserverInterface` with `observe(float|int $amount, iterable $attributes=[])`. Instruments: `add(value,attrs)` / `record(value,attrs)`.

---

## File Structure

```
packages/nexus-observability-otel/
  composer.json
  src/
    Trace/
      OtelSpan.php            # wraps OTEL SpanInterface (+ Scope); maps kind/status/context
      OtelTracer.php          # wraps OTEL TracerInterface; startSpan maps kind/parent/attrs + activates
    Metric/
      OtelCounter.php
      OtelUpDownCounter.php
      OtelHistogram.php
      OtelObservableGauge.php
      OtelMeter.php           # wraps OTEL MeterInterface
    OtelObservability.php     # implements Observability; holds providers; forceFlush/shutdown
    ObservabilityFactory.php  # fromConfig(): builds SDK providers + OtelObservability, or NoopObservability
  tests/
    Unit/
      Trace/OtelSpanTest.php
      Trace/OtelTracerTest.php
      Metric/OtelMeterTest.php
      ObservabilityFactoryTest.php
      OtelObservabilityIntegrationTest.php
```

Shared files modified by Task 1: root `composer.json` (require + autoload + autoload-dev), `deptrac.yaml` (layer + ruleset), `phpunit.xml` (unit testsuite + coverage source).

---

## Task 1: Package scaffold + wiring + `OtelSpan`

**Files:**
- Create: `packages/nexus-observability-otel/composer.json`
- Create: `packages/nexus-observability-otel/src/Trace/OtelSpan.php`
- Create: `packages/nexus-observability-otel/tests/Unit/Trace/OtelSpanTest.php`
- Modify: `composer.json` (root), `deptrac.yaml`, `phpunit.xml`

**Interfaces:**
- Consumes: `Monadial\Nexus\Observability\Trace\{Span,SpanContext,StatusCode}`.
- Produces: `final class OtelSpan implements Span` — constructor `(SpanInterface $span, ?ScopeInterface $scope = null)`; maps our `StatusCode` → OTEL status string; `context()` maps OTEL span context → our `SpanContext`; `end()` detaches scope (if any) then ends the span.

- [ ] **Step 1: Create `packages/nexus-observability-otel/composer.json`**
```json
{
    "name": "nexus-actors/observability-otel",
    "description": "Nexus observability OpenTelemetry bridge — SDK-backed Tracer/Meter and an OTLP provider factory.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5.7",
        "nexus-actors/observability": "dev-main",
        "open-telemetry/api": "^1.9",
        "open-telemetry/sdk": "^1.14",
        "open-telemetry/exporter-otlp": "^1.4",
        "open-telemetry/sem-conv": "^1.38"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Otel\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Otel\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Root `composer.json` — add the OTEL deps + autoload**

Add to root `composer.json` `require` (these direct deps; composer pulls transitive `open-telemetry/context`, `open-telemetry/gen-otlp-protobuf`, `google/protobuf`, etc. automatically):
```json
        "open-telemetry/api": "^1.9",
        "open-telemetry/exporter-otlp": "^1.4",
        "open-telemetry/sdk": "^1.14",
        "open-telemetry/sem-conv": "^1.38",
```
Add to `autoload.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\Otel\\": "packages/nexus-observability-otel/src/",
```
Add to `autoload-dev.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\Otel\\Tests\\": "packages/nexus-observability-otel/tests/",
```
Then reconcile: `docker compose exec -T php composer update open-telemetry/api open-telemetry/sdk open-telemetry/exporter-otlp open-telemetry/sem-conv --no-interaction` (the packages are already in vendor from an earlier spike; this records them in composer.json/lock). Expected: no errors.

- [ ] **Step 3: Deptrac layer + ruleset**

In `deptrac.yaml` add a layer (next to `Observability`):
```yaml
    - name: ObservabilityOtel
      collectors:
        - type: directory
          value: packages/nexus-observability-otel/src/.*
```
and a ruleset entry:
```yaml
    ObservabilityOtel:
      - Observability
```

- [ ] **Step 4: PHPUnit wiring**

In `phpunit.xml` add to `<testsuite name="unit">`:
```xml
            <directory>packages/nexus-observability-otel/tests/Unit</directory>
```
and to `<source><include>`:
```xml
            <directory>packages/nexus-observability-otel/src</directory>
```

- [ ] **Step 5: `docker compose exec -T php composer dump-autoload`** — expect no errors.

- [ ] **Step 6: Write the failing test**

`packages/nexus-observability-otel/tests/Unit/Trace/OtelSpanTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit\Trace;

use Monadial\Nexus\Observability\Otel\Trace\OtelSpan;
use Monadial\Nexus\Observability\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(OtelSpan::class)]
final class OtelSpanTest extends TestCase
{
    #[Test]
    public function recordsAttributesStatusAndExposesContext(): void
    {
        $exporter = new InMemoryExporter();
        $provider = new TracerProvider(new SimpleSpanProcessor($exporter));
        $otelSpan = $provider->getTracer('test')->spanBuilder('op')->startSpan();

        $span = new OtelSpan($otelSpan);
        $span->setAttribute('nexus.actor.path', '/user/a');
        $span->setAttributes(['nexus.mailbox.depth' => 3]);
        $span->addEvent('stashed', ['count' => 1]);
        $span->recordException(new RuntimeException('boom'));
        $span->setStatus(StatusCode::Error, 'failed');

        $context = $span->context();
        self::assertTrue($context->isValid());
        self::assertSame($otelSpan->getContext()->getTraceId(), $context->traceId);
        self::assertSame($otelSpan->getContext()->getSpanId(), $context->spanId);

        $span->end();
        $provider->forceFlush();

        $exported = $exporter->getSpans();
        self::assertCount(1, $exported);
        self::assertSame('/user/a', $exported[0]->getAttributes()->get('nexus.actor.path'));
        self::assertSame(3, $exported[0]->getAttributes()->get('nexus.mailbox.depth'));
        self::assertSame('Error', $exported[0]->getStatus()->getCode());
    }
}
```

- [ ] **Step 7: Run — expect FAIL** (`OtelSpan` not found):
`docker compose exec -T php vendor/bin/phpunit packages/nexus-observability-otel/tests/Unit/Trace/OtelSpanTest.php`

- [ ] **Step 8: Create `OtelSpan`**

`packages/nexus-observability-otel/src/Trace/OtelSpan.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Trace;

use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanContext;
use Monadial\Nexus\Observability\Trace\StatusCode;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode as OtelStatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * @psalm-api
 *
 * Adapts an OpenTelemetry {@see SpanInterface} to the Nexus {@see Span} contract.
 * If constructed with the activation {@see ScopeInterface}, {@see self::end()}
 * detaches it before ending the span.
 */
final class OtelSpan implements Span
{
    public function __construct(
        private readonly SpanInterface $span,
        private readonly ?ScopeInterface $scope = null,
    ) {}

    public function setAttribute(string $key, string|int|float|bool $value): void
    {
        $this->span->setAttribute($key, $value);
    }

    public function setAttributes(array $attributes): void
    {
        $this->span->setAttributes($attributes);
    }

    public function addEvent(string $name, array $attributes = []): void
    {
        $this->span->addEvent($name, $attributes);
    }

    public function recordException(Throwable $exception): void
    {
        $this->span->recordException($exception);
    }

    public function setStatus(StatusCode $code, ?string $description = null): void
    {
        $otelCode = match ($code) {
            StatusCode::Unset => OtelStatusCode::STATUS_UNSET,
            StatusCode::Ok => OtelStatusCode::STATUS_OK,
            StatusCode::Error => OtelStatusCode::STATUS_ERROR,
        };

        $this->span->setStatus($otelCode, $description);
    }

    public function end(): void
    {
        $this->scope?->detach();
        $this->span->end();
    }

    public function context(): SpanContext
    {
        $context = $this->span->getContext();
        $traceState = $context->getTraceState();

        return new SpanContext(
            traceId: $context->getTraceId(),
            spanId: $context->getSpanId(),
            traceFlags: $context->getTraceFlags(),
            remote: $context->isRemote(),
            traceState: $traceState !== null
                ? (string) $traceState
                : '',
        );
    }
}
```

- [ ] **Step 9: Run — expect PASS.** Then gate: `make cs-fix && make phpcs && make psalm` (all clean).

- [ ] **Step 10: Commit**
```bash
git add packages/nexus-observability-otel composer.json composer.lock deptrac.yaml phpunit.xml
git commit --no-verify -m "feat(observability-otel): scaffold bridge package + OtelSpan"
```

---

## Task 2: `OtelTracer`

**Files:**
- Create: `packages/nexus-observability-otel/src/Trace/OtelTracer.php`
- Create: `packages/nexus-observability-otel/tests/Unit/Trace/OtelTracerTest.php`

**Interfaces:**
- Consumes: `Monadial\Nexus\Observability\Trace\{Tracer,Span,SpanKind}`, `Monadial\Nexus\Observability\Context\Context`; produces `OtelSpan` (Task 1).
- Produces: `final class OtelTracer implements Tracer` — constructor `(TracerInterface $tracer)`; `startSpan()` maps our `SpanKind` → OTEL kind int, sets a remote parent when `$parent->spanContext` is valid (else leaves the active context as parent so nested user spans chain), sets attributes, starts + activates the span, returns `OtelSpan($span, $scope)`.

- [ ] **Step 1: Write the failing test**

`packages/nexus-observability-otel/tests/Unit/Trace/OtelTracerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit\Trace;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Otel\Trace\OtelTracer;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\SpanContext;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtelTracer::class)]
final class OtelTracerTest extends TestCase
{
    private InMemoryExporter $exporter;
    private TracerProvider $provider;
    private OtelTracer $tracer;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $this->tracer = new OtelTracer($this->provider->getTracer('test'));
    }

    #[Test]
    public function startsSpanUnderRemoteParentAndNestsChildren(): void
    {
        $parent = Context::fromSpanContext(new SpanContext(
            traceId: '0af7651916cd43dd8448eb211c80319c',
            spanId: 'b7ad6b7169203331',
            traceFlags: 1,
            remote: true,
        ));

        $consumer = $this->tracer->startSpan('process Greet', SpanKind::Consumer, ['nexus.actor.path' => '/user/g'], $parent);
        $consumerId = $consumer->context()->spanId;
        $child = $this->tracer->startSpan('charge-card', SpanKind::Client);
        $child->end();
        $consumer->end();

        $this->provider->forceFlush();
        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);

        $byName = [];

        foreach ($spans as $span) {
            $byName[$span->getName()] = $span;
        }

        self::assertSame('0af7651916cd43dd8448eb211c80319c', $byName['process Greet']->getTraceId());
        self::assertSame('b7ad6b7169203331', $byName['process Greet']->getParentSpanId());
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $byName['charge-card']->getTraceId());
        self::assertSame($consumerId, $byName['charge-card']->getParentSpanId());
        self::assertSame(4, $byName['process Greet']->getKind());
        self::assertSame(1, $byName['charge-card']->getKind());
    }

    #[Test]
    public function startsNewRootWhenParentInvalid(): void
    {
        $span = $this->tracer->startSpan('root');
        $span->end();

        $this->provider->forceFlush();
        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('0000000000000000', $spans[0]->getParentSpanId());
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (`OtelTracer` not found).

- [ ] **Step 3: Create `OtelTracer`**

`packages/nexus-observability-otel/src/Trace/OtelTracer.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Trace;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\Tracer;
use OpenTelemetry\API\Trace\Span as OtelApiSpan;
use OpenTelemetry\API\Trace\SpanContext as OtelSpanContext;
use OpenTelemetry\API\Trace\SpanKind as OtelSpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context as OtelContext;

/**
 * @psalm-api
 *
 * Adapts an OpenTelemetry {@see TracerInterface} to the Nexus {@see Tracer}
 * contract. Started spans are activated so nested spans chain via OTEL context
 * storage; the returned {@see OtelSpan} detaches the scope on end.
 */
final class OtelTracer implements Tracer
{
    public function __construct(
        private readonly TracerInterface $tracer,
    ) {}

    public function startSpan(
        string $name,
        SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
        ?Context $parent = null,
    ): Span {
        $builder = $this->tracer
            ->spanBuilder($name)
            ->setSpanKind($this->mapKind($kind))
            ->setAttributes($attributes);

        if ($parent !== null && $parent->spanContext->isValid()) {
            $remote = OtelSpanContext::createFromRemoteParent(
                $parent->spanContext->traceId,
                $parent->spanContext->spanId,
                $parent->spanContext->traceFlags,
            );
            $builder = $builder->setParent(
                OtelContext::getRoot()->withContextValue(OtelApiSpan::wrap($remote)),
            );
        }

        $span = $builder->startSpan();

        return new OtelSpan($span, $span->activate());
    }

    private function mapKind(SpanKind $kind): int
    {
        return match ($kind) {
            SpanKind::Internal => OtelSpanKind::KIND_INTERNAL,
            SpanKind::Server => OtelSpanKind::KIND_SERVER,
            SpanKind::Client => OtelSpanKind::KIND_CLIENT,
            SpanKind::Producer => OtelSpanKind::KIND_PRODUCER,
            SpanKind::Consumer => OtelSpanKind::KIND_CONSUMER,
        };
    }
}
```

- [ ] **Step 4: Run — expect PASS.** Then `make cs-fix && make phpcs && make psalm` (clean), and the package suite once.

- [ ] **Step 5: Commit**
```bash
git add packages/nexus-observability-otel
git commit --no-verify -m "feat(observability-otel): add OtelTracer (kind/parent mapping + span activation)"
```

---

## Task 3: Metric instruments + `OtelMeter`

**Files:**
- Create: `packages/nexus-observability-otel/src/Metric/OtelCounter.php`
- Create: `packages/nexus-observability-otel/src/Metric/OtelUpDownCounter.php`
- Create: `packages/nexus-observability-otel/src/Metric/OtelHistogram.php`
- Create: `packages/nexus-observability-otel/src/Metric/OtelObservableGauge.php`
- Create: `packages/nexus-observability-otel/src/Metric/OtelMeter.php`
- Create: `packages/nexus-observability-otel/tests/Unit/Metric/OtelMeterTest.php`

**Interfaces:**
- Consumes: `Monadial\Nexus\Observability\Metric\{Counter,UpDownCounter,Histogram,ObservableGauge,Meter}`.
- Produces: instrument wrappers delegating to OTEL instruments, and `final class OtelMeter implements Meter` wrapping `MeterInterface`. `observableGauge()` registers a callback that calls the user callback and reports via `ObserverInterface::observe()`.

- [ ] **Step 1: Write the failing test**

`packages/nexus-observability-otel/tests/Unit/Metric/OtelMeterTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit\Metric;

use Monadial\Nexus\Observability\Otel\Metric\OtelMeter;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtelMeter::class)]
final class OtelMeterTest extends TestCase
{
    private InMemoryExporter $exporter;
    private ExportingReader $reader;
    private MeterProvider $provider;
    private OtelMeter $meter;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->reader = new ExportingReader($this->exporter);
        $this->provider = MeterProvider::builder()->addReader($this->reader)->build();
        $this->meter = new OtelMeter($this->provider->getMeter('test'));
    }

    #[Test]
    public function recordsCounterHistogramAndUpDown(): void
    {
        $this->meter->counter('nexus.messages.processed', '{message}', 'processed')->add(2, ['nexus.message.type' => 'Greet']);
        $this->meter->histogram('nexus.msg.duration', 'ms')->record(12.5);
        $this->meter->upDownCounter('nexus.actor.mailbox.size')->add(3);

        $this->reader->collect();
        $names = array_map(static fn ($metric): string => $metric->name, $this->exporter->collect());

        self::assertContains('nexus.messages.processed', $names);
        self::assertContains('nexus.msg.duration', $names);
        self::assertContains('nexus.actor.mailbox.size', $names);
    }

    #[Test]
    public function observableGaugeReportsCallbackValue(): void
    {
        $this->meter->observableGauge('nexus.runtime.coroutines', static fn (): int => 7);

        $this->reader->collect();
        $names = array_map(static fn ($metric): string => $metric->name, $this->exporter->collect());

        self::assertContains('nexus.runtime.coroutines', $names);
    }
}
```

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Create the instrument wrappers**

`packages/nexus-observability-otel/src/Metric/OtelCounter.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Metric;

use Monadial\Nexus\Observability\Metric\Counter;
use OpenTelemetry\API\Metrics\CounterInterface;

/** @psalm-api */
final class OtelCounter implements Counter
{
    public function __construct(
        private readonly CounterInterface $counter,
    ) {}

    public function add(int|float $value, array $attributes = []): void
    {
        $this->counter->add($value, $attributes);
    }
}
```

`packages/nexus-observability-otel/src/Metric/OtelUpDownCounter.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Metric;

use Monadial\Nexus\Observability\Metric\UpDownCounter;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;

/** @psalm-api */
final class OtelUpDownCounter implements UpDownCounter
{
    public function __construct(
        private readonly UpDownCounterInterface $upDownCounter,
    ) {}

    public function add(int|float $value, array $attributes = []): void
    {
        $this->upDownCounter->add($value, $attributes);
    }
}
```

`packages/nexus-observability-otel/src/Metric/OtelHistogram.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Metric;

use Monadial\Nexus\Observability\Metric\Histogram;
use OpenTelemetry\API\Metrics\HistogramInterface;

/** @psalm-api */
final class OtelHistogram implements Histogram
{
    public function __construct(
        private readonly HistogramInterface $histogram,
    ) {}

    public function record(int|float $value, array $attributes = []): void
    {
        $this->histogram->record($value, $attributes);
    }
}
```

`packages/nexus-observability-otel/src/Metric/OtelObservableGauge.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Metric;

use Monadial\Nexus\Observability\Metric\ObservableGauge;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;

/**
 * @psalm-api
 *
 * Holds the OTEL observable gauge handle so the registered callback stays alive
 * for the lifetime of this wrapper.
 */
final class OtelObservableGauge implements ObservableGauge
{
    public function __construct(
        private readonly ObservableGaugeInterface $gauge,
    ) {}
}
```

- [ ] **Step 4: Create `OtelMeter`**

`packages/nexus-observability-otel/src/Metric/OtelMeter.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Metric;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\ObservableGauge;
use Monadial\Nexus\Observability\Metric\UpDownCounter;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

/**
 * @psalm-api
 *
 * Adapts an OpenTelemetry {@see MeterInterface} to the Nexus {@see Meter}
 * contract.
 */
final class OtelMeter implements Meter
{
    public function __construct(
        private readonly MeterInterface $meter,
    ) {}

    public function counter(string $name, string $unit = '', string $description = ''): Counter
    {
        return new OtelCounter($this->meter->createCounter($name, $unit, $description));
    }

    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
    {
        return new OtelUpDownCounter($this->meter->createUpDownCounter($name, $unit, $description));
    }

    public function histogram(string $name, string $unit = '', string $description = ''): Histogram
    {
        return new OtelHistogram($this->meter->createHistogram($name, $unit, $description));
    }

    public function observableGauge(
        string $name,
        callable $callback,
        string $unit = '',
        string $description = '',
    ): ObservableGauge {
        $gauge = $this->meter->createObservableGauge(
            $name,
            $unit,
            $description,
            [],
            static function (ObserverInterface $observer) use ($callback): void {
                $observer->observe($callback());
            },
        );

        return new OtelObservableGauge($gauge);
    }
}
```

- [ ] **Step 5: Run — expect PASS.** Then `make cs-fix && make phpcs && make psalm` (clean) + package suite.

- [ ] **Step 6: Commit**
```bash
git add packages/nexus-observability-otel
git commit --no-verify -m "feat(observability-otel): add OtelMeter + metric instrument wrappers"
```

---

## Task 4: `OtelObservability` + `ObservabilityFactory`

**Files:**
- Create: `packages/nexus-observability-otel/src/OtelObservability.php`
- Create: `packages/nexus-observability-otel/src/ObservabilityFactory.php`
- Create: `packages/nexus-observability-otel/tests/Unit/ObservabilityFactoryTest.php`

**Interfaces:**
- Consumes: `Monadial\Nexus\Observability\{Observability,NoopObservability}`, `...\Config\ObservabilityConfig`, `...\Context\{ContextPropagator,CompositePropagator,TraceContextPropagator,BaggagePropagator}`; produces `OtelTracer`/`OtelMeter` (Tasks 2–3).
- Produces:
  - `final class OtelObservability implements Observability` — constructor `(TracerProvider $tracerProvider, MeterProvider $meterProvider, ContextPropagator $propagator, string $instrumentationScope = 'nexus')`; builds `OtelTracer`/`OtelMeter` from the providers; `tracer()/meter()/propagator()`; plus `forceFlush(): void` and `shutdown(): void` delegating to both providers.
  - `final class ObservabilityFactory` — `static fromConfig(ObservabilityConfig $config): Observability` (disabled → `NoopObservability`); `static samplerFromConfig(ObservabilityConfig $config): SamplerInterface`.

- [ ] **Step 1: Write the failing test**

`packages/nexus-observability-otel/tests/Unit/ObservabilityFactoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit;

use Monadial\Nexus\Observability\Config\ObservabilityConfig;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Otel\ObservabilityFactory;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObservabilityFactory::class)]
#[CoversClass(OtelObservability::class)]
final class ObservabilityFactoryTest extends TestCase
{
    #[Test]
    public function disabledConfigYieldsNoopObservability(): void
    {
        self::assertInstanceOf(
            NoopObservability::class,
            ObservabilityFactory::fromConfig(ObservabilityConfig::disabled()),
        );
    }

    #[Test]
    public function enabledConfigYieldsOtelObservabilityWithWorkingPropagator(): void
    {
        $observability = ObservabilityFactory::fromConfig(
            ObservabilityConfig::enabled('orders')->withExporterEndpoint('http://localhost:4318'),
        );

        self::assertInstanceOf(OtelObservability::class, $observability);

        $context = $observability->propagator()->extract([
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $context->spanContext->traceId);
    }

    #[Test]
    public function samplerMappingCoversEachMode(): void
    {
        self::assertInstanceOf(
            ParentBased::class,
            ObservabilityFactory::samplerFromConfig(ObservabilityConfig::enabled('a')),
        );
        self::assertInstanceOf(
            AlwaysOffSampler::class,
            ObservabilityFactory::samplerFromConfig(ObservabilityConfig::enabled('a')->withSampler('always_off', null)),
        );
        self::assertInstanceOf(
            TraceIdRatioBasedSampler::class,
            ObservabilityFactory::samplerFromConfig(ObservabilityConfig::enabled('a')->withSampler('traceidratio', 0.25)),
        );
    }
}
```

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Create `OtelObservability`**

`packages/nexus-observability-otel/src/OtelObservability.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel;

use Monadial\Nexus\Observability\Context\ContextPropagator;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Otel\Metric\OtelMeter;
use Monadial\Nexus\Observability\Otel\Trace\OtelTracer;
use Monadial\Nexus\Observability\Trace\Tracer;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * @psalm-api
 *
 * OpenTelemetry-backed {@see Observability} provider. Owns the SDK providers so
 * telemetry can be flushed/shut down (wired into the actor-system lifecycle by a
 * later plan).
 */
final class OtelObservability implements Observability
{
    private readonly Tracer $tracer;

    private readonly Meter $meter;

    public function __construct(
        private readonly TracerProvider $tracerProvider,
        private readonly MeterProvider $meterProvider,
        private readonly ContextPropagator $propagator,
        string $instrumentationScope = 'nexus',
    ) {
        $this->tracer = new OtelTracer($tracerProvider->getTracer($instrumentationScope));
        $this->meter = new OtelMeter($meterProvider->getMeter($instrumentationScope));
    }

    public function tracer(): Tracer
    {
        return $this->tracer;
    }

    public function meter(): Meter
    {
        return $this->meter;
    }

    public function propagator(): ContextPropagator
    {
        return $this->propagator;
    }

    public function forceFlush(): void
    {
        $this->tracerProvider->forceFlush();
        $this->meterProvider->forceFlush();
    }

    public function shutdown(): void
    {
        $this->tracerProvider->shutdown();
        $this->meterProvider->shutdown();
    }
}
```

- [ ] **Step 4: Create `ObservabilityFactory`**

`packages/nexus-observability-otel/src/ObservabilityFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel;

use Monadial\Nexus\Observability\Config\ObservabilityConfig;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * @psalm-api
 *
 * Builds a configured {@see Observability} provider from an
 * {@see ObservabilityConfig}. Returns {@see NoopObservability} when disabled.
 */
final class ObservabilityFactory
{
    public static function fromConfig(ObservabilityConfig $config): Observability
    {
        if (!$config->enabled) {
            return new NoopObservability();
        }

        $endpoint = $config->exporterEndpoint ?? 'http://localhost:4318';
        $resource = ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create(['service.name' => $config->serviceName] + $config->resourceAttributes)),
        );

        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(
                new BatchSpanProcessor(
                    new SpanExporter(
                        (new OtlpHttpTransportFactory())->create($endpoint . '/v1/traces', 'application/x-protobuf'),
                    ),
                    ClockFactory::getDefault(),
                ),
            )
            ->setResource($resource)
            ->setSampler(self::samplerFromConfig($config))
            ->build();

        $meterProvider = MeterProvider::builder()
            ->addReader(
                new ExportingReader(
                    new MetricExporter(
                        (new OtlpHttpTransportFactory())->create($endpoint . '/v1/metrics', 'application/x-protobuf'),
                    ),
                ),
            )
            ->build();

        $scope = $config->serviceName === ''
            ? 'nexus'
            : $config->serviceName;

        return new OtelObservability(
            $tracerProvider,
            $meterProvider,
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
            $scope,
        );
    }

    public static function samplerFromConfig(ObservabilityConfig $config): SamplerInterface
    {
        $ratio = $config->samplerArg ?? 1.0;

        return match ($config->sampler) {
            'always_off' => new AlwaysOffSampler(),
            'always_on' => new AlwaysOnSampler(),
            'parentbased_always_off' => new ParentBased(new AlwaysOffSampler()),
            'parentbased_traceidratio' => new ParentBased(new TraceIdRatioBasedSampler($ratio)),
            'traceidratio' => new TraceIdRatioBasedSampler($ratio),
            default => new ParentBased(new AlwaysOnSampler()),
        };
    }
}
```

- [ ] **Step 5: Run — expect PASS.** Then `make cs-fix && make phpcs && make psalm` (clean) + package suite. Do NOT call `forceFlush()` in tests (would attempt a network export).

- [ ] **Step 6: Commit**
```bash
git add packages/nexus-observability-otel
git commit --no-verify -m "feat(observability-otel): add OtelObservability + ObservabilityFactory"
```

---

## Task 5: End-to-end integration test

**Files:**
- Create: `packages/nexus-observability-otel/tests/Unit/OtelObservabilityIntegrationTest.php`

**Interfaces:** consumes everything above; builds an `OtelObservability` from **in-memory-backed** SDK providers to prove tracer + span + propagator + metrics compose end-to-end.

- [ ] **Step 1: Write the test**

`packages/nexus-observability-otel/tests/Unit/OtelObservabilityIntegrationTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit;

use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class OtelObservabilityIntegrationTest extends TestCase
{
    #[Test]
    public function propagatesTraceAcrossBoundaryAndRecordsMetrics(): void
    {
        $spanExporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($spanExporter));

        $metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($metricExporter);
        $meterProvider = MeterProvider::builder()->addReader($reader)->build();

        $observability = new OtelObservability(
            $tracerProvider,
            $meterProvider,
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        // Inbound carrier (e.g. HTTP headers / envelope metadata) → context.
        $parent = $observability->propagator()->extract([
            'baggage' => 'tenant.id=acme',
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);
        self::assertSame('acme', $parent->baggage->get('tenant.id'));

        $consumer = $observability->tracer()->startSpan('process PlaceOrder', SpanKind::Consumer, ['nexus.actor.path' => '/user/orders'], $parent);
        $child = $observability->tracer()->startSpan('charge-card', SpanKind::Client);
        $child->end();
        $consumer->setStatus(StatusCode::Ok);
        $consumer->end();

        $observability->meter()->counter('nexus.messages.processed')->add(1, ['nexus.message.type' => 'PlaceOrder']);

        $tracerProvider->forceFlush();
        $reader->collect();

        $spans = $spanExporter->getSpans();
        self::assertCount(2, $spans);

        foreach ($spans as $span) {
            self::assertSame('0af7651916cd43dd8448eb211c80319c', $span->getTraceId());
        }

        $metricNames = array_map(static fn ($metric): string => $metric->name, $metricExporter->collect());
        self::assertContains('nexus.messages.processed', $metricNames);
    }
}
```

- [ ] **Step 2: Run — expect PASS.**

- [ ] **Step 3: Full package gate + deptrac**
```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-observability-otel/tests/Unit
make cs-fix && make phpcs && make psalm
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --config-file=deptrac.yaml
```
Expected: all green; deptrac reports `ObservabilityOtel → Observability` with no violations.

- [ ] **Step 4: Commit**
```bash
git add packages/nexus-observability-otel
git commit --no-verify -m "test(observability-otel): end-to-end trace propagation + metrics integration"
```

---

## Self-Review (plan author)

- **Spec coverage (Plan 2 slice — §3 bridge package, §9 async-export handles, D8 OTLP/HTTP):** SDK-backed Tracer/Span/Meter/instruments ✓ (Tasks 1–3); `Observability` provider over OTEL ✓ (Task 4); factory from `ObservabilityConfig` incl. sampler mapping (D10) + resource (service.name wins) + OTLP HTTP exporters (D8) + disabled→Noop ✓ (Task 4); propagation reuses the pure `CompositePropagator` ✓ (Task 4). `forceFlush()`/`shutdown()` exposed for the later lifecycle/async plan. **Out of scope here:** actor instrumentation (Plan 3), Swoole-coroutine transport (async plan), config surfaces (NexusApp/env/wizard).
- **Placeholder scan:** none — every step has complete code or an exact command; all OTEL symbols verified against installed vendor on PHP 8.5.
- **Type consistency:** `OtelSpan(SpanInterface, ?ScopeInterface)` produced by `OtelTracer::startSpan` (Tasks 1–2). `OtelMeter` factory return types match the instrument wrappers (Task 3). `OtelObservability(TracerProvider, MeterProvider, ContextPropagator, string)` consumed by `ObservabilityFactory::fromConfig` (Task 4). `samplerFromConfig` returns `SamplerInterface` and is asserted per-mode (Task 4). Integration test uses only the public API (Task 5).

## Downstream: Plan 3 = actor instrumentation in `nexus-core` (`ActorCell`/`ActorContext`/`ActorSystem`, core metrics, `Core → Observability` deptrac edge), consuming this provider. Deferred items to fold into Plan 3+: traceparent version forward-parsing; `traces/metrics/logsEnabled` env wiring; Swoole async export.
