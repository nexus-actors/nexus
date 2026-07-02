# Observability — Plan 1: Foundation (`nexus-observability`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create the vendor-neutral `nexus-observability` package — the pure interfaces, no-op implementations, W3C trace-context propagator, and `ObservabilityConfig` value object that every other Nexus package and the OTEL bridge build on.

**Architecture:** A zero-runtime-dependency foundational package (mirrors `nexus-runtime`). It defines a minimal subset of the OpenTelemetry data model (Tracer/Span, Meter/instruments, Context/propagator) as PHP interfaces plus shared no-op singletons, and one pure W3C `TraceContextPropagator`. No OpenTelemetry SDK dependency — the package is fully unit-testable on its own. The real SDK wiring is a later plan (`nexus-observability-otel`).

**Tech Stack:** PHP 8.5.7, PHPUnit 13, Psalm (level 1), PHPCS/PHP-CS-Fixer (PER-CS2.0 + Slevomat), Deptrac, Docker Compose (all commands run in the `php` container).

## Global Constraints

- **PHP floor:** `php: >=8.5.7` in every `composer.json`.
- **Packagist vendor:** `nexus-actors/<name>`. This package: `nexus-actors/observability`.
- **PSR-4 namespace:** `Monadial\Nexus\Observability\` → `src/`; tests `Monadial\Nexus\Observability\Tests\` → `tests/`.
- **Docker only:** never run `php`/`composer`/`vendor/bin/*` on the host. Prefix everything with `docker compose exec -T php`. No local PHP exists.
- **Commit messages:** NEVER add `Co-Authored-By: Claude`. GrumPHP (PHP-CS-Fixer, PHPCS, Psalm, PHPUnit) runs on every commit via Docker and must pass.
- **Code style:** `declare(strict_types=1);` in every file. All classes `final`; all value objects `final readonly`. Ordered imports (class, function, const — each alphabetical). Trailing commas in all multiline contexts. Array literals with string keys **sorted alphabetically**. Blank line required before `if`/`for`/`foreach`/`while`/`switch`/`try`. Ternaries multi-line.
- **Psalm:** level 1 strict. Annotate public API classes/enums with `/** @psalm-api */` (matches existing convention, e.g. `Supervision/Directive.php`). Attribute-value arrays are typed `array<string, scalar>`.
- **Deptrac:** the new `Observability` layer depends on **nothing**. `Core` gains an allowed edge to `Observability` in a *later* plan — not here.
- **Tests:** namespace `...\Tests\Unit`; `final class …Test extends TestCase`; `#[CoversClass(X::class)]` on the class, `#[Test]` on methods; assert via `self::assert*`.
- **No singletons (hard rule).** Never use the singleton pattern — no `static instance()`, no `private static ?self $instance`, no private constructor used to enforce a single instance. No-op / default collaborators are plain instantiable `final` classes wired via constructor injection, using PHP 8.1 `new`-in-initializer defaults (e.g. `public function __construct(private readonly Tracer $tracer = new NoopTracer())`). A composition root (`NoopObservability`) owns its collaborators; callers `new` it.

> **CORRECTION (post-review):** Some Task 3–5 code blocks below were originally drafted with a `static instance()` singleton on the no-op classes. That was rejected — the delivered code uses plain constructor-injected instances per the "No singletons" rule above (`NoopTracer`/`NoopMeter`/`NoopSpan`/instruments/`NoopContextPropagator`/`NoopObservability` are all `new`-able, no static state). Where a block shows `static instance()`, read it as a normal constructor + DI. Authoritative source is the committed code, not those blocks.

---

## File Structure

```
packages/nexus-observability/
  composer.json
  src/
    Observability.php                      # provider interface: tracer/meter/propagator
    NoopObservability.php                  # no-op provider (shared singletons)
    Trace/
      SpanKind.php                         # enum: Internal|Server|Client|Producer|Consumer
      StatusCode.php                       # enum: Unset|Ok|Error
      SpanContext.php                      # final readonly VO (trace id, span id, flags, remote, tracestate)
      Span.php                             # interface
      Tracer.php                           # interface
      NoopSpan.php                         # final, shared singleton
      NoopTracer.php                       # final, shared singleton
    Metric/
      Counter.php                          # interface
      UpDownCounter.php                    # interface
      Histogram.php                        # interface
      ObservableGauge.php                  # interface (marker; callback-driven)
      Meter.php                            # interface
      NoopCounter.php
      NoopUpDownCounter.php
      NoopHistogram.php
      NoopObservableGauge.php
      NoopMeter.php
    Context/
      Baggage.php                          # final readonly map<string,string> (get/with/all/isEmpty)
      Context.php                          # final readonly wrapper: SpanContext + Baggage (+ withers)
      ContextPropagator.php                # interface: inject/extract (composing) on array<string,string>
      TraceContextPropagator.php           # pure W3C traceparent/tracestate impl
      BaggagePropagator.php                # pure W3C baggage header impl
      CompositePropagator.php              # runs multiple propagators (trace + baggage)
      NoopContextPropagator.php            # no-op (extract → root)
    Config/
      ObservabilityConfig.php              # final readonly VO + disabled()/enabled()/fromEnv()/withers
  tests/
    Unit/
      Trace/SpanContextTest.php
      Trace/NoopTracerTest.php
      Metric/NoopMeterTest.php
      Context/BaggageTest.php
      Context/TraceContextPropagatorTest.php
      Context/BaggagePropagatorTest.php
      Context/CompositePropagatorTest.php
      NoopObservabilityTest.php
      Config/ObservabilityConfigTest.php
```

Shared wiring files modified by Task 1: root `composer.json` (psr-4 autoload + autoload-dev), `deptrac.yaml` (layer + ruleset), `phpunit.xml` (unit testsuite dir + coverage source).

---

## Task 1: Package scaffold + `SpanKind` / `StatusCode` / `SpanContext`

Creates the package, wires it into the monorepo, and adds the three simplest, dependency-free types so there is something to autoload and test.

**Files:**
- Create: `packages/nexus-observability/composer.json`
- Create: `packages/nexus-observability/src/Trace/SpanKind.php`
- Create: `packages/nexus-observability/src/Trace/StatusCode.php`
- Create: `packages/nexus-observability/src/Trace/SpanContext.php`
- Create: `packages/nexus-observability/tests/Unit/Trace/SpanContextTest.php`
- Modify: `composer.json` (root) — add psr-4 entries
- Modify: `deptrac.yaml` — add `Observability` layer + ruleset entry
- Modify: `phpunit.xml` — add unit testsuite dir + coverage source

**Interfaces:**
- Produces:
  - `enum SpanKind` cases `Internal|Server|Client|Producer|Consumer`
  - `enum StatusCode` cases `Unset|Ok|Error`
  - `final readonly class SpanContext` with public `string $traceId, string $spanId, int $traceFlags, bool $remote, string $traceState`; `static invalid(): self`; `isValid(): bool`

- [ ] **Step 1: Create the package `composer.json`**

`packages/nexus-observability/composer.json`:
```json
{
    "name": "nexus-actors/observability",
    "description": "Nexus observability contracts — vendor-neutral tracing, metrics, and context-propagation interfaces with no-op defaults.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5.7"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Register autoload in the root `composer.json`**

In root `composer.json`, add to `autoload.psr-4` (keep the block readable; ordering is not enforced):
```json
            "Monadial\\Nexus\\Observability\\": "packages/nexus-observability/src/",
```
and to `autoload-dev.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\Tests\\": "packages/nexus-observability/tests/",
```

- [ ] **Step 3: Add the Deptrac layer and ruleset**

In `deptrac.yaml`, add a new layer under `layers:` (next to `Runtime`):
```yaml
    - name: Observability
      collectors:
        - type: directory
          value: packages/nexus-observability/src/.*
```
and add an (empty-dependency) ruleset entry under `ruleset:`:
```yaml
    Observability: []
```

- [ ] **Step 4: Wire PHPUnit**

In `phpunit.xml`, add inside `<testsuite name="unit">`:
```xml
            <directory>packages/nexus-observability/tests/Unit</directory>
```
and inside `<source><include>`:
```xml
            <directory>packages/nexus-observability/src</directory>
```

- [ ] **Step 5: Refresh the autoloader**

Run: `docker compose exec -T php composer dump-autoload`
Expected: `Generated autoload files` with no errors.

- [ ] **Step 6: Write the failing test for `SpanContext`**

`packages/nexus-observability/tests/Unit/Trace/SpanContextTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Trace;

use Monadial\Nexus\Observability\Trace\SpanContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpanContext::class)]
final class SpanContextTest extends TestCase
{
    #[Test]
    public function invalidContextIsNotValid(): void
    {
        $context = SpanContext::invalid();

        self::assertFalse($context->isValid());
        self::assertFalse($context->remote);
        self::assertSame('', $context->traceState);
    }

    #[Test]
    public function populatedContextIsValid(): void
    {
        $context = new SpanContext(
            traceId: '0af7651916cd43dd8448eb211c80319c',
            spanId: 'b7ad6b7169203331',
            traceFlags: 1,
            remote: true,
        );

        self::assertTrue($context->isValid());
        self::assertTrue($context->remote);
    }

    #[Test]
    public function allZeroIdsAreNotValid(): void
    {
        $context = new SpanContext(
            traceId: str_repeat('0', 32),
            spanId: str_repeat('0', 16),
            traceFlags: 0,
            remote: false,
        );

        self::assertFalse($context->isValid());
    }
}
```

- [ ] **Step 7: Run the test to verify it fails**

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/Trace/SpanContextTest.php`
Expected: FAIL — `Class "Monadial\Nexus\Observability\Trace\SpanContext" not found`.

- [ ] **Step 8: Create the enums**

`packages/nexus-observability/src/Trace/SpanKind.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Trace;

/** @psalm-api */
enum SpanKind: string
{
    case Internal = 'internal';
    case Server = 'server';
    case Client = 'client';
    case Producer = 'producer';
    case Consumer = 'consumer';
}
```

`packages/nexus-observability/src/Trace/StatusCode.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Trace;

/** @psalm-api */
enum StatusCode: string
{
    case Unset = 'unset';
    case Ok = 'ok';
    case Error = 'error';
}
```

- [ ] **Step 9: Create `SpanContext`**

`packages/nexus-observability/src/Trace/SpanContext.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Trace;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Minimal, transport-agnostic identity of a span for context propagation.
 *
 * `traceId` is a 32-char lowercase hex string, `spanId` a 16-char lowercase hex
 * string, `traceFlags` the W3C trace-flags byte (only bit 0, "sampled", is used).
 */
final readonly class SpanContext
{
    public function __construct(
        public string $traceId,
        public string $spanId,
        public int $traceFlags,
        public bool $remote,
        public string $traceState = '',
    ) {}

    /** An unset/invalid context (all-zero ids). */
    public static function invalid(): self
    {
        return new self(
            traceId: str_repeat('0', 32),
            spanId: str_repeat('0', 16),
            traceFlags: 0,
            remote: false,
        );
    }

    /** True when both ids are non-zero (a real, propagatable context). */
    public function isValid(): bool
    {
        return $this->traceId !== str_repeat('0', 32)
            && $this->spanId !== str_repeat('0', 16);
    }
}
```

- [ ] **Step 10: Run the test to verify it passes**

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/Trace/SpanContextTest.php`
Expected: PASS (3 tests).

- [ ] **Step 11: Verify style + types on the new package**

Run: `docker compose exec -T php composer dump-autoload && make cs-fix && make psalm`
Expected: PHP-CS-Fixer applies/needs no changes; Psalm reports no errors.

- [ ] **Step 12: Commit**

```bash
git add packages/nexus-observability composer.json deptrac.yaml phpunit.xml
git commit -m "feat(observability): scaffold nexus-observability + SpanKind/StatusCode/SpanContext"
```

---

## Task 2: Context, Baggage & W3C propagators (Trace Context + Baggage + Composite)

The propagation backbone — the one mechanism used across every boundary. `Context` carries both a `SpanContext` and a `Baggage` map. The `ContextPropagator` interface uses a **composing** `extract` (an optional accumulator context) so multiple propagators chain. Implements W3C Trace Context and W3C Baggage, combined by `CompositePropagator`. Includes the `NoopContextPropagator` for the disabled path. This is the highest-value unit to test hard.

**Files:**
- Create: `packages/nexus-observability/src/Context/Baggage.php`
- Create: `packages/nexus-observability/src/Context/Context.php`
- Create: `packages/nexus-observability/src/Context/ContextPropagator.php`
- Create: `packages/nexus-observability/src/Context/TraceContextPropagator.php`
- Create: `packages/nexus-observability/src/Context/BaggagePropagator.php`
- Create: `packages/nexus-observability/src/Context/CompositePropagator.php`
- Create: `packages/nexus-observability/src/Context/NoopContextPropagator.php`
- Create: `packages/nexus-observability/tests/Unit/Context/BaggageTest.php`
- Create: `packages/nexus-observability/tests/Unit/Context/TraceContextPropagatorTest.php`
- Create: `packages/nexus-observability/tests/Unit/Context/BaggagePropagatorTest.php`
- Create: `packages/nexus-observability/tests/Unit/Context/CompositePropagatorTest.php`

**Interfaces:**
- Consumes: `SpanContext` (Task 1).
- Produces:
  - `final readonly class Baggage` with public `array<string,string> $values`; `static empty(): self`; `get(string): ?string`; `with(string, string): self`; `all(): array<string,string>`; `isEmpty(): bool`
  - `final readonly class Context` with public `SpanContext $spanContext` and `Baggage $baggage`; `static root(): self`; `static fromSpanContext(SpanContext $sc): self`; `withSpanContext(SpanContext): self`; `withBaggage(Baggage): self`
  - `interface ContextPropagator { public function inject(Context $context, array &$carrier): void; public function extract(array $carrier, ?Context $context = null): Context; }` where `$carrier` is `array<string, string>`
  - `final class TraceContextPropagator implements ContextPropagator`
  - `final class BaggagePropagator implements ContextPropagator`
  - `final class CompositePropagator implements ContextPropagator` — constructed from `list<ContextPropagator>`
  - `final class NoopContextPropagator implements ContextPropagator`

- [ ] **Step 1: Write the failing `Baggage` + `Context` test**

`packages/nexus-observability/tests/Unit/Context/BaggageTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Context;

use Monadial\Nexus\Observability\Context\Baggage;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Trace\SpanContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Baggage::class)]
#[CoversClass(Context::class)]
final class BaggageTest extends TestCase
{
    #[Test]
    public function emptyBaggageIsEmpty(): void
    {
        $baggage = Baggage::empty();

        self::assertTrue($baggage->isEmpty());
        self::assertNull($baggage->get('missing'));
        self::assertSame([], $baggage->all());
    }

    #[Test]
    public function withAddsImmutably(): void
    {
        $base = Baggage::empty();
        $updated = $base->with('tenant.id', 'acme');

        self::assertTrue($base->isEmpty());
        self::assertSame('acme', $updated->get('tenant.id'));
        self::assertFalse($updated->isEmpty());
    }

    #[Test]
    public function rootContextHasEmptyBaggageAndInvalidSpan(): void
    {
        $context = Context::root();

        self::assertFalse($context->spanContext->isValid());
        self::assertTrue($context->baggage->isEmpty());
    }

    #[Test]
    public function withersReplaceOnlyTheTargetField(): void
    {
        $span = new SpanContext(
            traceId: '0af7651916cd43dd8448eb211c80319c',
            spanId: 'b7ad6b7169203331',
            traceFlags: 1,
            remote: false,
        );
        $context = Context::root()
            ->withSpanContext($span)
            ->withBaggage(Baggage::empty()->with('user.tier', 'gold'));

        self::assertTrue($context->spanContext->isValid());
        self::assertSame('gold', $context->baggage->get('user.tier'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/Context/BaggageTest.php`
Expected: FAIL — `Baggage`/`Context` not found.

- [ ] **Step 3: Create `Baggage`**

`packages/nexus-observability/src/Context/Baggage.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Context;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Immutable W3C Baggage: a set of cross-cutting string key/value pairs that
 * propagate alongside trace context, independent of sampling.
 */
final readonly class Baggage
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(
        public array $values,
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function with(string $key, string $value): self
    {
        $values = $this->values;
        $values[$key] = $value;

        return new self($values);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
```

- [ ] **Step 4: Create `Context`**

`packages/nexus-observability/src/Context/Context.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Context;

use Monadial\Nexus\Observability\Trace\SpanContext;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Minimal propagation context: the active {@see SpanContext} plus {@see Baggage}.
 */
final readonly class Context
{
    public function __construct(
        public SpanContext $spanContext,
        public Baggage $baggage,
    ) {}

    /** The empty root context (no valid span, no baggage). */
    public static function root(): self
    {
        return new self(SpanContext::invalid(), Baggage::empty());
    }

    public static function fromSpanContext(SpanContext $spanContext): self
    {
        return new self($spanContext, Baggage::empty());
    }

    public function withSpanContext(SpanContext $spanContext): self
    {
        return new self($spanContext, $this->baggage);
    }

    public function withBaggage(Baggage $baggage): self
    {
        return new self($this->spanContext, $baggage);
    }
}
```

- [ ] **Step 5: Run the `Baggage` test to verify it passes**

Run: `docker compose exec -T php composer dump-autoload && docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/Context/BaggageTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Write the failing `TraceContextPropagator` test**

`packages/nexus-observability/tests/Unit/Context/TraceContextPropagatorTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Context;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Trace\SpanContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TraceContextPropagator::class)]
final class TraceContextPropagatorTest extends TestCase
{
    private TraceContextPropagator $propagator;

    protected function setUp(): void
    {
        $this->propagator = new TraceContextPropagator();
    }

    #[Test]
    public function injectsValidContextAsTraceparent(): void
    {
        $context = Context::fromSpanContext(new SpanContext(
            traceId: '0af7651916cd43dd8448eb211c80319c',
            spanId: 'b7ad6b7169203331',
            traceFlags: 1,
            remote: false,
        ));

        $carrier = [];
        $this->propagator->inject($context, $carrier);

        self::assertSame(
            '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            $carrier['traceparent'],
        );
    }

    #[Test]
    public function injectDoesNothingForInvalidContext(): void
    {
        $carrier = [];
        $this->propagator->inject(Context::root(), $carrier);

        self::assertSame([], $carrier);
    }

    #[Test]
    public function extractsValidTraceparentAsRemoteContext(): void
    {
        $context = $this->propagator->extract([
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);

        self::assertTrue($context->spanContext->isValid());
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $context->spanContext->traceId);
        self::assertSame('b7ad6b7169203331', $context->spanContext->spanId);
        self::assertSame(1, $context->spanContext->traceFlags);
        self::assertTrue($context->spanContext->remote);
    }

    #[Test]
    public function extractPreservesTracestate(): void
    {
        $context = $this->propagator->extract([
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            'tracestate' => 'vendor=value',
        ]);

        self::assertSame('vendor=value', $context->spanContext->traceState);
    }

    #[Test]
    public function extractPreservesIncomingBaggage(): void
    {
        $incoming = Context::root()->withBaggage(
            \Monadial\Nexus\Observability\Context\Baggage::empty()->with('tenant.id', 'acme'),
        );

        $context = $this->propagator->extract(
            ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'],
            $incoming,
        );

        self::assertTrue($context->spanContext->isValid());
        self::assertSame('acme', $context->baggage->get('tenant.id'));
    }

    #[Test]
    public function extractReturnsRootWhenHeaderMissing(): void
    {
        self::assertFalse($this->propagator->extract([])->spanContext->isValid());
    }

    /**
     * @param non-empty-string $traceparent
     */
    #[Test]
    #[DataProvider('malformedHeaders')]
    public function extractReturnsInvalidSpanForMalformedHeader(string $traceparent): void
    {
        $context = $this->propagator->extract(['traceparent' => $traceparent]);

        self::assertFalse($context->spanContext->isValid());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedHeaders(): iterable
    {
        yield 'too few parts' => ['00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331'];
        yield 'bad version' => ['ff-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'];
        yield 'short trace id' => ['00-0af7-b7ad6b7169203331-01'];
        yield 'non-hex span id' => ['00-0af7651916cd43dd8448eb211c80319c-zzzzzzzzzzzzzzzz-01'];
        yield 'all-zero trace id' => ['00-00000000000000000000000000000000-b7ad6b7169203331-01'];
        yield 'all-zero span id' => ['00-0af7651916cd43dd8448eb211c80319c-0000000000000000-01'];
    }

    #[Test]
    public function roundTripPreservesContext(): void
    {
        $original = new SpanContext(
            traceId: '4bf92f3577b34da6a3ce929d0e0e4736',
            spanId: '00f067aa0ba902b7',
            traceFlags: 1,
            remote: false,
        );

        $carrier = [];
        $this->propagator->inject(Context::fromSpanContext($original), $carrier);
        $extracted = $this->propagator->extract($carrier)->spanContext;

        self::assertSame($original->traceId, $extracted->traceId);
        self::assertSame($original->spanId, $extracted->spanId);
        self::assertSame($original->traceFlags, $extracted->traceFlags);
    }
}
```

- [ ] **Step 7: Run the test to verify it fails**

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/Context/TraceContextPropagatorTest.php`
Expected: FAIL — `TraceContextPropagator` not found.

- [ ] **Step 8: Create the `ContextPropagator` interface**

`packages/nexus-observability/src/Context/ContextPropagator.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Context;

/**
 * @psalm-api
 *
 * Injects/extracts a {@see Context} into/from a string carrier (message
 * metadata or HTTP headers). `extract` takes an optional accumulator context so
 * multiple propagators can compose (see {@see CompositePropagator}).
 */
interface ContextPropagator
{
    /**
     * @param array<string, string> $carrier
     */
    public function inject(Context $context, array &$carrier): void;

    /**
     * @param array<string, string> $carrier
     */
    public function extract(array $carrier, ?Context $context = null): Context;
}
```

- [ ] **Step 9: Create `TraceContextPropagator`**

`packages/nexus-observability/src/Context/TraceContextPropagator.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Context;

use Monadial\Nexus\Observability\Trace\SpanContext;

use function hexdec;
use function preg_match;
use function sprintf;

/**
 * @psalm-api
 *
 * W3C Trace Context propagator (`traceparent` / `tracestate`). Preserves any
 * baggage on the incoming accumulator context.
 *
 * @see https://www.w3.org/TR/trace-context/
 */
final class TraceContextPropagator implements ContextPropagator
{
    private const string TRACEPARENT = 'traceparent';
    private const string TRACESTATE = 'tracestate';
    private const string TRACEPARENT_PATTERN = '/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/';

    public function inject(Context $context, array &$carrier): void
    {
        $spanContext = $context->spanContext;

        if (!$spanContext->isValid()) {
            return;
        }

        $carrier[self::TRACEPARENT] = sprintf(
            '00-%s-%s-%02x',
            $spanContext->traceId,
            $spanContext->spanId,
            $spanContext->traceFlags & 1,
        );

        if ($spanContext->traceState !== '') {
            $carrier[self::TRACESTATE] = $spanContext->traceState;
        }
    }

    public function extract(array $carrier, ?Context $context = null): Context
    {
        $base = $context ?? Context::root();
        $traceparent = $carrier[self::TRACEPARENT] ?? null;

        if ($traceparent === null || preg_match(self::TRACEPARENT_PATTERN, $traceparent, $matches) !== 1) {
            return $base;
        }

        $spanContext = new SpanContext(
            traceId: $matches[1],
            spanId: $matches[2],
            traceFlags: (int) hexdec($matches[3]),
            remote: true,
            traceState: $carrier[self::TRACESTATE] ?? '',
        );

        if (!$spanContext->isValid()) {
            return $base;
        }

        return $base->withSpanContext($spanContext);
    }
}
```

- [ ] **Step 10: Create `NoopContextPropagator`**

`packages/nexus-observability/src/Context/NoopContextPropagator.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Context;

/**
 * @psalm-api
 *
 * Zero-overhead propagator used when observability is disabled: injects nothing
 * and always extracts the (possibly supplied) context unchanged.
 */
final class NoopContextPropagator implements ContextPropagator
{
    public function inject(Context $context, array &$carrier): void {}

    public function extract(array $carrier, ?Context $context = null): Context
    {
        return $context ?? Context::root();
    }
}
```

- [ ] **Step 11: Run the trace test to verify it passes**

Run: `docker compose exec -T php composer dump-autoload && docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/Context/TraceContextPropagatorTest.php`
Expected: PASS (all cases including the 6 malformed-header rows + baggage preservation).

- [ ] **Step 12: Write the failing `BaggagePropagator` + `CompositePropagator` tests**

`packages/nexus-observability/tests/Unit/Context/BaggagePropagatorTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Context;

use Monadial\Nexus\Observability\Context\Baggage;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\Context;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BaggagePropagator::class)]
final class BaggagePropagatorTest extends TestCase
{
    private BaggagePropagator $propagator;

    protected function setUp(): void
    {
        $this->propagator = new BaggagePropagator();
    }

    #[Test]
    public function injectsBaggageHeader(): void
    {
        $context = Context::root()->withBaggage(
            Baggage::empty()->with('tenant.id', 'acme')->with('user.tier', 'gold'),
        );

        $carrier = [];
        $this->propagator->inject($context, $carrier);

        self::assertSame('tenant.id=acme,user.tier=gold', $carrier['baggage']);
    }

    #[Test]
    public function injectDoesNothingForEmptyBaggage(): void
    {
        $carrier = [];
        $this->propagator->inject(Context::root(), $carrier);

        self::assertSame([], $carrier);
    }

    #[Test]
    public function injectPercentEncodesValues(): void
    {
        $context = Context::root()->withBaggage(Baggage::empty()->with('note', 'a b,c'));

        $carrier = [];
        $this->propagator->inject($context, $carrier);

        self::assertSame('note=a%20b%2Cc', $carrier['baggage']);
    }

    #[Test]
    public function extractsBaggageHeader(): void
    {
        $context = $this->propagator->extract(['baggage' => 'tenant.id=acme,user.tier=gold']);

        self::assertSame('acme', $context->baggage->get('tenant.id'));
        self::assertSame('gold', $context->baggage->get('user.tier'));
    }

    #[Test]
    public function extractDecodesValuesAndIgnoresProperties(): void
    {
        $context = $this->propagator->extract(['baggage' => 'note=a%20b;meta=1']);

        self::assertSame('a b', $context->baggage->get('note'));
    }

    #[Test]
    public function extractPreservesIncomingSpanContext(): void
    {
        $incoming = Context::fromSpanContext(new \Monadial\Nexus\Observability\Trace\SpanContext(
            traceId: '0af7651916cd43dd8448eb211c80319c',
            spanId: 'b7ad6b7169203331',
            traceFlags: 1,
            remote: true,
        ));

        $context = $this->propagator->extract(['baggage' => 'k=v'], $incoming);

        self::assertTrue($context->spanContext->isValid());
        self::assertSame('v', $context->baggage->get('k'));
    }

    #[Test]
    public function extractReturnsEmptyBaggageWhenHeaderMissing(): void
    {
        self::assertTrue($this->propagator->extract([])->baggage->isEmpty());
    }
}
```

`packages/nexus-observability/tests/Unit/Context/CompositePropagatorTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Context;

use Monadial\Nexus\Observability\Context\Baggage;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Trace\SpanContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositePropagator::class)]
final class CompositePropagatorTest extends TestCase
{
    private CompositePropagator $propagator;

    protected function setUp(): void
    {
        $this->propagator = new CompositePropagator([
            new TraceContextPropagator(),
            new BaggagePropagator(),
        ]);
    }

    #[Test]
    public function injectsBothTraceparentAndBaggage(): void
    {
        $context = Context::fromSpanContext(new SpanContext(
            traceId: '0af7651916cd43dd8448eb211c80319c',
            spanId: 'b7ad6b7169203331',
            traceFlags: 1,
            remote: false,
        ))->withBaggage(Baggage::empty()->with('tenant.id', 'acme'));

        $carrier = [];
        $this->propagator->inject($context, $carrier);

        self::assertSame('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01', $carrier['traceparent']);
        self::assertSame('tenant.id=acme', $carrier['baggage']);
    }

    #[Test]
    public function extractsBothIntoOneContext(): void
    {
        $context = $this->propagator->extract([
            'baggage' => 'tenant.id=acme',
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);

        self::assertTrue($context->spanContext->isValid());
        self::assertSame('acme', $context->baggage->get('tenant.id'));
    }

    #[Test]
    public function roundTripThroughCarrierPreservesBoth(): void
    {
        $original = Context::fromSpanContext(new SpanContext(
            traceId: '4bf92f3577b34da6a3ce929d0e0e4736',
            spanId: '00f067aa0ba902b7',
            traceFlags: 1,
            remote: false,
        ))->withBaggage(Baggage::empty()->with('user.tier', 'gold'));

        $carrier = [];
        $this->propagator->inject($original, $carrier);
        $extracted = $this->propagator->extract($carrier);

        self::assertSame($original->spanContext->traceId, $extracted->spanContext->traceId);
        self::assertSame('gold', $extracted->baggage->get('user.tier'));
    }
}
```

- [ ] **Step 13: Run the tests to verify they fail**

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/Context/BaggagePropagatorTest.php packages/nexus-observability/tests/Unit/Context/CompositePropagatorTest.php`
Expected: FAIL — `BaggagePropagator`/`CompositePropagator` not found.

- [ ] **Step 14: Create `BaggagePropagator`**

`packages/nexus-observability/src/Context/BaggagePropagator.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Context;

use function explode;
use function implode;
use function rawurldecode;
use function rawurlencode;
use function str_contains;
use function trim;

/**
 * @psalm-api
 *
 * W3C Baggage propagator (`baggage` header). Member properties (after `;`) are
 * parsed away on extract and not emitted on inject.
 *
 * @see https://www.w3.org/TR/baggage/
 */
final class BaggagePropagator implements ContextPropagator
{
    private const string BAGGAGE = 'baggage';

    public function inject(Context $context, array &$carrier): void
    {
        if ($context->baggage->isEmpty()) {
            return;
        }

        $members = [];

        foreach ($context->baggage->all() as $key => $value) {
            $members[] = rawurlencode($key) . '=' . rawurlencode($value);
        }

        $carrier[self::BAGGAGE] = implode(',', $members);
    }

    public function extract(array $carrier, ?Context $context = null): Context
    {
        $base = $context ?? Context::root();
        $header = $carrier[self::BAGGAGE] ?? null;

        if ($header === null || $header === '') {
            return $base;
        }

        $baggage = $base->baggage;

        foreach (explode(',', $header) as $member) {
            $member = trim($member);

            // Drop any member-level properties after ';'.
            $pair = explode(';', $member, 2)[0];

            if (!str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);
            $baggage = $baggage->with(rawurldecode(trim($key)), rawurldecode(trim($value)));
        }

        return $base->withBaggage($baggage);
    }
}
```

- [ ] **Step 15: Create `CompositePropagator`**

`packages/nexus-observability/src/Context/CompositePropagator.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Context;

/**
 * @psalm-api
 *
 * Runs several propagators in order. `inject` applies each; `extract` threads
 * the accumulating context through each propagator so trace context and baggage
 * end up on one {@see Context}.
 */
final class CompositePropagator implements ContextPropagator
{
    /**
     * @param list<ContextPropagator> $propagators
     */
    public function __construct(
        private readonly array $propagators,
    ) {}

    public function inject(Context $context, array &$carrier): void
    {
        foreach ($this->propagators as $propagator) {
            $propagator->inject($context, $carrier);
        }
    }

    public function extract(array $carrier, ?Context $context = null): Context
    {
        $result = $context ?? Context::root();

        foreach ($this->propagators as $propagator) {
            $result = $propagator->extract($carrier, $result);
        }

        return $result;
    }
}
```

- [ ] **Step 16: Run the tests to verify they pass**

Run: `docker compose exec -T php composer dump-autoload && docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/Context`
Expected: PASS — Baggage, TraceContext, BaggagePropagator, and Composite suites all green.

- [ ] **Step 17: Verify style + types**

Run: `make cs-fix && make psalm`
Expected: no PHP-CS-Fixer changes needed after re-run; Psalm reports no errors.

- [ ] **Step 18: Commit**

```bash
git add packages/nexus-observability
git commit -m "feat(observability): add Context/Baggage + W3C Trace Context, Baggage, and Composite propagators"
```

---

## Task 3: Tracing contracts + no-op (`Span`, `Tracer`, `NoopSpan`, `NoopTracer`)

**Files:**
- Create: `packages/nexus-observability/src/Trace/Span.php`
- Create: `packages/nexus-observability/src/Trace/Tracer.php`
- Create: `packages/nexus-observability/src/Trace/NoopSpan.php`
- Create: `packages/nexus-observability/src/Trace/NoopTracer.php`
- Create: `packages/nexus-observability/tests/Unit/Trace/NoopTracerTest.php`

**Interfaces:**
- Consumes: `SpanKind`, `StatusCode`, `SpanContext` (Task 1); `Context` (Task 2).
- Produces:
  - `interface Span` — `setAttribute(string, string|int|float|bool): void`, `setAttributes(array<string,scalar>): void`, `addEvent(string, array<string,scalar>): void`, `recordException(\Throwable): void`, `setStatus(StatusCode, ?string): void`, `end(): void`, `context(): SpanContext`
  - `interface Tracer` — `startSpan(string $name, SpanKind $kind = SpanKind::Internal, array $attributes = [], ?Context $parent = null): Span`
  - `final class NoopSpan implements Span` with `static instance(): self`
  - `final class NoopTracer implements Tracer` with `static instance(): self`

- [ ] **Step 1: Write the failing test**

`packages/nexus-observability/tests/Unit/Trace/NoopTracerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Trace;

use Monadial\Nexus\Observability\Trace\NoopSpan;
use Monadial\Nexus\Observability\Trace\NoopTracer;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(NoopTracer::class)]
#[CoversClass(NoopSpan::class)]
final class NoopTracerTest extends TestCase
{
    #[Test]
    public function startSpanReturnsAnInvalidNoopSpan(): void
    {
        $span = NoopTracer::instance()->startSpan('op', SpanKind::Consumer, ['nexus.actor.path' => '/user/a']);

        self::assertInstanceOf(NoopSpan::class, $span);
        self::assertFalse($span->context()->isValid());
    }

    #[Test]
    public function spanMethodsDoNotThrow(): void
    {
        $span = NoopTracer::instance()->startSpan('op');

        $span->setAttribute('key', 'value');
        $span->setAttributes(['a' => 1, 'b' => true]);
        $span->addEvent('event', ['x' => 'y']);
        $span->recordException(new RuntimeException('boom'));
        $span->setStatus(StatusCode::Error, 'failed');
        $span->end();

        self::assertFalse($span->context()->isValid());
    }

    #[Test]
    public function instanceIsShared(): void
    {
        self::assertSame(NoopTracer::instance(), NoopTracer::instance());
        self::assertSame(NoopSpan::instance(), NoopSpan::instance());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/Trace/NoopTracerTest.php`
Expected: FAIL — `Span`/`Tracer`/`NoopSpan`/`NoopTracer` not found.

- [ ] **Step 3: Create the `Span` interface**

`packages/nexus-observability/src/Trace/Span.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Trace;

use Throwable;

/** @psalm-api */
interface Span
{
    public function setAttribute(string $key, string|int|float|bool $value): void;

    /**
     * @param array<string, scalar> $attributes
     */
    public function setAttributes(array $attributes): void;

    /**
     * @param array<string, scalar> $attributes
     */
    public function addEvent(string $name, array $attributes = []): void;

    public function recordException(Throwable $exception): void;

    public function setStatus(StatusCode $code, ?string $description = null): void;

    public function end(): void;

    public function context(): SpanContext;
}
```

- [ ] **Step 4: Create the `Tracer` interface**

`packages/nexus-observability/src/Trace/Tracer.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Trace;

use Monadial\Nexus\Observability\Context\Context;

/** @psalm-api */
interface Tracer
{
    /**
     * @param array<string, scalar> $attributes
     */
    public function startSpan(
        string $name,
        SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
        ?Context $parent = null,
    ): Span;
}
```

- [ ] **Step 5: Create `NoopSpan`**

`packages/nexus-observability/src/Trace/NoopSpan.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Trace;

use Throwable;

/**
 * @psalm-api
 *
 * Shared do-nothing span. Its context is always invalid, so downstream
 * propagation injects nothing.
 */
final class NoopSpan implements Span
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function setAttribute(string $key, string|int|float|bool $value): void {}

    public function setAttributes(array $attributes): void {}

    public function addEvent(string $name, array $attributes = []): void {}

    public function recordException(Throwable $exception): void {}

    public function setStatus(StatusCode $code, ?string $description = null): void {}

    public function end(): void {}

    public function context(): SpanContext
    {
        return SpanContext::invalid();
    }
}
```

- [ ] **Step 6: Create `NoopTracer`**

`packages/nexus-observability/src/Trace/NoopTracer.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Trace;

use Monadial\Nexus\Observability\Context\Context;

/** @psalm-api */
final class NoopTracer implements Tracer
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function startSpan(
        string $name,
        SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
        ?Context $parent = null,
    ): Span {
        return NoopSpan::instance();
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `docker compose exec -T php composer dump-autoload && docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/Trace/NoopTracerTest.php`
Expected: PASS (3 tests).

- [ ] **Step 8: Verify style + types**

Run: `make cs-fix && make psalm`
Expected: no changes needed; no Psalm errors.

- [ ] **Step 9: Commit**

```bash
git add packages/nexus-observability
git commit -m "feat(observability): add Tracer/Span contracts + no-op implementations"
```

---

## Task 4: Metrics contracts + no-op

**Files:**
- Create: `packages/nexus-observability/src/Metric/Counter.php`
- Create: `packages/nexus-observability/src/Metric/UpDownCounter.php`
- Create: `packages/nexus-observability/src/Metric/Histogram.php`
- Create: `packages/nexus-observability/src/Metric/ObservableGauge.php`
- Create: `packages/nexus-observability/src/Metric/Meter.php`
- Create: `packages/nexus-observability/src/Metric/NoopCounter.php`
- Create: `packages/nexus-observability/src/Metric/NoopUpDownCounter.php`
- Create: `packages/nexus-observability/src/Metric/NoopHistogram.php`
- Create: `packages/nexus-observability/src/Metric/NoopObservableGauge.php`
- Create: `packages/nexus-observability/src/Metric/NoopMeter.php`
- Create: `packages/nexus-observability/tests/Unit/Metric/NoopMeterTest.php`

**Interfaces:**
- Produces:
  - `interface Counter { public function add(int|float $value, array $attributes = []): void; }` (`$attributes` is `array<string,scalar>`)
  - `interface UpDownCounter { public function add(int|float $value, array $attributes = []): void; }`
  - `interface Histogram { public function record(int|float $value, array $attributes = []): void; }`
  - `interface ObservableGauge {}` (marker — the value is supplied by the callback registered at creation)
  - `interface Meter` — `counter(string $name, string $unit = '', string $description = ''): Counter`; `upDownCounter(...): UpDownCounter`; `histogram(...): Histogram`; `observableGauge(string $name, callable $callback, string $unit = '', string $description = ''): ObservableGauge` where `$callback` is `callable(): (int|float)`
  - `final class Noop{Counter,UpDownCounter,Histogram,ObservableGauge,Meter}` each with `static instance(): self`

- [ ] **Step 1: Write the failing test**

`packages/nexus-observability/tests/Unit/Metric/NoopMeterTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Metric;

use Monadial\Nexus\Observability\Metric\NoopCounter;
use Monadial\Nexus\Observability\Metric\NoopHistogram;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Observability\Metric\NoopUpDownCounter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoopMeter::class)]
#[CoversClass(NoopCounter::class)]
#[CoversClass(NoopUpDownCounter::class)]
#[CoversClass(NoopHistogram::class)]
final class NoopMeterTest extends TestCase
{
    #[Test]
    public function instrumentsAreCreatedAndDoNotThrow(): void
    {
        $meter = NoopMeter::instance();

        $counter = $meter->counter('nexus.messages.processed', '{message}', 'Messages processed');
        $counter->add(1, ['nexus.message.type' => 'Greet']);

        $upDown = $meter->upDownCounter('nexus.actor.mailbox.size');
        $upDown->add(-1);

        $histogram = $meter->histogram('nexus.message.processing.duration', 'ms');
        $histogram->record(12.5, ['nexus.message.type' => 'Greet']);

        $meter->observableGauge('nexus.runtime.coroutines', static fn (): int => 3);

        self::assertInstanceOf(NoopCounter::class, $counter);
        self::assertInstanceOf(NoopUpDownCounter::class, $upDown);
        self::assertInstanceOf(NoopHistogram::class, $histogram);
    }

    #[Test]
    public function instanceIsShared(): void
    {
        self::assertSame(NoopMeter::instance(), NoopMeter::instance());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/Metric/NoopMeterTest.php`
Expected: FAIL — metric classes not found.

- [ ] **Step 3: Create the instrument interfaces**

`packages/nexus-observability/src/Metric/Counter.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/** @psalm-api */
interface Counter
{
    /**
     * @param array<string, scalar> $attributes
     */
    public function add(int|float $value, array $attributes = []): void;
}
```

`packages/nexus-observability/src/Metric/UpDownCounter.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/** @psalm-api */
interface UpDownCounter
{
    /**
     * @param array<string, scalar> $attributes
     */
    public function add(int|float $value, array $attributes = []): void;
}
```

`packages/nexus-observability/src/Metric/Histogram.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/** @psalm-api */
interface Histogram
{
    /**
     * @param array<string, scalar> $attributes
     */
    public function record(int|float $value, array $attributes = []): void;
}
```

`packages/nexus-observability/src/Metric/ObservableGauge.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/**
 * @psalm-api
 *
 * Marker for an asynchronous gauge. The current value is produced by the
 * callback registered via {@see Meter::observableGauge()}; there is no
 * imperative record call.
 */
interface ObservableGauge {}
```

- [ ] **Step 4: Create the `Meter` interface**

`packages/nexus-observability/src/Metric/Meter.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/** @psalm-api */
interface Meter
{
    public function counter(string $name, string $unit = '', string $description = ''): Counter;

    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter;

    public function histogram(string $name, string $unit = '', string $description = ''): Histogram;

    /**
     * @param callable(): (int|float) $callback
     */
    public function observableGauge(
        string $name,
        callable $callback,
        string $unit = '',
        string $description = '',
    ): ObservableGauge;
}
```

- [ ] **Step 5: Create the no-op instruments**

`packages/nexus-observability/src/Metric/NoopCounter.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/** @psalm-api */
final class NoopCounter implements Counter
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function add(int|float $value, array $attributes = []): void {}
}
```

`packages/nexus-observability/src/Metric/NoopUpDownCounter.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/** @psalm-api */
final class NoopUpDownCounter implements UpDownCounter
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function add(int|float $value, array $attributes = []): void {}
}
```

`packages/nexus-observability/src/Metric/NoopHistogram.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/** @psalm-api */
final class NoopHistogram implements Histogram
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function record(int|float $value, array $attributes = []): void {}
}
```

`packages/nexus-observability/src/Metric/NoopObservableGauge.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/** @psalm-api */
final class NoopObservableGauge implements ObservableGauge
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
```

- [ ] **Step 6: Create `NoopMeter`**

`packages/nexus-observability/src/Metric/NoopMeter.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/** @psalm-api */
final class NoopMeter implements Meter
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function counter(string $name, string $unit = '', string $description = ''): Counter
    {
        return NoopCounter::instance();
    }

    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
    {
        return NoopUpDownCounter::instance();
    }

    public function histogram(string $name, string $unit = '', string $description = ''): Histogram
    {
        return NoopHistogram::instance();
    }

    public function observableGauge(
        string $name,
        callable $callback,
        string $unit = '',
        string $description = '',
    ): ObservableGauge {
        return NoopObservableGauge::instance();
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `docker compose exec -T php composer dump-autoload && docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/Metric/NoopMeterTest.php`
Expected: PASS (2 tests).

- [ ] **Step 8: Verify style + types**

Run: `make cs-fix && make psalm`
Expected: no changes needed; no Psalm errors.

- [ ] **Step 9: Commit**

```bash
git add packages/nexus-observability
git commit -m "feat(observability): add Meter + metric instrument contracts + no-op implementations"
```

---

## Task 5: `Observability` provider + `NoopObservability` + `ObservabilityConfig`

Ties the package together: the provider interface actors and satellites depend on, the no-op provider (the default everywhere), and the pure config value object with env parsing.

**Files:**
- Create: `packages/nexus-observability/src/Observability.php`
- Create: `packages/nexus-observability/src/NoopObservability.php`
- Create: `packages/nexus-observability/src/Config/ObservabilityConfig.php`
- Create: `packages/nexus-observability/tests/Unit/NoopObservabilityTest.php`
- Create: `packages/nexus-observability/tests/Unit/Config/ObservabilityConfigTest.php`

**Interfaces:**
- Consumes: `Tracer`/`NoopTracer` (Task 3), `Meter`/`NoopMeter` (Task 4), `ContextPropagator`/`NoopContextPropagator` (Task 2).
- Produces:
  - `interface Observability { public function tracer(): Tracer; public function meter(): Meter; public function propagator(): ContextPropagator; }`
  - `final class NoopObservability implements Observability` with `static instance(): self`
  - `final readonly class ObservabilityConfig` with public fields `bool $enabled, string $serviceName, ?string $exporterEndpoint, string $exporterProtocol, string $sampler, ?float $samplerArg, bool $tracesEnabled, bool $metricsEnabled, bool $logsEnabled, array<string,string> $resourceAttributes`; statics `disabled(): self`, `enabled(string $serviceName): self`, `fromEnv(array<string,string> $env): self`; withers `withServiceName(string): self`, `withExporterEndpoint(?string): self`, `withSampler(string, ?float): self`

- [ ] **Step 1: Write the failing provider test**

`packages/nexus-observability/tests/Unit/NoopObservabilityTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\NoopContextPropagator;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Observability\Trace\NoopTracer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoopObservability::class)]
final class NoopObservabilityTest extends TestCase
{
    #[Test]
    public function exposesNoopProviders(): void
    {
        $observability = NoopObservability::instance();

        self::assertInstanceOf(NoopTracer::class, $observability->tracer());
        self::assertInstanceOf(NoopMeter::class, $observability->meter());
        self::assertInstanceOf(NoopContextPropagator::class, $observability->propagator());
    }

    #[Test]
    public function propagatorExtractsRoot(): void
    {
        $context = NoopObservability::instance()->propagator()->extract(['traceparent' => '00-x']);

        self::assertFalse($context->spanContext->isValid());
        self::assertInstanceOf(Context::class, $context);
    }
}
```

- [ ] **Step 2: Write the failing config test**

`packages/nexus-observability/tests/Unit/Config/ObservabilityConfigTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Config;

use Monadial\Nexus\Observability\Config\ObservabilityConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObservabilityConfig::class)]
final class ObservabilityConfigTest extends TestCase
{
    #[Test]
    public function disabledHasSaneDefaults(): void
    {
        $config = ObservabilityConfig::disabled();

        self::assertFalse($config->enabled);
        self::assertSame('parentbased_always_on', $config->sampler);
        self::assertSame('http/protobuf', $config->exporterProtocol);
    }

    #[Test]
    public function fromEnvEnablesByDefault(): void
    {
        $config = ObservabilityConfig::fromEnv([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://collector:4318',
            'OTEL_SERVICE_NAME' => 'orders',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('orders', $config->serviceName);
        self::assertSame('http://collector:4318', $config->exporterEndpoint);
    }

    #[Test]
    public function fromEnvHonorsSdkDisabled(): void
    {
        $config = ObservabilityConfig::fromEnv(['OTEL_SDK_DISABLED' => 'true']);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function fromEnvParsesResourceAttributesAndSampler(): void
    {
        $config = ObservabilityConfig::fromEnv([
            'OTEL_RESOURCE_ATTRIBUTES' => 'deployment.environment=prod,team=payments',
            'OTEL_TRACES_SAMPLER' => 'parentbased_traceidratio',
            'OTEL_TRACES_SAMPLER_ARG' => '0.25',
        ]);

        self::assertSame('parentbased_traceidratio', $config->sampler);
        self::assertSame(0.25, $config->samplerArg);
        self::assertSame(
            ['deployment.environment' => 'prod', 'team' => 'payments'],
            $config->resourceAttributes,
        );
    }

    #[Test]
    public function withersReturnNewInstances(): void
    {
        $base = ObservabilityConfig::enabled('svc');
        $changed = $base->withSampler('always_on', null)->withServiceName('renamed');

        self::assertSame('svc', $base->serviceName);
        self::assertSame('renamed', $changed->serviceName);
        self::assertSame('always_on', $changed->sampler);
    }
}
```

- [ ] **Step 3: Run both tests to verify they fail**

Run: `docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/NoopObservabilityTest.php packages/nexus-observability/tests/Unit/Config/ObservabilityConfigTest.php`
Expected: FAIL — `Observability`/`NoopObservability`/`ObservabilityConfig` not found.

- [ ] **Step 4: Create the `Observability` interface**

`packages/nexus-observability/src/Observability.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability;

use Monadial\Nexus\Observability\Context\ContextPropagator;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Trace\Tracer;

/**
 * @psalm-api
 *
 * Entry point bundling the three telemetry providers. Passed into the actor
 * system and satellite instrumentation; defaults to {@see NoopObservability}.
 */
interface Observability
{
    public function tracer(): Tracer;

    public function meter(): Meter;

    public function propagator(): ContextPropagator;
}
```

- [ ] **Step 5: Create `NoopObservability`**

`packages/nexus-observability/src/NoopObservability.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability;

use Monadial\Nexus\Observability\Context\ContextPropagator;
use Monadial\Nexus\Observability\Context\NoopContextPropagator;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Observability\Trace\NoopTracer;
use Monadial\Nexus\Observability\Trace\Tracer;

/**
 * @psalm-api
 *
 * Zero-overhead default provider. Every method returns a shared no-op
 * singleton, so instrumentation call sites cost nothing when disabled.
 */
final class NoopObservability implements Observability
{
    private static ?self $instance = null;

    private ?ContextPropagator $propagator = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function tracer(): Tracer
    {
        return NoopTracer::instance();
    }

    public function meter(): Meter
    {
        return NoopMeter::instance();
    }

    public function propagator(): ContextPropagator
    {
        return $this->propagator ??= new NoopContextPropagator();
    }
}
```

- [ ] **Step 6: Create `ObservabilityConfig`**

`packages/nexus-observability/src/Config/ObservabilityConfig.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Config;

use function explode;
use function in_array;
use function str_contains;
use function strtolower;
use function trim;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Vendor-neutral observability configuration. Pure value object; the OTEL
 * bridge consumes it to build real providers. `fromEnv()` reads the standard
 * OpenTelemetry environment variables from an injected map (kept pure for
 * testability).
 */
final readonly class ObservabilityConfig
{
    /**
     * @param array<string, string> $resourceAttributes
     */
    public function __construct(
        public bool $enabled,
        public string $serviceName,
        public ?string $exporterEndpoint,
        public string $exporterProtocol,
        public string $sampler,
        public ?float $samplerArg,
        public bool $tracesEnabled,
        public bool $metricsEnabled,
        public bool $logsEnabled,
        public array $resourceAttributes,
    ) {}

    public static function disabled(): self
    {
        return new self(
            enabled: false,
            serviceName: 'nexus',
            exporterEndpoint: null,
            exporterProtocol: 'http/protobuf',
            sampler: 'parentbased_always_on',
            samplerArg: null,
            tracesEnabled: true,
            metricsEnabled: true,
            logsEnabled: true,
            resourceAttributes: [],
        );
    }

    public static function enabled(string $serviceName): self
    {
        return self::disabled()->withServiceName($serviceName)->withEnabled(true);
    }

    /**
     * @param array<string, string> $env
     */
    public static function fromEnv(array $env): self
    {
        $disabled = in_array(strtolower($env['OTEL_SDK_DISABLED'] ?? 'false'), ['1', 'true'], true);

        $samplerArg = isset($env['OTEL_TRACES_SAMPLER_ARG'])
            ? (float) $env['OTEL_TRACES_SAMPLER_ARG']
            : null;

        return new self(
            enabled: !$disabled,
            serviceName: $env['OTEL_SERVICE_NAME'] ?? 'nexus',
            exporterEndpoint: $env['OTEL_EXPORTER_OTLP_ENDPOINT'] ?? null,
            exporterProtocol: $env['OTEL_EXPORTER_OTLP_PROTOCOL'] ?? 'http/protobuf',
            sampler: $env['OTEL_TRACES_SAMPLER'] ?? 'parentbased_always_on',
            samplerArg: $samplerArg,
            tracesEnabled: true,
            metricsEnabled: true,
            logsEnabled: true,
            resourceAttributes: self::parseResourceAttributes($env['OTEL_RESOURCE_ATTRIBUTES'] ?? ''),
        );
    }

    public function withEnabled(bool $enabled): self
    {
        return new self(
            enabled: $enabled,
            serviceName: $this->serviceName,
            exporterEndpoint: $this->exporterEndpoint,
            exporterProtocol: $this->exporterProtocol,
            sampler: $this->sampler,
            samplerArg: $this->samplerArg,
            tracesEnabled: $this->tracesEnabled,
            metricsEnabled: $this->metricsEnabled,
            logsEnabled: $this->logsEnabled,
            resourceAttributes: $this->resourceAttributes,
        );
    }

    public function withServiceName(string $serviceName): self
    {
        return new self(
            enabled: $this->enabled,
            serviceName: $serviceName,
            exporterEndpoint: $this->exporterEndpoint,
            exporterProtocol: $this->exporterProtocol,
            sampler: $this->sampler,
            samplerArg: $this->samplerArg,
            tracesEnabled: $this->tracesEnabled,
            metricsEnabled: $this->metricsEnabled,
            logsEnabled: $this->logsEnabled,
            resourceAttributes: $this->resourceAttributes,
        );
    }

    public function withExporterEndpoint(?string $exporterEndpoint): self
    {
        return new self(
            enabled: $this->enabled,
            serviceName: $this->serviceName,
            exporterEndpoint: $exporterEndpoint,
            exporterProtocol: $this->exporterProtocol,
            sampler: $this->sampler,
            samplerArg: $this->samplerArg,
            tracesEnabled: $this->tracesEnabled,
            metricsEnabled: $this->metricsEnabled,
            logsEnabled: $this->logsEnabled,
            resourceAttributes: $this->resourceAttributes,
        );
    }

    public function withSampler(string $sampler, ?float $samplerArg): self
    {
        return new self(
            enabled: $this->enabled,
            serviceName: $this->serviceName,
            exporterEndpoint: $this->exporterEndpoint,
            exporterProtocol: $this->exporterProtocol,
            sampler: $sampler,
            samplerArg: $samplerArg,
            tracesEnabled: $this->tracesEnabled,
            metricsEnabled: $this->metricsEnabled,
            logsEnabled: $this->logsEnabled,
            resourceAttributes: $this->resourceAttributes,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function parseResourceAttributes(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $attributes = [];

        foreach (explode(',', $raw) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);
            $attributes[trim($key)] = trim($value);
        }

        return $attributes;
    }
}
```

- [ ] **Step 7: Run both tests to verify they pass**

Run: `docker compose exec -T php composer dump-autoload && docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit/NoopObservabilityTest.php packages/nexus-observability/tests/Unit/Config/ObservabilityConfigTest.php`
Expected: PASS.

- [ ] **Step 8: Run the whole package suite + static analysis + deptrac**

Run:
```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit
make cs-fix && make phpcs && make psalm
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --config-file=deptrac.yaml
```
Expected: all green — every unit test passes; PHPCS/PHP-CS-Fixer clean; Psalm 0 errors; Deptrac reports the `Observability` layer with no violations.

- [ ] **Step 9: Commit**

```bash
git add packages/nexus-observability
git commit -m "feat(observability): add Observability provider, NoopObservability, and ObservabilityConfig"
```

---

## Self-Review (completed by plan author)

- **Spec coverage (this plan's slice — §2 D2, §4, §10 config VO):** vendor-neutral interfaces ✓ (Tasks 3–4); no-op default ✓ (Tasks 3–5); W3C **Trace Context + Baggage** propagation on `array<string,string>` carrier via a composing `ContextPropagator` + `CompositePropagator` ✓ (Task 2, D7 + D15); `Baggage` map on `Context` ✓ (Task 2, D15); `Observability` provider ✓ (Task 5); `ObservabilityConfig` incl. env parsing + defaults `parentbased_always_on` (D10) and `http/protobuf` (D8) ✓ (Task 5); zero OTEL dependency / deptrac `Observability` depends on nothing ✓ (Task 1). Actor wiring, OTEL bridge (which wires the enabled `CompositePropagator`), satellites, logs, docs are **out of scope for this plan** and covered by Plans 2–10.
- **Placeholder scan:** none — every step contains complete code or an exact command.
- **Type consistency:** `SpanContext(traceId, spanId, traceFlags, remote, traceState)` used identically in Tasks 1, 2, 3. `Context` holds `spanContext` + `baggage` with `withSpanContext`/`withBaggage`, used consistently by all three propagators (Task 2). `ContextPropagator::extract(array $carrier, ?Context $context = null)` signature matches across `TraceContextPropagator`, `BaggagePropagator`, `CompositePropagator`, `NoopContextPropagator` (Tasks 2, 5). `Tracer::startSpan(name, kind, attributes, parent)` matches between interface (Task 3) and `NoopTracer` (Task 3). `Meter` instrument factory names (`counter`/`upDownCounter`/`histogram`/`observableGauge`) match between interface and `NoopMeter` (Task 4). `Observability::{tracer,meter,propagator}` match interface and `NoopObservability` (Task 5). `ObservabilityConfig` field list is identical across constructor, `disabled()`, `fromEnv()`, and all withers (Task 5).

## Downstream Plans (this is Plan 1 of the observability series)

2. **Actors + OTEL bridge** — `nexus-observability-otel` (SDK-backed Tracer/Meter, sampler/resource builder, PHP 8.5 spike) + core instrumentation (`ActorCell`/`ActorContext`/`ActorSystem`), core metrics, `Core → Observability` deptrac edge.
3. Swoole async export (`nexus-observability-swoole`) · 4. HTTP · 5. Persistence · 6. Worker-pool · 7. Doctrine · 8. Logs correlation · 9. Config surfaces (NexusApp/env/wizard) · 10. Documentation.
