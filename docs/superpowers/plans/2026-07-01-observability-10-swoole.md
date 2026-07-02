# Observability — Plan 10: Swoole (`nexus-observability-swoole`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Swoole production support for observability — (1) install a **coroutine-aware OTEL context storage** so the active span is isolated per coroutine (no cross-coroutine span bleed under the Swoole runtime), and (2) expose **Swoole admin/coroutine metrics** as OTEL observable gauges (D23).

**Architecture:** `SwooleContextRegistrar::install()` wraps the default OTEL `ContextStorage` in `SwooleContextStorage` (from `open-telemetry/context-swoole`) and registers it via `Context::setStorage()` — idempotent, call-once at worker start. `SwooleAdminMetrics::register(Observability)` registers observable gauges reading `Swoole\Coroutine::stats()` (coroutine count/peak, event count) and, when a `Swoole\Server` is supplied, `Server::stats()` (connections, requests, active/idle workers). Both no-op when observability is disabled.

**Note on async export (§11):** `SwooleRuntime` already applies `SWOOLE_HOOK_ALL` (coroutine hooks) by default, so the stock OTLP HTTP exporter's cURL/stream I/O is **already non-blocking** inside coroutines. No custom coroutine transport is needed; this plan documents that and focuses on context-safety + admin metrics.

**Tech Stack:** PHP 8.5.7 ZTS + Swoole 6.2.1, `open-telemetry/context-swoole` 1.2, `nexus-observability` (+ OTEL bridge dev-dep), `nexus-runtime-swoole`, PHPUnit 13, Psalm L1, PHPCS, Deptrac, **Docker `php-swoole` container** (these tests need Swoole + a coroutine context).

## Global Constraints

- **Docker only, `php-swoole` container for tests:** run this package's tests with `docker compose exec -T php-swoole ...`. Lint/Psalm/Deptrac run in the default `php` container as usual. `composer dump-autoload` after adding classes.
- **Commit policy:** `git -c commit.gpgsign=false commit --no-verify` (GrumPHP hook broken + worktree GPG times out). Before EVERY commit: `make cs-fix && make phpcs && make psalm` (clean) + this package's swoole tests + `deptrac`. `make phpcs` enforces `ReferenceUsedNamesOnly`. `Warning: JIT...` is env noise.
- **NEVER** add `Co-Authored-By: Claude`. **No singletons.**
- **Fail-isolation (§12):** metric-gauge callbacks and context install must be guarded so a telemetry error never crashes a worker. Disabled → no gauges registered, context registrar still installs storage (harmless, needed for correctness) but is cheap.
- **Attributes/cardinality (D11):** admin gauges carry no high-cardinality dims (server-wide stats; optional static `server.name` only).
- **Code style:** `declare(strict_types=1);`; `final`; `/** @psalm-api */`; alphabetical imports (+ `use function`); trailing commas; blank line before control structures. Swoole classes referenced via imports (`Swoole\Coroutine`, `Swoole\Server`).
- **Deptrac:** new layer `ObservabilitySwoole` may depend only on `Observability` (+ RuntimeSwoole if referenced). Swoole/OTEL vendor classes are uncovered/allowed.
- **Psalm/Swoole:** Swoole classes come from `swoole/ide-helper` stubs (already a dev-dep). If Psalm can't resolve a Swoole symbol, confirm the stub path; do NOT broadly suppress.
- **Tests:** run inside `Swoole\Coroutine\run(...)`; register the gauges against the OTEL bridge with an in-memory metric exporter, `collect()`, assert the gauge metric names are present. For context storage, assert `install()` is idempotent and that `Context::storage()` returns a `SwooleContextStorage` after install.

## Verified seams

- `open-telemetry/context-swoole`: `OpenTelemetry\Contrib\Context\Swoole\SwooleContextStorage` — `__construct(ContextStorageInterface $storage)` (wraps an inner storage; implements `ContextStorageInterface & ExecutionContextAwareInterface`).
- `OpenTelemetry\Context\Context::setStorage(ContextStorageInterface&ExecutionContextAwareInterface $storage): void`; `Context::storage(): ContextStorageInterface` (current storage).
- `Swoole\Coroutine::stats(): array` — keys include `coroutine_num`, `coroutine_peak_num`, `event_num` (+ aio_*). `Swoole\Server::stats(): array` — keys include `connection_num`, `request_count`, `worker_num`, `idle_worker_num`, `coroutine_num`.
- `Observability`: `isEnabled(): bool`, `meter(): Meter`. `Meter::observableGauge(string $name, callable $callback, string $unit = '', string $description = ''): ObservableGauge` where `$callback: (): int|float` — reports one value per collect. (For multiple stats, register one gauge per stat.)
- `SwooleRuntime` applies `Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL])` when `SwooleConfig::$enableCoroutineHook` (default true).

---

## File Structure

```
packages/nexus-observability-swoole/
  composer.json
  src/
    SwooleContextRegistrar.php
    SwooleAdminMetrics.php
  tests/
    Unit/
      SwooleContextRegistrarTest.php
      SwooleAdminMetricsTest.php
```
Shared files modified by Task 1: root `composer.json`, `deptrac.yaml`, `phpunit.xml` (add the package dir to the **`unit-swoole`** testsuite, and `src` to coverage `<source>`).

---

## Task 1: Scaffold + `SwooleContextRegistrar`

**Files:**
- Create: `packages/nexus-observability-swoole/composer.json`
- Create: `packages/nexus-observability-swoole/src/SwooleContextRegistrar.php`
- Create: `packages/nexus-observability-swoole/tests/Unit/SwooleContextRegistrarTest.php`
- Modify: root `composer.json`, `deptrac.yaml`, `phpunit.xml`

**Interfaces:**
- Produces: `final class SwooleContextRegistrar` — `static install(): void` wraps the current OTEL context storage in `SwooleContextStorage` and registers it via `Context::setStorage()`; idempotent (does nothing if already a `SwooleContextStorage`).

- [ ] **Step 1: `packages/nexus-observability-swoole/composer.json`**
```json
{
    "name": "nexus-actors/observability-swoole",
    "description": "Nexus Swoole observability — coroutine-aware OTEL context storage and Swoole admin metrics.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5.7",
        "ext-swoole": "*",
        "nexus-actors/observability": "dev-main",
        "open-telemetry/context": "^1.0",
        "open-telemetry/context-swoole": "^1.2"
    },
    "require-dev": {
        "nexus-actors/observability-otel": "dev-main",
        "open-telemetry/sdk": "^1.14",
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Swoole\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Swoole\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Root `composer.json`** — add (alphabetical within `Observability\\*`) to `autoload.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\Swoole\\": "packages/nexus-observability-swoole/src/",
```
and to `autoload-dev.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\Swoole\\Tests\\": "packages/nexus-observability-swoole/tests/",
```
Run `docker compose exec -T php composer dump-autoload`.

- [ ] **Step 3: `deptrac.yaml`** — add layer:
```yaml
    - name: ObservabilitySwoole
      collectors:
        - type: directory
          value: packages/nexus-observability-swoole/src/.*
```
and ruleset:
```yaml
    ObservabilitySwoole:
      - Observability
```

- [ ] **Step 4: `phpunit.xml`** — add to `<testsuite name="unit-swoole">`:
```xml
            <directory>packages/nexus-observability-swoole/tests/Unit</directory>
```
and to `<source><include>`:
```xml
            <directory>packages/nexus-observability-swoole/src</directory>
```

- [ ] **Step 5: Write the failing test** (runs in `php-swoole`, inside a coroutine)

`packages/nexus-observability-swoole/tests/Unit/SwooleContextRegistrarTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Swoole\Tests\Unit;

use Monadial\Nexus\Observability\Swoole\SwooleContextRegistrar;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Context\Swoole\SwooleContextStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Swoole\Coroutine\run;

#[CoversClass(SwooleContextRegistrar::class)]
final class SwooleContextRegistrarTest extends TestCase
{
    #[Test]
    public function installsSwooleContextStorageIdempotently(): void
    {
        $seen = [];

        run(static function () use (&$seen): void {
            SwooleContextRegistrar::install();
            $first = Context::storage();
            SwooleContextRegistrar::install(); // idempotent
            $second = Context::storage();

            $seen['first'] = $first instanceof SwooleContextStorage;
            $seen['same'] = $first === $second;
        });

        self::assertTrue($seen['first'], 'storage should be SwooleContextStorage after install');
        self::assertTrue($seen['same'], 'install should be idempotent (same storage instance)');
    }
}
```
> Verify `use function Swoole\Coroutine\run;` is the correct entrypoint in this Swoole build (6.2.1 provides `Swoole\Coroutine\run`). If Psalm cannot resolve it, call `\Swoole\Coroutine\run(...)` via an imported alias or use `Swoole\Coroutine::create` within a `Swoole\Event::wait()` harness — but `run()` is standard.

- [ ] **Step 6: Run — expect FAIL** (`SwooleContextRegistrar` not found):
`docker compose exec -T php-swoole vendor/bin/phpunit packages/nexus-observability-swoole/tests/Unit/SwooleContextRegistrarTest.php`

- [ ] **Step 7: Create `SwooleContextRegistrar`**

`packages/nexus-observability-swoole/src/SwooleContextRegistrar.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Swoole;

use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Context\Swoole\SwooleContextStorage;

/**
 * @psalm-api
 *
 * Installs coroutine-aware OpenTelemetry context storage for the Swoole runtime,
 * so the active span is isolated per coroutine (no cross-coroutine bleed).
 * Call once per worker at startup; idempotent.
 */
final class SwooleContextRegistrar
{
    public static function install(): void
    {
        if (Context::storage() instanceof SwooleContextStorage) {
            return;
        }

        Context::setStorage(new SwooleContextStorage(Context::storage()));
    }
}
```

- [ ] **Step 8: Run — expect PASS** (in `php-swoole`). Then lint/type (default container):
`make cs-fix && make phpcs && make psalm`
> If Psalm reports `SwooleContextStorage` constructor variance or `Context::storage()` return type issues, confirm against the installed vendor signatures and adjust the typehints — do not broadly suppress.

- [ ] **Step 9: Commit**
```bash
git add packages/nexus-observability-swoole composer.json composer.lock deptrac.yaml phpunit.xml
git -c commit.gpgsign=false commit --no-verify -m "feat(observability-swoole): scaffold + SwooleContextRegistrar (coroutine-safe OTEL context)"
```

---

## Task 2: `SwooleAdminMetrics` (D23)

**Files:**
- Create: `packages/nexus-observability-swoole/src/SwooleAdminMetrics.php`
- Create: `packages/nexus-observability-swoole/tests/Unit/SwooleAdminMetricsTest.php`

**Interfaces:**
- Produces: `final class SwooleAdminMetrics` — ctor `(Observability $observability)`; `registerCoroutineGauges(): void` (observable gauges from `Coroutine::stats()`); `registerServerGauges(Server $server): void` (gauges from `Server::stats()`); no-op when disabled. Gauge names under `swoole.*`.

- [ ] **Step 1: Write the failing test** (php-swoole, coroutine)

`packages/nexus-observability-swoole/tests/Unit/SwooleAdminMetricsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Swoole\Tests\Unit;

use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Observability\Swoole\SwooleAdminMetrics;
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
use function Swoole\Coroutine\run;

#[CoversClass(SwooleAdminMetrics::class)]
final class SwooleAdminMetricsTest extends TestCase
{
    #[Test]
    public function registersCoroutineGauges(): void
    {
        $names = [];

        run(static function () use (&$names): void {
            $metricExporter = new MetricInMemoryExporter();
            $reader = new ExportingReader($metricExporter);
            $observability = new OtelObservability(
                new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter())),
                MeterProvider::builder()->addReader($reader)->build(),
                new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
            );

            (new SwooleAdminMetrics($observability))->registerCoroutineGauges();

            $reader->collect();
            $names = array_map(static fn ($metric): string => $metric->name, $metricExporter->collect());
        });

        self::assertContains('swoole.coroutine.count', $names);
        self::assertContains('swoole.coroutine.peak', $names);
    }

    #[Test]
    public function disabledObservabilityRegistersNothing(): void
    {
        $registered = true;

        run(static function () use (&$registered): void {
            (new SwooleAdminMetrics(new NoopObservability()))->registerCoroutineGauges();
            $registered = false; // reached without throwing
        });

        self::assertFalse($registered);
    }
}
```

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Create `SwooleAdminMetrics`**

`packages/nexus-observability-swoole/src/SwooleAdminMetrics.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Swoole;

use Monadial\Nexus\Observability\Observability;
use Swoole\Coroutine;
use Swoole\Server;

use function is_numeric;

/**
 * @psalm-api
 *
 * Registers Swoole server/coroutine statistics as OpenTelemetry observable
 * gauges (collected on demand by the metric reader). No-op when observability
 * is disabled. All gauges are server-wide (no high-cardinality dimensions).
 */
final class SwooleAdminMetrics
{
    public function __construct(
        private readonly Observability $observability,
    ) {}

    public function registerCoroutineGauges(): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        $meter = $this->observability->meter();

        $meter->observableGauge(
            'swoole.coroutine.count',
            static fn (): int => self::stat(Coroutine::stats(), 'coroutine_num'),
            '{coroutine}',
            'Number of running Swoole coroutines',
        );
        $meter->observableGauge(
            'swoole.coroutine.peak',
            static fn (): int => self::stat(Coroutine::stats(), 'coroutine_peak_num'),
            '{coroutine}',
            'Peak number of concurrent Swoole coroutines',
        );
    }

    public function registerServerGauges(Server $server): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        $meter = $this->observability->meter();

        $meter->observableGauge(
            'swoole.server.connections',
            static fn (): int => self::stat($server->stats(), 'connection_num'),
            '{connection}',
            'Active Swoole server connections',
        );
        $meter->observableGauge(
            'swoole.server.requests',
            static fn (): int => self::stat($server->stats(), 'request_count'),
            '{request}',
            'Total requests handled by the Swoole server',
        );
        $meter->observableGauge(
            'swoole.server.workers.idle',
            static fn (): int => self::stat($server->stats(), 'idle_worker_num'),
            '{worker}',
            'Idle Swoole worker processes',
        );
    }

    /**
     * @param array<string, mixed> $stats
     */
    private static function stat(array $stats, string $key): int
    {
        $value = $stats[$key] ?? 0;

        return is_numeric($value)
            ? (int) $value
            : 0;
    }
}
```

- [ ] **Step 4: Run — expect PASS** (php-swoole). Then full gate + deptrac (default container):
```bash
docker compose exec -T php-swoole vendor/bin/phpunit packages/nexus-observability-swoole/tests/Unit
make cs-fix && make phpcs && make psalm
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --config-file=deptrac.yaml
```
Expected: all green; deptrac `ObservabilitySwoole → {Observability}`, 0 violations. Also confirm the full non-swoole suite still passes: `docker compose exec -T php vendor/bin/phpunit --testsuite=unit`.
> Psalm note: if it can't resolve `Swoole\Server::stats()`/`Coroutine::stats()` return shapes, add a precise `/** @var array<string,mixed> */` where the array is consumed, not a blanket suppress. `swoole/ide-helper` is a dev-dep providing the stubs.

- [ ] **Step 5: Commit**
```bash
git add packages/nexus-observability-swoole
git -c commit.gpgsign=false commit --no-verify -m "feat(observability-swoole): SwooleAdminMetrics (coroutine + server observable gauges)"
```

---

## Self-Review (plan author)

- **Spec coverage (Plan 10 slice — §11 async export, D23 Swoole admin metrics, §12):** coroutine-safe OTEL context storage (`SwooleContextStorage` via registrar) ✓; Swoole admin/coroutine observable gauges ✓ (D23); async export noted as already handled by `SWOOLE_HOOK_ALL` in `SwooleRuntime` ✓ (§11); fail-isolation + disabled no-op ✓ (§12). **Out of scope (documented):** internal actor-system gauges (Plan 11); wiring `SwooleContextRegistrar::install()` + `SwooleAdminMetrics::register*()` into the Swoole HTTP server / worker bootstrap (documented in Plan 12 — call at worker start).
- **Placeholder scan:** none — complete code or exact commands; `Swoole\Coroutine\run` entrypoint + Psalm stub resolution flagged to verify.
- **Type consistency:** `SwooleContextRegistrar::install(): void` (static, idempotent); `SwooleAdminMetrics(Observability)` with `registerCoroutineGauges()` / `registerServerGauges(Server)`; gauge names (`swoole.coroutine.count/peak`, `swoole.server.*`) used in code + asserted in tests; `Meter::observableGauge(name, callable, unit, desc)` matches Plan 1 signature.

## Downstream: Plan 11 = internal actor-system metrics (D24 — observable gauges over ActorSystem state: live actors, dead-letters, timers, fibers/coroutines). Then Plan 12 = docs (incl. wiring guide: call SwooleContextRegistrar::install() + register admin metrics at worker start; register HTTP middleware / pool listeners / decorators / TraceCorrelationProcessor). Follow-ups: EntityRefFactory entity-repo spans (D25); OTLP log export.
