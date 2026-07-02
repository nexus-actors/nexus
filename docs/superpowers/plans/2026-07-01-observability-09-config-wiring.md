# Observability — Plan 9: Config Wiring (`NexusApp` + provider lifecycle) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Make observability turn-key from the application entry point — `NexusApp::withObservability(Observability)` threads the provider into the actor system and flushes it on shutdown — and give the `Observability` provider a `shutdown()` lifecycle method so telemetry is flushed cleanly when the app exits.

**Architecture:** Add `shutdown(): void` to the `Observability` interface (no-op default; the OTEL bridge flushes + stops its SDK providers). `NexusApp` gains a `withObservability()` builder method; `start()` passes the provider to `ActorSystem::create()`, and `run()` calls `$observability->shutdown()` in a `finally` after the event loop returns. Users build the provider via `ObservabilityFactory::fromConfig(ObservabilityConfig::fromEnv($_SERVER))` (env path, already working from Plans 1–2) or programmatically, and pass it in — keeping `nexus-app` decoupled from the OTEL bridge (it depends only on the `nexus-observability` interface).

**Scope note:** The **skeleton wizard step** + `docker-compose` collector scaffolding (D4) and **auto-registration** of the HTTP middleware / pool listeners / store & transport decorators / SQL middleware are **deferred to documentation** (Plan 12) — those are app-assembly-specific (each app builds its own HTTP pipeline / pool / stores), so they're wired by the user following the guide rather than magically. This plan delivers the core programmatic wiring (actor tracing + metrics + clean flush) via `NexusApp`.

**Tech Stack:** PHP 8.5.7, `nexus-app`, `nexus-observability` (+ OTEL bridge & runtime-fiber dev-deps for the integration test), PHPUnit 13, Psalm L1, PHPCS, Deptrac, Docker.

## Global Constraints

- **Docker only:** prefix with `docker compose exec -T php`. `composer dump-autoload` after edits.
- **Commit policy:** `git -c commit.gpgsign=false commit --no-verify` (GrumPHP hook broken + worktree GPG times out). Before EVERY commit: `make cs-fix && make phpcs && make psalm` (clean) + suites + deptrac. `make phpcs` enforces `ReferenceUsedNamesOnly`. `Warning: JIT...` is env noise.
- **NEVER** add `Co-Authored-By: Claude`. **No singletons.**
- **Backward compatibility:** `withObservability()` is optional; not calling it leaves the app on `NoopObservability` (unchanged behavior). `ActorSystem::create()` already defaults the provider.
- **Interface change is atomic:** adding `shutdown()` to `Observability` must land in the SAME commit as its implementations (Noop, Otel, Recording double) so Psalm never sees an incomplete implementor.
- **Fail-isolation (§12):** `NexusApp::run()` must call `$observability->shutdown()` in a `finally` and swallow any shutdown error (telemetry flush must not mask/replace an app error).
- **Code style:** `declare(strict_types=1);`; `final`; `/** @psalm-api */` on public API; alphabetical imports; trailing commas; blank line before control structures; `#[Override]`.
- **Deptrac:** `App` ruleset gains `Observability` (currently `[Core, Runtime]`).

## Verified seams

- `NexusApp`: mutable builder — `create(string): self`, `actor(string, Props): self`, `onStart(callable): self` (return `$this`); `start(Runtime, ?LoggerInterface): ActorSystem` (calls `ActorSystem::create($appName, $runtime, logger: $logger)`, spawns actors, invokes start callback, returns system); `run(Runtime, ?LoggerInterface): void` (calls `start()` then `$system->run()`).
- `ActorSystem::create(string $name, Runtime $runtime, ?ClockInterface $clock = null, ?LoggerInterface $logger = null, ?EventDispatcherInterface $eventDispatcher = null, ?Observability $observability = null)` (Plan 3).
- `Observability` interface (Plan 1/3): `tracer()`, `meter()`, `propagator()`, `currentContext()`, `isEnabled()`. `OtelObservability` already has `forceFlush(): void` + `shutdown(): void` (Plan 2). `NoopObservability` + `RecordingObservability` (core test support) implement the interface.

---

## Task 1: Add `Observability::shutdown()` (interface + all implementors)

**Files:**
- Modify: `packages/nexus-observability/src/Observability.php`
- Modify: `packages/nexus-observability/src/NoopObservability.php`
- Modify: `packages/nexus-observability-otel/src/OtelObservability.php` (add `#[Override]` — method already exists)
- Modify: `packages/nexus-core/tests/Support/Observability/RecordingObservability.php`
- Modify: `packages/nexus-observability/tests/Unit/NoopObservabilityTest.php`

**Interfaces:**
- Produces: `Observability::shutdown(): void` — flush and stop the telemetry provider (called once at app shutdown). No-op default.

- [ ] **Step 1: Add to the interface** — in `Observability.php`, after `currentContext()`:
```php
    /**
     * Flush pending telemetry and stop the provider. Called once during
     * application shutdown; a no-op provider does nothing.
     */
    public function shutdown(): void;
```

- [ ] **Step 2: `NoopObservability`** — add:
```php
    public function shutdown(): void {}
```

- [ ] **Step 3: `OtelObservability`** — it already has `public function shutdown(): void { $this->tracerProvider->shutdown(); $this->meterProvider->shutdown(); }`. Add `#[Override]` above it (and `use Override;` if not already imported — it is). Do not change behavior.

- [ ] **Step 4: `RecordingObservability`** (core test support) — add:
```php
    public function shutdown(): void {}
```

- [ ] **Step 5: Extend `NoopObservabilityTest`** — add:
```php
    #[Test]
    public function shutdownIsNoOp(): void
    {
        (new NoopObservability())->shutdown();

        self::expectNotToPerformAssertions();
    }
```

- [ ] **Step 6: Run + gate** (interface change touches multiple packages — run all):
```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
make cs-fix && make phpcs && make psalm
```
Expected: all green; Psalm 0 errors (every implementor now satisfies `shutdown()`).

- [ ] **Step 7: Commit**
```bash
git add packages/nexus-observability packages/nexus-observability-otel packages/nexus-core
git -c commit.gpgsign=false commit --no-verify -m "feat(observability): add Observability::shutdown() lifecycle (flush on app exit)"
```

---

## Task 2: `NexusApp::withObservability()` + shutdown flush

**Files:**
- Modify: `packages/nexus-app/composer.json` (require `nexus-actors/observability`; dev-require otel bridge + runtime-fiber + open-telemetry/sdk for the test)
- Modify: `deptrac.yaml` (`App` → add `Observability`)
- Modify: `packages/nexus-app/src/NexusApp.php`
- Create: `packages/nexus-app/tests/Unit/NexusAppObservabilityTest.php` (or Integration path if that matches the package's test layout)

**Interfaces:**
- Produces: `NexusApp::withObservability(Observability $observability): self`; `start()` threads it into `ActorSystem::create(...)`; `run()` calls `$observability->shutdown()` in a `finally`.

- [ ] **Step 1: composer + deptrac**

`packages/nexus-app/composer.json` — add to `require`: `"nexus-actors/observability": "dev-main"`; add to `require-dev` (alphabetical): `"nexus-actors/observability-otel": "dev-main"`, `"nexus-actors/runtime-fiber": "dev-main"`, `"open-telemetry/sdk": "^1.14"` (whichever aren't already present). `deptrac.yaml` — `App` ruleset becomes:
```yaml
    App:
      - Core
      - Observability
      - Runtime
```
Run `docker compose exec -T php composer dump-autoload`.

- [ ] **Step 2: `NexusApp` changes**

Add imports `use Monadial\Nexus\Observability\NoopObservability;` and `use Monadial\Nexus\Observability\Observability;` (alphabetical). Add a field + builder method:
```php
    private ?Observability $observability = null;
```
```php
    /**
     * Attach an observability provider (traces + metrics). The provider is
     * threaded into the actor system and shut down (flushed) when {@see run()}
     * returns. Build it via ObservabilityFactory::fromConfig(...) or pass a
     * NoopObservability to disable. Optional — defaults to no-op.
     */
    public function withObservability(Observability $observability): self
    {
        $this->observability = $observability;

        return $this;
    }
```
In `start()`, thread the provider into `ActorSystem::create`:
```php
        $system = ActorSystem::create(
            $this->appName,
            $runtime,
            logger: $logger,
            observability: $this->observability ?? new NoopObservability(),
        );
```
In `run()`, flush on shutdown (fail-isolated):
```php
    public function run(Runtime $runtime, ?LoggerInterface $logger = null): void
    {
        $observability = $this->observability;

        try {
            $system = $this->start($runtime, $logger);
            $system->run();
        } finally {
            try {
                $observability?->shutdown();
            } catch (\Throwable) {
                // Telemetry flush must not mask an application error.
            }
        }
    }
```
(Import `Throwable` at top rather than inline `\Throwable` to satisfy phpcs — `use Throwable;`.)

- [ ] **Step 3: Write the integration test**

`packages/nexus-app/tests/Unit/NexusAppObservabilityTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\App\Tests\Unit;

use Monadial\Nexus\App\NexusApp;
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
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversNothing]
final class NexusAppObservabilityTest extends TestCase
{
    #[Test]
    #[WithoutErrorHandler]
    public function providedObservabilityInstrumentsActorsAndFlushesOnShutdown(): void
    {
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($exporter));
        $observability = new OtelObservability(
            $tracerProvider,
            MeterProvider::builder()->addReader(new ExportingReader(new MetricInMemoryExporter()))->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $runtime = new FiberRuntime();
        $app = NexusApp::create('app-obs-test')
            ->actor('worker', Props::fromBehavior(Behavior::receive(
                static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same(),
            )))
            ->onStart(static function (ActorSystem $system) use ($runtime): void {
                $worker = $system->child('worker');
                self::assertInstanceOf(ActorRef::class, $worker);
                $worker->tell(new AppObsPing());
                $runtime->scheduleOnce(Duration::millis(200), static fn () => $system->shutdown(Duration::seconds(1)));
            })
            ->withObservability($observability);

        $app->run($runtime);

        // run() returned → NexusApp called $observability->shutdown() which force-flushed the tracer provider.
        $names = array_map(static fn ($span): string => $span->getName(), $exporter->getSpans());
        self::assertContains('process AppObsPing', $names);
    }
}

final readonly class AppObsPing {}
```
> If `NexusApp`'s existing tests live under a different directory/namespace (e.g. `tests/Integration`), mirror that layout and register it in `phpunit.xml` if needed. If `ActorSystem::child()` isn't the right accessor to fetch a spawned actor inside `onStart`, capture the ref another way (e.g. spawn in `onStart` directly and `tell`).

- [ ] **Step 4: Run — expect PASS.** Full gate + deptrac:
```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-app/tests
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
make cs-fix && make phpcs && make psalm
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --config-file=deptrac.yaml
```
Expected: all green; deptrac `App → {Core, Observability, Runtime}`, 0 violations.
> If the actor Consumer span is absent, the provider wasn't threaded or wasn't flushed — do NOT weaken; STOP and report BLOCKED with what you observed.

- [ ] **Step 5: Commit**
```bash
git add packages/nexus-app composer.json composer.lock deptrac.yaml phpunit.xml
git -c commit.gpgsign=false commit --no-verify -m "feat(app): NexusApp::withObservability() threads provider + flushes on shutdown"
```

---

## Self-Review (plan author)

- **Spec coverage (Plan 9 slice — §10 config, D4 programmatic builder + env, §11 shutdown flush, §12):** `NexusApp::withObservability()` programmatic wiring ✓ (D4 builder); provider threaded into `ActorSystem` ✓; `shutdown()` flush on app exit ✓ (§11), fail-isolated ✓ (§12); env path already delivered via `ObservabilityConfig::fromEnv` + `ObservabilityFactory::fromConfig` (Plans 1–2) — user builds + passes the provider, keeping `nexus-app` off the OTEL bridge. **Deferred to docs (Plan 12) / follow-up:** skeleton wizard step + docker-compose collector (D4 wizard); auto-registration of HTTP middleware / pool listeners / store & transport decorators / SQL middleware (app-assembly-specific — documented wiring); making `TraceCorrelationProcessor` the log span-id source in generated apps.
- **Placeholder scan:** none — complete code or exact commands; test-layout + `child()` accessor flagged to verify.
- **Type consistency:** `Observability::shutdown(): void` added to interface + all 3 implementors atomically (Task 1). `NexusApp::withObservability(Observability): self`; `start()` passes `observability:` named arg matching `ActorSystem::create`'s Plan-3 signature; `run()` calls `shutdown()` in `finally`.

## Downstream: Plan 10 = Swoole async export + admin metrics (`nexus-observability-swoole`, D23 + §11). Then Plan 11 = internal actor-system metrics (D24); Plan 12 = docs (incl. the deferred wizard + component-wiring guide). Follow-up: EntityRefFactory entity-repo spans (D25); OTLP log export.
