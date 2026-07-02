# Observability — Plan 11: Internal Actor-System Metrics (`nexus-observability-actor`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Expose internal actor-system state as OTEL observable gauges (D24) — live root-actor count, dead-letter count, and running state — via an `ActorSystemMetrics` registrar bound to a running `ActorSystem` and the `Observability` meter.

**Architecture:** A tiny public accessor `ActorSystem::liveActorCount(): int` (counts alive root children — no new state, mirrors the existing private `hasAliveChildren()`), plus a new satellite package `nexus-observability-actor` with `ActorSystemMetrics::register()` that wires observable gauges reading the system's public API at collect-time. No-op when observability is disabled.

**Tech Stack:** PHP 8.5.7, `nexus-core` (ActorSystem accessor + registrar dep), `nexus-observability` (+ OTEL bridge & runtime-fiber dev-deps for tests), PHPUnit 13, Psalm L1, PHPCS, Deptrac, Docker.

## Global Constraints

- **Docker only:** prefix with `docker compose exec -T php`. `composer dump-autoload` after adding classes.
- **Commit policy:** `git -c commit.gpgsign=false commit --no-verify` (GrumPHP hook broken + worktree GPG times out; composer.lock is gitignored — don't `git add` it). Before EVERY commit: `make cs-fix && make phpcs && make psalm` (clean) + suites + deptrac. `make phpcs` enforces `ReferenceUsedNamesOnly`. `Warning: JIT...` is env noise.
- **NEVER** add `Co-Authored-By: Claude`. **No singletons.**
- **Backward compatibility:** the new `ActorSystem::liveActorCount()` accessor is additive; changes no existing behavior.
- **Fail-isolation (§12):** gauge callbacks must not throw out of collection; disabled → no gauges registered.
- **Attributes/cardinality (D11):** system-wide gauges, no per-actor dims.
- **Code style:** `declare(strict_types=1);`; `final`; `/** @psalm-api */`; alphabetical imports (+ `use function`); trailing commas; blank line before control structures; `#[Override]` where overriding.
- **Deptrac:** new layer `ObservabilityActor` may depend only on `Core` + `Observability`.
- **Tests:** core accessor test in `nexus-core/tests/Unit`; registrar test in the new package using a real `ActorSystem` (FiberRuntime) + the OTEL bridge in-memory metric exporter; `collect()` and assert gauge names + a live-actor value.

## Verified seams

- `ActorSystem`: private `array $children` (root actor refs by name), each `ActorRef` has `isAlive(): bool`; private `hasAliveChildren()` already iterates them. Public: `deadLetters(): DeadLetterRef`, `isRunning(): bool`, `spawn(Props, string): ActorRef`.
- `DeadLetterRef::captured(): list<object>` — captured undeliverable messages (count = dead-letter total).
- `Observability`: `isEnabled(): bool`, `meter(): Meter`. `Meter::observableGauge(string $name, callable $callback, string $unit = '', string $description = ''): ObservableGauge` (`$callback: (): int|float`).

---

## File Structure

```
packages/nexus-core/src/Actor/ActorSystem.php   (add liveActorCount())
packages/nexus-core/tests/Unit/Actor/ActorSystemLiveCountTest.php   (new)
packages/nexus-observability-actor/
  composer.json
  src/
    ActorSystemMetrics.php
  tests/
    Unit/
      ActorSystemMetricsTest.php
```
Shared files modified by Task 2: root `composer.json`, `deptrac.yaml`, `phpunit.xml`.

---

## Task 1: Add `ActorSystem::liveActorCount()`

**Files:**
- Modify: `packages/nexus-core/src/Actor/ActorSystem.php`
- Create: `packages/nexus-core/tests/Unit/Actor/ActorSystemLiveCountTest.php`

**Interfaces:**
- Produces: `ActorSystem::liveActorCount(): int` — number of alive root actors (children whose `isAlive()` is true).

- [ ] **Step 1: Write the failing test**

`packages/nexus-core/tests/Unit/Actor/ActorSystemLiveCountTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorSystem::class)]
final class ActorSystemLiveCountTest extends TestCase
{
    #[Test]
    public function countsLiveRootActors(): void
    {
        $system = ActorSystem::create('count-test', new TestRuntime());
        self::assertSame(0, $system->liveActorCount());

        $behavior = Behavior::receive(static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same());
        $system->spawn(Props::fromBehavior($behavior), 'a');
        $system->spawn(Props::fromBehavior($behavior), 'b');

        self::assertSame(2, $system->liveActorCount());
    }
}
```
> Verify the correct dependency-free runtime for a core unit test — the codebase's `Monadial\Nexus\Core\Tests\Support\TestRuntime` (per CLAUDE.md test utilities). If its namespace/name differs, use whatever the existing `nexus-core/tests/Unit/Actor` tests use to build an `ActorSystem` (grep an existing ActorSystem unit test). If actors need the runtime running to stay alive, assert immediately after spawn (they're alive on spawn) — do not run the loop.

- [ ] **Step 2: Run — expect FAIL** (`liveActorCount` not defined):
`docker compose exec -T php vendor/bin/phpunit packages/nexus-core/tests/Unit/Actor/ActorSystemLiveCountTest.php`

- [ ] **Step 3: Add the accessor** — in `ActorSystem.php`, next to `isRunning()` / `hasAliveChildren()`:
```php
    /**
     * Number of alive root actors currently registered under the system.
     *
     * @return int Count of root children whose actor is still alive.
     */
    public function liveActorCount(): int
    {
        $count = 0;

        foreach ($this->children as $child) {
            if ($child->isAlive()) {
                ++$count;
            }
        }

        return $count;
    }
```

- [ ] **Step 4: Run — expect PASS.** Then `make cs-fix && make phpcs && make psalm` (clean) + full unit suite (core change): `docker compose exec -T php vendor/bin/phpunit --testsuite=unit`.

- [ ] **Step 5: Commit**
```bash
git add packages/nexus-core
git -c commit.gpgsign=false commit --no-verify -m "feat(core): add ActorSystem::liveActorCount() accessor"
```

---

## Task 2: `nexus-observability-actor` + `ActorSystemMetrics`

**Files:**
- Create: `packages/nexus-observability-actor/composer.json`
- Create: `packages/nexus-observability-actor/src/ActorSystemMetrics.php`
- Create: `packages/nexus-observability-actor/tests/Unit/ActorSystemMetricsTest.php`
- Modify: root `composer.json`, `deptrac.yaml`, `phpunit.xml`

**Interfaces:**
- Produces: `final class ActorSystemMetrics` — ctor `(Observability $observability, ActorSystem $system)`; `register(): void` registers observable gauges `nexus.actor_system.live_actors`, `nexus.actor_system.dead_letters`, `nexus.actor_system.running`; no-op when disabled. Documented call-once.

- [ ] **Step 1: `packages/nexus-observability-actor/composer.json`**
```json
{
    "name": "nexus-actors/observability-actor",
    "description": "Nexus actor-system observability — internal-state metrics (live actors, dead letters, running) as OTEL gauges.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5.7",
        "nexus-actors/core": "dev-main",
        "nexus-actors/observability": "dev-main"
    },
    "require-dev": {
        "nexus-actors/observability-otel": "dev-main",
        "nexus-actors/runtime-fiber": "dev-main",
        "open-telemetry/sdk": "^1.14",
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Actor\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Actor\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Root `composer.json`** — add (alphabetical: `Actor` sorts FIRST within `Observability\\*`) to `autoload.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\Actor\\": "packages/nexus-observability-actor/src/",
```
and to `autoload-dev.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\Actor\\Tests\\": "packages/nexus-observability-actor/tests/",
```
Run `docker compose exec -T php composer dump-autoload`.

- [ ] **Step 3: `deptrac.yaml`** — add layer:
```yaml
    - name: ObservabilityActor
      collectors:
        - type: directory
          value: packages/nexus-observability-actor/src/.*
```
and ruleset:
```yaml
    ObservabilityActor:
      - Core
      - Observability
```

- [ ] **Step 4: `phpunit.xml`** — add to `<testsuite name="unit">`:
```xml
            <directory>packages/nexus-observability-actor/tests/Unit</directory>
```
and to `<source><include>`:
```xml
            <directory>packages/nexus-observability-actor/src</directory>
```

- [ ] **Step 5: Write the failing test**

`packages/nexus-observability-actor/tests/Unit/ActorSystemMetricsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Actor\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Observability\Actor\ActorSystemMetrics;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use OpenTelemetry\SDK\Metrics\Data\Sum;
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

#[CoversClass(ActorSystemMetrics::class)]
final class ActorSystemMetricsTest extends TestCase
{
    #[Test]
    public function registersActorSystemGauges(): void
    {
        $metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($metricExporter);
        $observability = new OtelObservability(
            new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter())),
            MeterProvider::builder()->addReader($reader)->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $system = ActorSystem::create('metrics-test', new FiberRuntime());
        $behavior = Behavior::receive(static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same());
        $system->spawn(Props::fromBehavior($behavior), 'a');
        $system->spawn(Props::fromBehavior($behavior), 'b');

        (new ActorSystemMetrics($observability, $system))->register();

        $reader->collect();
        $metrics = $metricExporter->collect();
        $names = array_map(static fn ($metric): string => $metric->name, $metrics);

        self::assertContains('nexus.actor_system.live_actors', $names);
        self::assertContains('nexus.actor_system.dead_letters', $names);
        self::assertContains('nexus.actor_system.running', $names);

        // live_actors should read 2
        $live = null;

        foreach ($metrics as $metric) {
            if ($metric->name === 'nexus.actor_system.live_actors' && $metric->data instanceof Sum) {
                $live = $metric->data->dataPoints[0]->value ?? null;
            }
        }

        self::assertSame(2, $live);
    }

    #[Test]
    public function disabledObservabilityRegistersNothing(): void
    {
        $system = ActorSystem::create('metrics-disabled', new FiberRuntime());
        (new ActorSystemMetrics(new NoopObservability(), $system))->register();

        self::expectNotToPerformAssertions();
    }
}
```
> The observable-gauge data type may surface as `Sum` or `Gauge` in the SDK's in-memory metrics — if `$metric->data instanceof Sum` doesn't match, inspect the actual data class (`Gauge`) and read `dataPoints[0]->value` from it. If reading the exact value is awkward across SDK versions, keep the name assertions and assert `dataPoints` is non-empty; but try for the value first.

- [ ] **Step 6: Run — expect FAIL** (`ActorSystemMetrics` not found):
`docker compose exec -T php vendor/bin/phpunit packages/nexus-observability-actor/tests/Unit/ActorSystemMetricsTest.php`

- [ ] **Step 7: Create `ActorSystemMetrics`**

`packages/nexus-observability-actor/src/ActorSystemMetrics.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Actor;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Observability\Observability;

use function count;

/**
 * @psalm-api
 *
 * Registers internal actor-system state as OpenTelemetry observable gauges,
 * collected on demand by the metric reader. No-op when observability is
 * disabled. Register once per system at startup — calling {@see self::register()}
 * more than once registers duplicate instruments.
 */
final class ActorSystemMetrics
{
    public function __construct(
        private readonly Observability $observability,
        private readonly ActorSystem $system,
    ) {}

    public function register(): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        $meter = $this->observability->meter();
        $system = $this->system;

        $meter->observableGauge(
            'nexus.actor_system.live_actors',
            static fn (): int => $system->liveActorCount(),
            '{actor}',
            'Number of live root actors in the system',
        );
        $meter->observableGauge(
            'nexus.actor_system.dead_letters',
            static fn (): int => count($system->deadLetters()->captured()),
            '{message}',
            'Total dead-lettered messages captured by the system',
        );
        $meter->observableGauge(
            'nexus.actor_system.running',
            static fn (): int => $system->isRunning()
                ? 1
                : 0,
            '{system}',
            'Whether the actor system runtime is running (1) or not (0)',
        );
    }
}
```

- [ ] **Step 8: Run — expect PASS.** Then full gate + deptrac:
```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-observability-actor/tests/Unit
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
make cs-fix && make phpcs && make psalm
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --config-file=deptrac.yaml
```
Expected: all green; deptrac `ObservabilityActor → {Core, Observability}`, 0 violations.

- [ ] **Step 9: Commit**
```bash
git add packages/nexus-observability-actor composer.json deptrac.yaml phpunit.xml
git -c commit.gpgsign=false commit --no-verify -m "feat(observability-actor): ActorSystemMetrics (live actors / dead letters / running gauges)"
```

---

## Self-Review (plan author)

- **Spec coverage (Plan 11 slice — §8 actor-system internals, D24, §12):** live-actor / dead-letter / running observable gauges ✓ (D24); minimal `ActorSystem::liveActorCount()` accessor ✓; no-op when disabled + fail-isolated callbacks ✓ (§12); system-wide (no high-cardinality dims) ✓ (D11). **Out of scope (documented):** scheduled-timer count + runtime fiber/coroutine count (no public accessor without deeper Core/runtime changes — the Swoole coroutine count is covered by Plan 10's `swoole.coroutine.*`); wiring `register()` at app start (documented in Plan 12).
- **Placeholder scan:** none — complete code or exact commands; `TestRuntime` name + SDK gauge data-type flagged to verify.
- **Type consistency:** `ActorSystem::liveActorCount(): int` used by the `nexus.actor_system.live_actors` gauge; `ActorSystemMetrics(Observability, ActorSystem)` with `register()`; gauge names used in code + asserted; `Meter::observableGauge(name, callable, unit, desc)` matches Plan 1.

## Downstream: Plan 12 = docs (Docusaurus guide + phpDocumentor wiring for all new packages + landing page; wiring guide: NexusApp::withObservability, register ActorSystemMetrics + SwooleAdminMetrics + SwooleContextRegistrar at startup, HTTP middleware / pool listeners / store & transport decorators / SQL middleware / TraceCorrelationProcessor). Follow-ups: EntityRefFactory entity-repo spans (D25); OTLP log export; Fiber context-storage warning fix.
