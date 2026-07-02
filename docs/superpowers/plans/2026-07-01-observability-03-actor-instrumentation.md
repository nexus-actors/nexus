# Observability — Plan 3: Actor Instrumentation (in `nexus-core`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Instrument the actor runtime so every user message produces a Consumer span (parented from the incoming envelope's trace context), every `tell`/`ask` injects the current trace context into the outgoing envelope, and core actor metrics are emitted — all driven by the injected `Observability` provider, defaulting to no-op (zero overhead when disabled).

**Architecture:** Thread one `Observability` instance from `ActorSystem` down through every `ActorCell` and the `LocalActorRef`s they create. On send, `LocalActorRef` injects the *ambient current context* (the sender's active span, read at send-time) into `Envelope::metadata` via the propagator. On receive, `ActorCell::processMessage` extracts the parent context from the envelope metadata and wraps user-message handling in a Consumer span, recording exceptions and processing metrics. Because the OTEL bridge activates started spans, nested sends within a handler carry the right parent — so the trace connects across actor boundaries through the single `Envelope::metadata` carrier. Adds a small `Observability::currentContext()` capability (needed to read the ambient span for injection).

**Tech Stack:** PHP 8.5.7, `nexus-observability` (+ `nexus-observability-otel` as a core dev-dep for the end-to-end test), PHPUnit 13, Psalm L1, PHPCS, Deptrac, Docker Compose.

## Global Constraints

- **Docker only:** prefix every command with `docker compose exec -T php`. `composer dump-autoload` after adding classes.
- **Commit policy:** GrumPHP hook is broken in this Docker+worktree — commit with `git commit --no-verify`. Before EVERY commit run the gate manually: `make cs-fix && make phpcs && make psalm` (all clean) + the relevant test suite. `make cs-fix` does NOT enforce `ReferenceUsedNamesOnly` — `make phpcs` does; never inline `\FQCN`. `Warning: JIT is incompatible...` is env noise.
- **NEVER** add `Co-Authored-By: Claude`.
- **No singletons (hard rule):** no `static instance()` / static self state; use DI / `new`.
- **Code style:** `declare(strict_types=1);`; classes `final` (except where the codebase already uses otherwise); `/** @psalm-api */` on public API; ordered/alphabetical imports; string-keyed array literals **alphabetical**; trailing commas in multiline; blank line before control structures; multi-line ternaries.
- **Backward compatibility:** all new params default to a no-op so existing callers/tests are unaffected. `ActorSystem::create` gains `?Observability $observability = null` (defaults to `new NoopObservability()`). No existing behavior changes when observability is not supplied.
- **Deptrac:** add `Core → Observability` to the `Core` ruleset (currently `Core: [Runtime]`). No new reverse edges.
- **PII/attributes (D5, D11):** span attributes are metadata only (message class short-name, actor path, mailbox depth) — never payload. Metric dimensions are low-cardinality (`nexus.message.type` = message class short-name) — never per-instance actor path.
- **Tests:** unit tests use a dependency-free `RecordingObservability` test double (Task 4); one Fiber integration test uses the real OTEL bridge with the SDK in-memory exporter.

## Key seams (verified in the current code)

- `ActorSystem::create(string $name, Runtime $runtime, ?ClockInterface $clock = null, ?LoggerInterface $logger = null, ?EventDispatcherInterface $eventDispatcher = null): self` (L113); private ctor L84 stores `clock/logger/eventDispatcher`; `createActorCell()` L341 builds `new ActorCell(behavior, path, mailbox, runtime, null, supervision, clock, logger, deadLetters)`.
- `ActorCell` ctor L90 params: `(Behavior $behavior, ActorPath $actorPath, Mailbox $mailbox, Runtime $runtime, ?ActorRef $parentRef, SupervisionStrategy $supervision, ClockInterface $clock, LoggerInterface $logger, DeadLetterRef $deadLetters)`. Self-ref built at L104. Child cells built via `new self(...)` at L253 (`spawn()`). Sender-ref built at L388.
- `ActorCell::processMessage()` L148 — user-message branch at the `else` (L162-165) calls `resetReceiveTimer()` + `handleUserMessage()`. `handleUserMessage()` L593 and `handleStatefulMessage()` L622 each have 3 catch blocks that SWALLOW exceptions (log/supervise) — record exceptions on the active span there.
- `Mailbox::count(): int` exists (mailbox depth). `LocalActorRef` (final readonly) builds `Envelope::of(...)` with no metadata in `tell()` (L45) and `ask()` (L75); `Envelope::withMetadata(array)` and `Envelope::of(msg, sender, target)` exist.
- Only two `new LocalActorRef(...)` sites, both in `ActorCell` (L104 self, L388 sender).

---

## Task 1: Add `Observability::currentContext()` to `nexus-observability`

**Files:**
- Modify: `packages/nexus-observability/src/Observability.php`
- Modify: `packages/nexus-observability/src/NoopObservability.php`
- Modify: `packages/nexus-observability/tests/Unit/NoopObservabilityTest.php`

**Interfaces:**
- Produces: `Observability::currentContext(): Context` — returns the ambient active context (for injecting into outgoing carriers). No-op returns `Context::root()`.

- [ ] **Step 1: Extend the failing test** — add to `NoopObservabilityTest`:
```php
    #[Test]
    public function currentContextIsRoot(): void
    {
        self::assertFalse((new NoopObservability())->currentContext()->spanContext->isValid());
    }
```
Add `use Monadial\Nexus\Observability\Context\Context;` if not already imported (it is). Run — expect FAIL (`currentContext` not defined).
Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/NoopObservabilityTest.php`

- [ ] **Step 2: Add to the `Observability` interface** — in `packages/nexus-observability/src/Observability.php`, add (after `propagator()`), importing `Monadial\Nexus\Observability\Context\Context` alphabetically:
```php
    /**
     * Returns the ambient active context — the span currently in scope — for
     * injection into outgoing carriers (message metadata / HTTP headers).
     */
    public function currentContext(): Context;
```

- [ ] **Step 3: Implement in `NoopObservability`** — add:
```php
    public function currentContext(): Context
    {
        return Context::root();
    }
```
Add `use Monadial\Nexus\Observability\Context\Context;` alphabetically among imports.

- [ ] **Step 4: Run — expect PASS.** Then `make cs-fix && make phpcs && make psalm` (clean). Note: this changes the `Observability` interface, so `OtelObservability` (Plan 2) will now be missing the method — Plan 3 Task 2 fixes it; run only the `nexus-observability` suite here: `docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit`.

- [ ] **Step 5: Commit**
```bash
git add packages/nexus-observability
git commit --no-verify -m "feat(observability): add Observability::currentContext() for send-side injection"
```

---

## Task 2: Implement `currentContext()` in the OTEL bridge

**Files:**
- Modify: `packages/nexus-observability-otel/src/OtelObservability.php`
- Modify: `packages/nexus-observability-otel/tests/Unit/OtelObservabilityIntegrationTest.php` (add a currentContext assertion)

**Interfaces:** consumes `OpenTelemetry\API\Trace\Span` (`::getCurrent()` returns the active span from the current OTEL context).

- [ ] **Step 1: Add a failing assertion** — in `OtelObservabilityIntegrationTest`, after starting the consumer span (which the bridge activates), assert the bridge reports it as current:
```php
        $current = $observability->currentContext();
        self::assertTrue($current->spanContext->isValid());
        self::assertSame($consumer->context()->spanId, $current->spanContext->spanId);
```
(Place these lines after the `$consumer = ...startSpan(...)` line and before `$consumer->end()`. `$consumer->context()->spanId` is the active span's id.) Run — expect FAIL (`currentContext` not defined on `OtelObservability`).

- [ ] **Step 2: Implement `currentContext()`** — in `OtelObservability.php`, add the method and imports:
```php
    public function currentContext(): Context
    {
        $spanContext = OtelApiSpan::getCurrent()->getContext();

        if (!$spanContext->isValid()) {
            return Context::root();
        }

        $traceState = $spanContext->getTraceState();

        return Context::fromSpanContext(new SpanContext(
            traceId: $spanContext->getTraceId(),
            spanId: $spanContext->getSpanId(),
            traceFlags: $spanContext->getTraceFlags(),
            remote: $spanContext->isRemote(),
            traceState: $traceState !== null
                ? (string) $traceState
                : '',
        ));
    }
```
Add imports (alphabetical): `use Monadial\Nexus\Observability\Context\Context;`, `use Monadial\Nexus\Observability\Trace\SpanContext;`, `use OpenTelemetry\API\Trace\Span as OtelApiSpan;`. Add `#[Override]` (the codebase convention) — `use Override;`.

- [ ] **Step 3: Run — expect PASS.** Then `make cs-fix && make phpcs && make psalm` (clean) + `docker compose exec -T php vendor/bin/phpunit packages/nexus-observability-otel/tests/Unit`.
> If Psalm reports `OpenTelemetry\API\Trace\Span::getCurrent()` unknown, fall back to `OtelApiSpan::fromContext(\OpenTelemetry\Context\Context::getCurrent())->getContext()` (import `OpenTelemetry\Context\Context as OtelContext` and use `OtelApiSpan::fromContext(OtelContext::getCurrent())`). Both are valid OTEL API; use whichever Psalm accepts.

- [ ] **Step 4: Commit**
```bash
git add packages/nexus-observability-otel
git commit --no-verify -m "feat(observability-otel): implement currentContext() mapping active OTEL span"
```

---

## Task 3: Thread `Observability` through core wiring (no spans yet)

Pure plumbing: give `ActorSystem`, `ActorCell`, and `LocalActorRef` an `Observability`, expose `tracer()`/`meter()`/`currentSpan()` on `ActorContext`, add the composer dep + deptrac edge. Default `NoopObservability` ⇒ existing behavior/tests unchanged.

**Files:**
- Modify: `packages/nexus-core/composer.json` (add `nexus-actors/observability`)
- Modify: `deptrac.yaml` (`Core` → `Observability`)
- Modify: `packages/nexus-core/src/Actor/ActorSystem.php`
- Modify: `packages/nexus-core/src/Actor/ActorContext.php`
- Modify: `packages/nexus-core/src/Actor/ActorCell.php`
- Modify: `packages/nexus-core/src/Actor/LocalActorRef.php`

**Interfaces:**
- Produces: `ActorSystem::create(..., ?Observability $observability = null)`; `ActorContext::tracer(): Tracer`, `meter(): Meter`, `currentSpan(): Span`; `LocalActorRef` ctor gains a trailing `Observability $observability`; `ActorCell` ctor gains a trailing `Observability $observability`.

- [ ] **Step 1: composer + deptrac**

`packages/nexus-core/composer.json` — add to `require` (alphabetical, after `nexus-actors/core`'s other nexus dep):
```json
        "nexus-actors/observability": "dev-main",
```
`deptrac.yaml` — change the `Core` ruleset to:
```yaml
    Core:
      - Observability
      - Runtime
```
Run `docker compose exec -T php composer dump-autoload`.

- [ ] **Step 2: `LocalActorRef` — accept `Observability` (no injection yet)**

In `packages/nexus-core/src/Actor/LocalActorRef.php`, add a constructor param (trailing) and import:
```php
use Monadial\Nexus\Observability\Observability;
```
```php
    public function __construct(
        private ActorPath $path,
        private Mailbox $mailbox,
        private Closure $aliveChecker,
        private Runtime $runtime,
        private Observability $observability,
    ) {}
```
(Leave `tell()`/`ask()` unchanged in this task — injection is Task 5.)

- [ ] **Step 3: `ActorContext` — declare the three accessors**

In `packages/nexus-core/src/Actor/ActorContext.php`, add imports (alphabetical) `use Monadial\Nexus\Observability\Metric\Meter;`, `use Monadial\Nexus\Observability\Trace\Span;`, `use Monadial\Nexus\Observability\Trace\Tracer;` and append to the interface:
```php
    /**
     * Tracer for creating custom spans from within a handler; child of the
     * current message span. No-op when observability is disabled.
     */
    public function tracer(): Tracer;

    /**
     * Meter for recording custom metrics from within a handler. No-op when
     * observability is disabled.
     */
    public function meter(): Meter;

    /**
     * The span for the message currently being handled (a no-op span outside a
     * user-message handler). Use to add attributes/events to the active span.
     */
    public function currentSpan(): Span;
```

- [ ] **Step 4: `ActorCell` — store `Observability`, wire refs/children, implement accessors**

In `packages/nexus-core/src/Actor/ActorCell.php`:
- Add imports (alphabetical): `use Monadial\Nexus\Observability\Metric\Meter;`, `use Monadial\Nexus\Observability\Observability;`, `use Monadial\Nexus\Observability\Trace\NoopSpan;`, `use Monadial\Nexus\Observability\Trace\Span;`, `use Monadial\Nexus\Observability\Trace\Tracer;`.
- Add a field: `private ?Span $currentSpan = null;` (near the other private fields).
- Add the ctor param (trailing): `private readonly Observability $observability,`.
- Update the self-ref (L104) to pass `$this->observability`:
```php
        $ref = new LocalActorRef($this->actorPath, $this->mailbox, fn(): bool => $this->isAlive(), $this->runtime, $this->observability);
```
- Update the child-cell construction in `spawn()` (L253) — add `$this->observability,` as the trailing argument to `new self(...)`.
- Update the sender-ref (L388) `new LocalActorRef(...)` — add `$this->observability,` as the trailing argument.
- Implement the accessors (place near `log()`):
```php
    #[Override]
    public function tracer(): Tracer
    {
        return $this->observability->tracer();
    }

    #[Override]
    public function meter(): Meter
    {
        return $this->observability->meter();
    }

    #[Override]
    public function currentSpan(): Span
    {
        return $this->currentSpan ?? new NoopSpan();
    }
```

- [ ] **Step 5: `ActorSystem` — accept and thread `Observability`**

In `packages/nexus-core/src/Actor/ActorSystem.php`:
- Add import `use Monadial\Nexus\Observability\NoopObservability;` and `use Monadial\Nexus\Observability\Observability;` (alphabetical).
- Add ctor param (trailing) `private readonly Observability $observability,`.
- `create()` — add `?Observability $observability = null,` as the last param; pass `$observability ?? new NoopObservability()` as the trailing constructor argument in the `return new self(...)`.
- `createActorCell()` — add `$this->observability,` as the trailing argument to `new ActorCell(...)`.

- [ ] **Step 6: Verify no behavior change**

Run the core + fiber unit suites and the full unit testsuite; everything must still pass with the default no-op:
```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-core/tests/Unit
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
make cs-fix && make phpcs && make psalm
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --config-file=deptrac.yaml
```
Expected: all green; deptrac shows `Core → Observability` allowed, no violations.

- [ ] **Step 7: Commit**
```bash
git add packages/nexus-core composer.json deptrac.yaml
git commit --no-verify -m "feat(core): thread Observability through ActorSystem/ActorCell/LocalActorRef (no-op default)"
```

---

## Task 4: `RecordingObservability` test double (in core test support)

A dependency-free `Observability` that records started spans (name, kind, attributes, parent, status, exceptions, ended) and metric operations, plus a controllable `currentContext()` — so core unit tests can assert instrumentation precisely without the OTEL SDK.

**Files:**
- Create: `packages/nexus-core/tests/Support/Observability/RecordingObservability.php`
- Create: `packages/nexus-core/tests/Support/Observability/RecordingTracer.php`
- Create: `packages/nexus-core/tests/Support/Observability/RecordedSpan.php`
- Create: `packages/nexus-core/tests/Support/Observability/RecordingMeter.php`
- Create: `packages/nexus-core/tests/Support/Observability/RecordedMetric.php`
- Create: `packages/nexus-core/tests/Unit/Observability/RecordingObservabilityTest.php`

**Interfaces:**
- Produces: `RecordingObservability` implementing `Observability`; `->spans(): list<RecordedSpan>`, `->metrics(): list<RecordedMetric>`. `currentContext()` returns the context of the most recently started, not-yet-ended span (so injection can be asserted), else `Context::root()`. Uses the real pure `CompositePropagator` so extract/inject behave like production.

- [ ] **Step 1: `RecordedSpan`**
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support\Observability;

use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanContext;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Throwable;

final class RecordedSpan implements Span
{
    public bool $ended = false;

    public StatusCode $status = StatusCode::Unset;

    public ?Throwable $exception = null;

    /** @var array<string, scalar> */
    public array $attributes;

    /**
     * @param array<string, scalar> $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly SpanKind $kind,
        array $attributes,
        private readonly SpanContext $context,
    ) {
        $this->attributes = $attributes;
    }

    public function setAttribute(string $key, string|int|float|bool $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function setAttributes(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
    }

    public function addEvent(string $name, array $attributes = []): void {}

    public function recordException(Throwable $exception): void
    {
        $this->exception = $exception;
    }

    public function setStatus(StatusCode $code, ?string $description = null): void
    {
        $this->status = $code;
    }

    public function end(): void
    {
        $this->ended = true;
    }

    public function context(): SpanContext
    {
        return $this->context;
    }
}
```

- [ ] **Step 2: `RecordingTracer`**
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support\Observability;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanContext;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\Tracer;

use function sprintf;

final class RecordingTracer implements Tracer
{
    /** @var list<RecordedSpan> */
    public array $spans = [];

    /** @var list<RecordedSpan> */
    public array $active = [];

    private int $counter = 0;

    public function startSpan(
        string $name,
        SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
        ?Context $parent = null,
    ): Span {
        ++$this->counter;
        $traceId = $parent !== null && $parent->spanContext->isValid()
            ? $parent->spanContext->traceId
            : sprintf('%032x', $this->counter);
        $spanId = sprintf('%016x', $this->counter);

        $span = new RecordedSpan(
            $name,
            $kind,
            $attributes,
            new SpanContext($traceId, $spanId, 1, false),
        );
        $this->spans[] = $span;
        $this->active[] = $span;

        return $span;
    }

    public function currentSpanContext(): SpanContext
    {
        for ($i = count($this->active) - 1; $i >= 0; --$i) {
            if (!$this->active[$i]->ended) {
                return $this->active[$i]->context();
            }
        }

        return SpanContext::invalid();
    }
}
```

- [ ] **Step 3: `RecordedMetric` + `RecordingMeter`**

`RecordedMetric.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support\Observability;

final class RecordedMetric
{
    /**
     * @param array<string, scalar> $attributes
     */
    public function __construct(
        public readonly string $instrument,
        public readonly string $name,
        public readonly int|float $value,
        public readonly array $attributes,
    ) {}
}
```

`RecordingMeter.php` (implements `Meter`; each instrument records into a shared list):
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support\Observability;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\ObservableGauge;
use Monadial\Nexus\Observability\Metric\NoopObservableGauge;
use Monadial\Nexus\Observability\Metric\UpDownCounter;

final class RecordingMeter implements Meter
{
    /** @var list<RecordedMetric> */
    public array $metrics = [];

    public function counter(string $name, string $unit = '', string $description = ''): Counter
    {
        return new class($name, $this->metrics) implements Counter {
            /** @param list<RecordedMetric> $sink */
            public function __construct(private readonly string $name, private array &$sink) {}

            public function add(int|float $value, array $attributes = []): void
            {
                $this->sink[] = new RecordedMetric('counter', $this->name, $value, $attributes);
            }
        };
    }

    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
    {
        return new class($name, $this->metrics) implements UpDownCounter {
            /** @param list<RecordedMetric> $sink */
            public function __construct(private readonly string $name, private array &$sink) {}

            public function add(int|float $value, array $attributes = []): void
            {
                $this->sink[] = new RecordedMetric('updown', $this->name, $value, $attributes);
            }
        };
    }

    public function histogram(string $name, string $unit = '', string $description = ''): Histogram
    {
        return new class($name, $this->metrics) implements Histogram {
            /** @param list<RecordedMetric> $sink */
            public function __construct(private readonly string $name, private array &$sink) {}

            public function record(int|float $value, array $attributes = []): void
            {
                $this->sink[] = new RecordedMetric('histogram', $this->name, $value, $attributes);
            }
        };
    }

    public function observableGauge(
        string $name,
        callable $callback,
        string $unit = '',
        string $description = '',
    ): ObservableGauge {
        return new NoopObservableGauge();
    }
}
```
> Note: the anonymous instrument classes capture `$this->metrics` by reference so recordings land in the meter's list. Verify Psalm accepts the `array &$sink` promoted-by-reference pattern; if it objects, make each instrument a named final class in the same directory taking the `RecordingMeter` and appending via a `record(RecordedMetric)` method.

- [ ] **Step 4: `RecordingObservability`**
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support\Observability;

use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\ContextPropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Tracer;

final class RecordingObservability implements Observability
{
    private readonly RecordingTracer $recordingTracer;

    private readonly RecordingMeter $recordingMeter;

    private readonly ContextPropagator $propagator;

    public function __construct()
    {
        $this->recordingTracer = new RecordingTracer();
        $this->recordingMeter = new RecordingMeter();
        $this->propagator = new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]);
    }

    public function tracer(): Tracer
    {
        return $this->recordingTracer;
    }

    public function meter(): Meter
    {
        return $this->recordingMeter;
    }

    public function propagator(): ContextPropagator
    {
        return $this->propagator;
    }

    public function currentContext(): Context
    {
        $spanContext = $this->recordingTracer->currentSpanContext();

        return $spanContext->isValid()
            ? Context::fromSpanContext($spanContext)
            : Context::root();
    }

    /** @return list<RecordedSpan> */
    public function spans(): array
    {
        return $this->recordingTracer->spans;
    }

    /** @return list<RecordedMetric> */
    public function metrics(): array
    {
        return $this->recordingMeter->metrics;
    }
}
```

- [ ] **Step 5: A test proving the double records**

`packages/nexus-core/tests/Unit/Observability/RecordingObservabilityTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Observability;

use Monadial\Nexus\Core\Tests\Support\Observability\RecordingObservability;
use Monadial\Nexus\Observability\Trace\SpanKind;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RecordingObservabilityTest extends TestCase
{
    #[Test]
    public function recordsSpansMetricsAndReportsCurrentContext(): void
    {
        $observability = new RecordingObservability();

        $span = $observability->tracer()->startSpan('op', SpanKind::Consumer, ['k' => 'v']);
        self::assertTrue($observability->currentContext()->spanContext->isValid());

        $observability->meter()->counter('c')->add(2, ['t' => 'x']);
        $span->end();

        self::assertCount(1, $observability->spans());
        self::assertSame('op', $observability->spans()[0]->name);
        self::assertCount(1, $observability->metrics());
        self::assertSame(2, $observability->metrics()[0]->value);
        self::assertFalse($observability->currentContext()->spanContext->isValid());
    }
}
```

- [ ] **Step 6: Run — expect PASS.** `make cs-fix && make phpcs && make psalm` (clean). If `tests/Support` is not on the `unit` testsuite path, the test under `tests/Unit/Observability` still runs. Commit:
```bash
git add packages/nexus-core/tests
git commit --no-verify -m "test(core): add RecordingObservability test double"
```

---

## Task 5: Instrument sends (injection) and receives (Consumer span + metrics)

**Files:**
- Modify: `packages/nexus-core/src/Actor/LocalActorRef.php` (inject on tell/ask)
- Modify: `packages/nexus-core/src/Actor/ActorCell.php` (Consumer span + metrics + exception recording)
- Create: `packages/nexus-core/tests/Unit/Actor/ActorCellInstrumentationTest.php`

**Interfaces:** consumes `RecordingObservability` (Task 4), `SpanKind`, `StatusCode`.

- [ ] **Step 1: Write the failing instrumentation test**

`packages/nexus-core/tests/Unit/Actor/ActorCellInstrumentationTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\Observability\RecordingObservability;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ActorCellInstrumentationTest extends TestCase
{
    #[Test]
    public function userMessageProducesConsumerSpanAndMetrics(): void
    {
        $observability = new RecordingObservability();
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('test', $runtime, null, null, null, $observability);

        $behavior = Behavior::receive(static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same());
        $ref = $system->spawn(Props::fromBehavior($behavior), 'greeter');
        $ref->tell(new InstrumentationPing());

        $runtime->scheduleOnce(Duration::millis(200), static fn () => $system->shutdown(Duration::seconds(1)));
        $system->run();

        $consumerSpans = array_values(array_filter(
            $observability->spans(),
            static fn ($span): bool => $span->kind === SpanKind::Consumer,
        ));
        self::assertNotEmpty($consumerSpans);
        $span = $consumerSpans[0];
        self::assertStringContainsString('InstrumentationPing', $span->name);
        self::assertSame('nexus', $span->attributes['messaging.system']);
        self::assertSame('InstrumentationPing', $span->attributes['nexus.message.type']);
        self::assertTrue($span->ended);

        $processed = array_values(array_filter(
            $observability->metrics(),
            static fn ($metric): bool => $metric->name === 'nexus.actor.messages.processed',
        ));
        self::assertNotEmpty($processed);
    }
}

final readonly class InstrumentationPing {}
```
Run — expect FAIL (no consumer span recorded yet; `create` doesn't accept the 6th arg until Task 3 is in — it is).

- [ ] **Step 2: Inject context on send in `LocalActorRef`**

Replace `tell()`:
```php
    #[Override]
    public function tell(object $message): void
    {
        try {
            $_ = $this->mailbox->enqueue($this->envelopeFor($message, ActorPath::root()));
        } catch (MailboxClosedException) {
            // fire-and-forget: silently drop messages to closed mailboxes
        }
    }
```
In `ask()`, replace the envelope construction line with:
```php
        $envelope = $this->envelopeFor($message, $futureRefPath)->withSenderRef($futureRef);
```
Add a private helper:
```php
    private function envelopeFor(object $message, ActorPath $sender): Envelope
    {
        $carrier = [];
        $this->observability->propagator()->inject($this->observability->currentContext(), $carrier);
        $envelope = Envelope::of($message, $sender, $this->path);

        if ($carrier !== []) {
            $envelope = $envelope->withMetadata($carrier);
        }

        return $envelope;
    }
```

- [ ] **Step 3: Consumer span + metrics in `ActorCell::processMessage`**

Replace the user-message branch (the `else` at ~L162) so it wraps `handleUserMessage()`:
```php
            } else {
                $this->resetReceiveTimer();
                $this->traceUserMessage($envelope, $message);
            }
```
Add these private methods to `ActorCell` (import `DateTimeImmutable`, `SpanKind`, `StatusCode`, and `function get_class`, `function substr`, `function strrchr` as needed):
```php
    private function traceUserMessage(Envelope $envelope, object $message): void
    {
        $type = $this->messageType($message);
        $parent = $this->observability->propagator()->extract($envelope->metadata);
        $span = $this->observability->tracer()->startSpan(
            'process ' . $type,
            SpanKind::Consumer,
            [
                'messaging.operation' => 'process',
                'messaging.system' => 'nexus',
                'nexus.actor.path' => (string) $this->actorPath,
                'nexus.mailbox.depth' => $this->mailbox->count(),
                'nexus.message.type' => $type,
            ],
            $parent,
        );
        $this->currentSpan = $span;
        $start = $this->clock->now();

        try {
            $this->handleUserMessage($message);
        } finally {
            $this->recordProcessingMetrics($type, $start);
            $span->end();
            $this->currentSpan = null;
        }
    }

    private function recordProcessingMetrics(string $type, DateTimeImmutable $start): void
    {
        $meter = $this->observability->meter();
        $meter->counter('nexus.actor.messages.processed', '{message}', 'User messages processed by actors')
            ->add(1, ['nexus.message.type' => $type]);

        $durationMs = ((float) $this->clock->now()->format('U.u') - (float) $start->format('U.u')) * 1000.0;
        $meter->histogram('nexus.actor.message.processing.duration', 'ms', 'Actor message processing duration')
            ->record($durationMs, ['nexus.message.type' => $type]);
    }

    private function messageType(object $message): string
    {
        $class = $message::class;
        $pos = strrchr($class, '\\');

        return $pos === false
            ? $class
            : substr($pos, 1);
    }
```
Add `use DateTimeImmutable;`, `use Monadial\Nexus\Observability\Trace\SpanKind;`, `use Monadial\Nexus\Observability\Trace\StatusCode;` (StatusCode used in Step 4), and `use function strrchr; use function substr;` (alphabetical, after existing `use function assert;`).

- [ ] **Step 4: Record exceptions on the active span**

In `handleUserMessage()` AND `handleStatefulMessage()`, add to EACH of the three `catch` blocks (before/after the existing log call), so the active span reflects failures:
```php
            $this->currentSpan?->recordException($e);
            $this->currentSpan?->setStatus(StatusCode::Error, $e->getMessage());
```
(Place these two lines inside each `catch (...) { ... }` block, after the existing logger call.)

- [ ] **Step 5: Run the instrumentation test — expect PASS**
```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-core/tests/Unit/Actor/ActorCellInstrumentationTest.php
```

- [ ] **Step 6: Full gate + no-regression**
```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
make cs-fix && make phpcs && make psalm
```
Expected: all green. (Psalm note: the float subtraction of two `format('U.u')` strings cast to float is float-only; if Psalm flags `InvalidOperand`, add `@psalm-suppress InvalidOperand` on the `recordProcessingMetrics` docblock per the project convention in CLAUDE.md.)

- [ ] **Step 7: Commit**
```bash
git add packages/nexus-core
git commit --no-verify -m "feat(core): Consumer span + processing metrics per message; inject trace context on send"
```

---

## Task 6: Fiber end-to-end trace-propagation integration test

Prove the real thing: with the OTEL bridge (in-memory exporter), actor A `tell`s actor B, and B's Consumer span is a child of A's span in the same trace.

**Files:**
- Modify: `packages/nexus-core/composer.json` (add `nexus-actors/observability-otel` + `open-telemetry/sdk` to `require-dev`)
- Create: `tests/Integration/Fiber/Observability/ActorTracePropagationTest.php`

- [ ] **Step 1: Add dev deps** to `packages/nexus-core/composer.json` `require-dev` (alphabetical):
```json
        "nexus-actors/observability-otel": "dev-main",
        "open-telemetry/sdk": "^1.14",
```
Run `docker compose exec -T php composer dump-autoload`. (The integration testsuite autoloads via the root `autoload-dev`; these dev-deps document the requirement.)

- [ ] **Step 2: Write the integration test**

`tests/Integration/Fiber/Observability/ActorTracePropagationTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber\Observability;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
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
final class ActorTracePropagationTest extends TestCase
{
    #[Test]
    public function tracePropagatesFromSenderActorToReceiverActor(): void
    {
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($exporter));
        $meterProvider = MeterProvider::builder()
            ->addReader(new ExportingReader(new MetricInMemoryExporter()))
            ->build();
        $observability = new OtelObservability(
            $tracerProvider,
            $meterProvider,
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $runtime = new FiberRuntime();
        $system = ActorSystem::create('test', $runtime, null, null, null, $observability);

        // B echoes nothing; its Consumer span should parent under A's span.
        $b = $system->spawn(
            Props::fromBehavior(Behavior::receive(static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same())),
            'b',
        );

        // A, on receiving Go, tells B — the send happens inside A's active span.
        $a = $system->spawn(
            Props::fromBehavior(Behavior::receive(static function (ActorContext $ctx, object $msg) use ($b): Behavior {
                $b->tell(new TraceMsg());

                return Behavior::same();
            })),
            'a',
        );

        $a->tell(new TraceMsg());

        $runtime->scheduleOnce(Duration::millis(300), static fn () => $system->shutdown(Duration::seconds(1)));
        $system->run();
        $tracerProvider->forceFlush();

        $spans = $exporter->getSpans();
        self::assertGreaterThanOrEqual(2, count($spans));

        $byPath = [];

        foreach ($spans as $span) {
            $byPath[$span->getAttributes()->get('nexus.actor.path')] = $span;
        }

        self::assertArrayHasKey('/user/a', $byPath);
        self::assertArrayHasKey('/user/b', $byPath);
        // Same trace, and B is a child of A.
        self::assertSame($byPath['/user/a']->getTraceId(), $byPath['/user/b']->getTraceId());
        self::assertSame($byPath['/user/a']->getSpanId(), $byPath['/user/b']->getParentSpanId());
    }
}

final readonly class TraceMsg {}
```

- [ ] **Step 3: Run — expect PASS**
```bash
docker compose exec -T php vendor/bin/phpunit tests/Integration/Fiber/Observability/ActorTracePropagationTest.php
```
> If this suite isn't picked up by an existing testsuite, run it by path as above. If it needs registering, add `tests/Integration/Fiber/Observability` to the appropriate `<testsuite>` in `phpunit.xml` (mirror the existing Fiber integration testsuite entry).

- [ ] **Step 4: Full gate**
```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
make cs-fix && make phpcs && make psalm
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --config-file=deptrac.yaml
```
Expected: all green; deptrac clean.

- [ ] **Step 5: Commit**
```bash
git add packages/nexus-core composer.json phpunit.xml tests/Integration
git commit --no-verify -m "test(core): Fiber end-to-end actor→actor trace propagation via OTEL bridge"
```

---

## Self-Review (plan author)

- **Spec coverage (Plan 3 slice — §5 actors, §5.1 custom instrumentation, §6 propagation, §8 actor metrics, D5/D11/D12/D14):** Consumer span per user message with parent from `Envelope::metadata` ✓ (Task 5); metadata-only attributes ✓ (D5); low-cardinality metrics (`nexus.message.type`) ✓ (D11); `correlationId` untouched ✓ (D12); user messages only (system/signals get no span) ✓ (D14); context injected on `tell`/`ask` ✓ (Task 5); `ActorContext::tracer()/meter()/currentSpan()` ✓ (Task 3, §5.1); `currentContext()` added to abstraction+bridge ✓ (Tasks 1–2); `Core → Observability` deptrac edge ✓ (Task 3); end-to-end proof ✓ (Task 6). No-op default ⇒ zero overhead ✓.
- **Placeholder scan:** none — complete code or exact commands per step. Two guarded fallbacks are called out explicitly (`Span::getCurrent()` alt in Task 2; by-ref instrument alt in Task 4) with the exact replacement.
- **Type consistency:** `Observability::currentContext(): Context` consistent across interface (T1), Noop (T1), Otel (T2), Recording (T4). `LocalActorRef`/`ActorCell` ctors gain a trailing `Observability` used identically at all call sites (T3). `ActorContext::{tracer,meter,currentSpan}` declared (T3 interface) and implemented in `ActorCell` (T3) and honored by `RecordingObservability` (T4). Span/metric names used in Task 5 (`nexus.actor.messages.processed`, `nexus.actor.message.processing.duration`) are asserted in Tasks 5–6.

## Downstream: Plan 4 = HTTP (`nexus-observability-http`: server/client/WS middleware, RED metrics, HTTP→actor context bridge). Deferred items still pending: Swoole async export + shutdown force-flush; traceparent version forward-parsing; signal-toggle env wiring; per-signal OTLP endpoints/PSR-18 client hardening.
