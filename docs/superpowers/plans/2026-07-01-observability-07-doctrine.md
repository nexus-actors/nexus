# Observability — Plan 7: Doctrine (`nexus-observability-doctrine`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Instrument the Doctrine data-access layer — DBAL connection-pool metrics (PSR-14 listeners), ORM EntityManager-pool metrics (PSR-14 listeners), and DBAL **SQL query spans** via a Doctrine DBAL `Middleware` — driven by the injected `Observability` provider (no-op default), fail-isolated.

**Architecture:** Two PSR-14 metric listeners subscribe to the existing DBAL/ORM pool events and record counters + a wait histogram (low-cardinality `pool.name` dim). A `TracingDriverMiddleware` (Doctrine DBAL middleware) wraps driver → connection → statement so each executed SQL statement opens a Client span carrying the **parameterized** query text (placeholders only — never bound values, D5) + operation name; it is injected via `Configuration::setMiddlewares([...])` (Plan 9 wires it into the pool).

**Scope note (D25):** `EntityRefFactory` (the entity actor-repository) is a `final`, builder-constructed class with no interface or events, so entity `resolve/replay/persist` spans require a base-package seam (interface extraction or PSR-14 events) and are **deferred to a follow-up**. Plan 7 delivers DBAL SQL spans + DBAL/ORM pool metrics, which cover the bulk of Doctrine tracing (entity-actor SQL is already spanned by the middleware, and the entity actor itself is spanned by Plan 3).

**Tech Stack:** PHP 8.5.7, Doctrine DBAL 4 (`Middleware`/`Abstract*Middleware`), pdo_sqlite (in-memory, for the SQL-span test), `nexus-observability` (+ OTEL bridge dev-deps), `nexus-doctrine-dbal`/`nexus-doctrine-orm` (events), PHPUnit 13, Psalm L1, PHPCS, Deptrac, Docker.

## Global Constraints

- **Docker only:** prefix with `docker compose exec -T php`. `composer dump-autoload` after adding classes.
- **Commit policy:** `git -c commit.gpgsign=false commit --no-verify` (GrumPHP hook broken + worktree GPG times out). Before EVERY commit: `make cs-fix && make phpcs && make psalm` (clean) + package suite. `make phpcs` enforces `ReferenceUsedNamesOnly`. `Warning: JIT...` is env noise.
- **NEVER** add `Co-Authored-By: Claude`. **No singletons.**
- **Fail-isolation (§12):** telemetry guarded (`safely()`); the underlying DB call / inner event work is NOT guarded — DB errors propagate (record on span first, rethrow). Listeners no-op when disabled; SQL wrappers short-circuit spans when disabled.
- **Attributes (D5/D11):** SQL span: `db.query.text` = the **parameterized SQL string** (placeholders only, no bound parameter values), `db.operation.name` (SELECT/INSERT/…). Metric dims: `pool.name` + (SQL) `db.operation.name` — bounded; never raw SQL or ids in metric dims.
- **`finishSpan` rule:** end the span in one `safely()` FIRST, then record any histogram in a SEPARATE `safely()`.
- **Code style:** `declare(strict_types=1);`; `final`; `/** @psalm-api */`; alphabetical imports (+ `use function`); string-keyed arrays **alphabetical**; trailing commas; blank line before control structures; multi-line ternaries; `#[Override]` on interface/abstract overrides.
- **Deptrac:** new layer `ObservabilityDoctrine` may depend only on `DoctrineDbal`, `DoctrineOrm`, `Observability` (Doctrine DBAL vendor classes are uncovered/allowed).
- **Tests:** listeners fire real events and assert exported metrics (OTEL bridge in-memory); the SQL middleware wraps a real in-memory sqlite connection (`['driver' => 'pdo_sqlite', 'memory' => true]` + `Configuration::setMiddlewares`) and asserts exported Client spans.

## Verified seams

- DBAL pool events (`Monadial\Nexus\Doctrine\Dbal\Event\`): `ConnectionTaken(string $poolName, Duration $waitTime)`, `ConnectionCreated`, `ConnectionReleased`, `ConnectionDestroyed`, `ConnectionPoisoned`, `PoolExhausted` (each carries at least `public string $poolName` — verify remaining fields in source).
- ORM pool events (`Monadial\Nexus\Doctrine\Orm\Event\`): `EntityManagerCreated(string $poolName)`, `EntityManagerCleared`, `EntityManagerEvicted` (each `public string $poolName`).
- DBAL middleware: `Doctrine\DBAL\Driver\Middleware::wrap(Driver): Driver`; helpers `Doctrine\DBAL\Driver\Middleware\{AbstractDriverMiddleware,AbstractConnectionMiddleware,AbstractStatementMiddleware}`. `AbstractDriverMiddleware::connect(array $params): Connection`; `AbstractConnectionMiddleware::prepare(string $sql): Statement`, `query(string $sql): Result`, `exec(string $sql): int|string`; `AbstractStatementMiddleware::execute(): Result`. Injected via `Doctrine\DBAL\Configuration::setMiddlewares(array $middlewares)` passed to `DriverManager::getConnection($params, $config)`.
- `Monadial\Nexus\Runtime\Duration`: `toSecondsFloat(): float` (for the wait histogram).
- `Observability`: `isEnabled()`, `tracer()`, `meter()`. `Tracer::startSpan(name, SpanKind, array<string,scalar>, ?Context)`.

---

## File Structure

```
packages/nexus-observability-doctrine/
  composer.json
  src/
    DbalPoolMetricsListener.php
    OrmPoolMetricsListener.php
    Sql/
      TracingDriverMiddleware.php
      TracingDriver.php
      TracingConnection.php
      TracingStatement.php
  tests/
    Unit/
      DbalPoolMetricsListenerTest.php
      OrmPoolMetricsListenerTest.php
      Sql/TracingDriverMiddlewareTest.php
```
Shared files modified by Task 1: root `composer.json`, `deptrac.yaml`, `phpunit.xml`.

---

## Task 1: Scaffold + `DbalPoolMetricsListener`

**Files:**
- Create: `packages/nexus-observability-doctrine/composer.json`
- Create: `packages/nexus-observability-doctrine/src/DbalPoolMetricsListener.php`
- Create: `packages/nexus-observability-doctrine/tests/Unit/DbalPoolMetricsListenerTest.php`
- Modify: root `composer.json`, `deptrac.yaml`, `phpunit.xml`

**Interfaces:**
- Produces: `final class DbalPoolMetricsListener` — ctor `(Observability $observability)`; one handler method per DBAL pool event, each recording a counter (dim `pool.name`); `onConnectionTaken` additionally records a `nexus.dbal.pool.acquire.wait` histogram (seconds). No-op when disabled; instruments cached.

- [ ] **Step 1: `packages/nexus-observability-doctrine/composer.json`**
```json
{
    "name": "nexus-actors/observability-doctrine",
    "description": "Nexus Doctrine observability — DBAL/ORM pool metrics and DBAL SQL query tracing.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5.7",
        "doctrine/dbal": "^4.0",
        "nexus-actors/doctrine-dbal": "dev-main",
        "nexus-actors/doctrine-orm": "dev-main",
        "nexus-actors/observability": "dev-main"
    },
    "require-dev": {
        "nexus-actors/observability-otel": "dev-main",
        "open-telemetry/sdk": "^1.14",
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Doctrine\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Doctrine\\Tests\\": "tests/"
        }
    }
}
```
> Verify `nexus-actors/doctrine-dbal` and `nexus-actors/doctrine-orm` are the real Packagist names in their `composer.json`; match exactly.

- [ ] **Step 2: Root `composer.json`** — add to `autoload.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\Doctrine\\": "packages/nexus-observability-doctrine/src/",
```
and to `autoload-dev.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\Doctrine\\Tests\\": "packages/nexus-observability-doctrine/tests/",
```
Run `docker compose exec -T php composer dump-autoload`.

- [ ] **Step 3: `deptrac.yaml`** — add layer:
```yaml
    - name: ObservabilityDoctrine
      collectors:
        - type: directory
          value: packages/nexus-observability-doctrine/src/.*
```
and ruleset:
```yaml
    ObservabilityDoctrine:
      - DoctrineDbal
      - DoctrineOrm
      - Observability
```

- [ ] **Step 4: `phpunit.xml`** — add to `<testsuite name="unit">`:
```xml
            <directory>packages/nexus-observability-doctrine/tests/Unit</directory>
```
and to `<source><include>`:
```xml
            <directory>packages/nexus-observability-doctrine/src</directory>
```

- [ ] **Step 5: Confirm the exact DBAL pool event fields** — run:
`docker compose exec -T php grep -rn "__construct" packages/nexus-doctrine-dbal/src/Event/` and note each event's public fields (all have `public string $poolName`; `ConnectionTaken` also has `public Duration $waitTime`). Use the real field names in the listener.

- [ ] **Step 6: Write the failing test**

`packages/nexus-observability-doctrine/tests/Unit/DbalPoolMetricsListenerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Tests\Unit;

use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionCreated;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionTaken;
use Monadial\Nexus\Doctrine\Dbal\Event\PoolExhausted;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Doctrine\DbalPoolMetricsListener;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Runtime\Duration;
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

#[CoversClass(DbalPoolMetricsListener::class)]
final class DbalPoolMetricsListenerTest extends TestCase
{
    #[Test]
    public function recordsPoolEventMetrics(): void
    {
        $metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($metricExporter);
        $observability = new OtelObservability(
            new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter())),
            MeterProvider::builder()->addReader($reader)->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $listener = new DbalPoolMetricsListener($observability);
        $listener->onConnectionCreated(new ConnectionCreated('default'));
        $listener->onConnectionTaken(new ConnectionTaken('default', Duration::millis(5)));
        $listener->onPoolExhausted(new PoolExhausted('default'));

        $reader->collect();
        $names = array_map(static fn ($metric): string => $metric->name, $metricExporter->collect());
        self::assertContains('nexus.dbal.pool.connections.created', $names);
        self::assertContains('nexus.dbal.pool.connections.taken', $names);
        self::assertContains('nexus.dbal.pool.acquire.wait', $names);
        self::assertContains('nexus.dbal.pool.exhausted', $names);
    }

    #[Test]
    public function disabledObservabilityRecordsNothing(): void
    {
        $listener = new DbalPoolMetricsListener(new NoopObservability());
        $listener->onConnectionCreated(new ConnectionCreated('default'));

        self::expectNotToPerformAssertions();
    }
}
```
> Adjust event constructor args to the real signatures confirmed in Step 5 (e.g. if `ConnectionCreated`/`PoolExhausted` take more than `poolName`).

- [ ] **Step 7: Run — expect FAIL.**

- [ ] **Step 8: Create `DbalPoolMetricsListener`**

`packages/nexus-observability-doctrine/src/DbalPoolMetricsListener.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine;

use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionCreated;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionDestroyed;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionPoisoned;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionReleased;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionTaken;
use Monadial\Nexus\Doctrine\Dbal\Event\PoolExhausted;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Observability;

/**
 * @psalm-api
 *
 * PSR-14 listener recording DBAL connection-pool metrics. Register each
 * `on*` method for its event. No-op when observability is disabled. Metric
 * dimension is the bounded `pool.name`.
 */
final class DbalPoolMetricsListener
{
    /** @var array<string, Counter> */
    private array $counters = [];

    private ?Histogram $acquireWait = null;

    public function __construct(
        private readonly Observability $observability,
    ) {}

    public function onConnectionCreated(ConnectionCreated $event): void
    {
        $this->count('nexus.dbal.pool.connections.created', $event->poolName);
    }

    public function onConnectionTaken(ConnectionTaken $event): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        $this->count('nexus.dbal.pool.connections.taken', $event->poolName);
        $this->acquireWaitHistogram()->record(
            $event->waitTime->toSecondsFloat(),
            ['pool.name' => $event->poolName],
        );
    }

    public function onConnectionReleased(ConnectionReleased $event): void
    {
        $this->count('nexus.dbal.pool.connections.released', $event->poolName);
    }

    public function onConnectionDestroyed(ConnectionDestroyed $event): void
    {
        $this->count('nexus.dbal.pool.connections.destroyed', $event->poolName);
    }

    public function onConnectionPoisoned(ConnectionPoisoned $event): void
    {
        $this->count('nexus.dbal.pool.connections.poisoned', $event->poolName);
    }

    public function onPoolExhausted(PoolExhausted $event): void
    {
        $this->count('nexus.dbal.pool.exhausted', $event->poolName);
    }

    private function count(string $name, string $poolName): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        ($this->counters[$name] ??= $this->observability->meter()->counter($name, '{connection}', 'DBAL connection pool events'))
            ->add(1, ['pool.name' => $poolName]);
    }

    private function acquireWaitHistogram(): Histogram
    {
        return $this->acquireWait ??= $this->observability->meter()->histogram(
            'nexus.dbal.pool.acquire.wait',
            's',
            'Time spent waiting to acquire a pooled DBAL connection',
        );
    }
}
```

- [ ] **Step 9: Run — expect PASS.** Then `make cs-fix && make phpcs && make psalm` (clean).

- [ ] **Step 10: Commit**
```bash
git add packages/nexus-observability-doctrine composer.json composer.lock deptrac.yaml phpunit.xml
git -c commit.gpgsign=false commit --no-verify -m "feat(observability-doctrine): scaffold + DbalPoolMetricsListener"
```

---

## Task 2: `OrmPoolMetricsListener`

**Files:**
- Create: `packages/nexus-observability-doctrine/src/OrmPoolMetricsListener.php`
- Create: `packages/nexus-observability-doctrine/tests/Unit/OrmPoolMetricsListenerTest.php`

**Interfaces:**
- Produces: `final class OrmPoolMetricsListener` — ctor `(Observability $observability)`; `onEntityManagerCreated`/`onEntityManagerCleared`/`onEntityManagerEvicted`, each a counter (dim `pool.name`); no-op when disabled; instruments cached.

- [ ] **Step 1: Confirm ORM event fields** — `docker compose exec -T php grep -rn "__construct" packages/nexus-doctrine-orm/src/Event/` (each `public string $poolName`; adjust if more).

- [ ] **Step 2: Write the failing test**

`packages/nexus-observability-doctrine/tests/Unit/OrmPoolMetricsListenerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Tests\Unit;

use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCleared;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCreated;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerEvicted;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Doctrine\OrmPoolMetricsListener;
use Monadial\Nexus\Observability\Otel\OtelObservability;
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

#[CoversClass(OrmPoolMetricsListener::class)]
final class OrmPoolMetricsListenerTest extends TestCase
{
    #[Test]
    public function recordsEntityManagerPoolMetrics(): void
    {
        $metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($metricExporter);
        $observability = new OtelObservability(
            new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter())),
            MeterProvider::builder()->addReader($reader)->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $listener = new OrmPoolMetricsListener($observability);
        $listener->onEntityManagerCreated(new EntityManagerCreated('default'));
        $listener->onEntityManagerCleared(new EntityManagerCleared('default'));
        $listener->onEntityManagerEvicted(new EntityManagerEvicted('default'));

        $reader->collect();
        $names = array_map(static fn ($metric): string => $metric->name, $metricExporter->collect());
        self::assertContains('nexus.orm.pool.entity_managers.created', $names);
        self::assertContains('nexus.orm.pool.entity_managers.cleared', $names);
        self::assertContains('nexus.orm.pool.entity_managers.evicted', $names);
    }
}
```

- [ ] **Step 3: Create `OrmPoolMetricsListener`** — same shape as `DbalPoolMetricsListener` (cached counters map, `isEnabled()` guard, `pool.name` dim). Handlers: `onEntityManagerCreated` → `nexus.orm.pool.entity_managers.created`; `onEntityManagerCleared` → `...cleared`; `onEntityManagerEvicted` → `...evicted`.

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine;

use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCleared;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCreated;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerEvicted;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Observability;

/**
 * @psalm-api
 *
 * PSR-14 listener recording ORM EntityManager-pool metrics. No-op when
 * disabled. Metric dimension is the bounded `pool.name`.
 */
final class OrmPoolMetricsListener
{
    /** @var array<string, Counter> */
    private array $counters = [];

    public function __construct(
        private readonly Observability $observability,
    ) {}

    public function onEntityManagerCreated(EntityManagerCreated $event): void
    {
        $this->count('nexus.orm.pool.entity_managers.created', $event->poolName);
    }

    public function onEntityManagerCleared(EntityManagerCleared $event): void
    {
        $this->count('nexus.orm.pool.entity_managers.cleared', $event->poolName);
    }

    public function onEntityManagerEvicted(EntityManagerEvicted $event): void
    {
        $this->count('nexus.orm.pool.entity_managers.evicted', $event->poolName);
    }

    private function count(string $name, string $poolName): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        ($this->counters[$name] ??= $this->observability->meter()->counter($name, '{entity_manager}', 'ORM entity-manager pool events'))
            ->add(1, ['pool.name' => $poolName]);
    }
}
```

- [ ] **Step 4: Run — expect PASS.** Then `make cs-fix && make phpcs && make psalm` (clean) + package suite.

- [ ] **Step 5: Commit**
```bash
git add packages/nexus-observability-doctrine
git -c commit.gpgsign=false commit --no-verify -m "feat(observability-doctrine): OrmPoolMetricsListener"
```

---

## Task 3: DBAL SQL query spans (`TracingDriverMiddleware`)

Wraps a DBAL driver so every executed statement opens a Client span with the parameterized SQL. Injected via `Configuration::setMiddlewares([...])`.

**Files:**
- Create: `packages/nexus-observability-doctrine/src/Sql/TracingDriverMiddleware.php`
- Create: `packages/nexus-observability-doctrine/src/Sql/TracingDriver.php`
- Create: `packages/nexus-observability-doctrine/src/Sql/TracingConnection.php`
- Create: `packages/nexus-observability-doctrine/src/Sql/TracingStatement.php`
- Create: `packages/nexus-observability-doctrine/tests/Unit/Sql/TracingDriverMiddlewareTest.php`

**Interfaces:**
- Produces: `TracingDriverMiddleware implements Doctrine\DBAL\Driver\Middleware` (ctor `(Observability)`, `wrap(Driver): Driver`); `TracingDriver extends AbstractDriverMiddleware` (overrides `connect` → `TracingConnection`); `TracingConnection extends AbstractConnectionMiddleware` (overrides `prepare` → `TracingStatement`, spans `query`/`exec`); `TracingStatement extends AbstractStatementMiddleware` (spans `execute`, carrying the SQL). Client spans named by SQL operation, attrs `db.query.text` (parameterized) + `db.operation.name`.

- [ ] **Step 1: Write the failing test** (real in-memory sqlite):

`packages/nexus-observability-doctrine/tests/Unit/Sql/TracingDriverMiddlewareTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Tests\Unit\Sql;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Doctrine\Sql\TracingDriverMiddleware;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_values;

#[CoversClass(TracingDriverMiddleware::class)]
final class TracingDriverMiddlewareTest extends TestCase
{
    #[Test]
    public function spansExecutedStatementsWithParameterizedSql(): void
    {
        $spanExporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($spanExporter));
        $observability = new OtelObservability(
            $tracerProvider,
            MeterProvider::builder()->addReader(new ExportingReader(new MetricInMemoryExporter()))->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $config = new Configuration();
        $config->setMiddlewares([new TracingDriverMiddleware($observability)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);

        $connection->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->executeStatement('INSERT INTO t (id, name) VALUES (?, ?)', [1, 'alice']);
        $rows = $connection->executeQuery('SELECT name FROM t WHERE id = ?', [1])->fetchAllAssociative();
        self::assertSame('alice', $rows[0]['name']); // real query executed (delegation)

        $tracerProvider->forceFlush();
        $spans = $spanExporter->getSpans();
        self::assertNotEmpty($spans);

        // A span carrying the parameterized SELECT (placeholders, NOT bound values).
        $selectSpans = array_values(array_filter(
            $spans,
            static fn ($span): bool => $span->getAttributes()->get('db.query.text') === 'SELECT name FROM t WHERE id = ?',
        ));
        self::assertNotEmpty($selectSpans);
        self::assertSame(3, $selectSpans[0]->getKind()); // CLIENT
        self::assertSame('SELECT', $selectSpans[0]->getAttributes()->get('db.operation.name'));

        // No bound value 'alice' or '1' leaked into any db.query.text.
        foreach ($spans as $span) {
            $text = $span->getAttributes()->get('db.query.text');

            if ($text !== null) {
                self::assertStringNotContainsString('alice', (string) $text);
            }
        }
    }
}
```

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Create the middleware + driver**

`src/Sql/TracingDriverMiddleware.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Sql;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Monadial\Nexus\Observability\Observability;
use Override;

/**
 * @psalm-api
 *
 * Doctrine DBAL middleware that opens a Client span for each executed SQL
 * statement, carrying the parameterized query text (never bound values).
 * Add via `Configuration::setMiddlewares([...])`.
 */
final class TracingDriverMiddleware implements Middleware
{
    public function __construct(
        private readonly Observability $observability,
    ) {}

    #[Override]
    public function wrap(Driver $driver): Driver
    {
        return new TracingDriver($driver, $this->observability);
    }
}
```

`src/Sql/TracingDriver.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Sql;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Monadial\Nexus\Observability\Observability;
use Override;
use SensitiveParameter;

/** @psalm-api */
final class TracingDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly Observability $observability,
    ) {
        parent::__construct($driver);
    }

    #[Override]
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): Connection {
        return new TracingConnection(parent::connect($params), $this->observability);
    }
}
```
> Match the exact `connect()` signature of the installed `AbstractDriverMiddleware` (DBAL 4). If it does not use `#[SensitiveParameter]` or types `$params` differently, mirror the parent signature exactly (run `grep -n "function connect" vendor/doctrine/dbal/src/Driver/Middleware/AbstractDriverMiddleware.php` first).

- [ ] **Step 4: Create the connection + statement wrappers**

`src/Sql/TracingConnection.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Sql;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Override;
use Throwable;

use function preg_match;
use function strtoupper;

/** @psalm-api */
final class TracingConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        Connection $connection,
        private readonly Observability $observability,
    ) {
        parent::__construct($connection);
    }

    #[Override]
    public function prepare(string $sql): Statement
    {
        return new TracingStatement(parent::prepare($sql), $this->observability, $sql);
    }

    #[Override]
    public function query(string $sql): Result
    {
        if (!$this->observability->isEnabled()) {
            return parent::query($sql);
        }

        $span = SqlSpan::start($this->observability, $sql);

        try {
            return parent::query($sql);
        } catch (Throwable $e) {
            SqlSpan::error($span, $e);

            throw $e;
        } finally {
            SqlSpan::end($span);
        }
    }

    #[Override]
    public function exec(string $sql): int|string
    {
        if (!$this->observability->isEnabled()) {
            return parent::exec($sql);
        }

        $span = SqlSpan::start($this->observability, $sql);

        try {
            return parent::exec($sql);
        } catch (Throwable $e) {
            SqlSpan::error($span, $e);

            throw $e;
        } finally {
            SqlSpan::end($span);
        }
    }
}
```

`src/Sql/TracingStatement.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Sql;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Monadial\Nexus\Observability\Observability;
use Override;
use Throwable;

/** @psalm-api */
final class TracingStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private readonly Observability $observability,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    #[Override]
    public function execute(): Result
    {
        if (!$this->observability->isEnabled()) {
            return parent::execute();
        }

        $span = SqlSpan::start($this->observability, $this->sql);

        try {
            return parent::execute();
        } catch (Throwable $e) {
            SqlSpan::error($span, $e);

            throw $e;
        } finally {
            SqlSpan::end($span);
        }
    }
}
```

- [ ] **Step 5: Add the shared `SqlSpan` helper** (avoids duplicating span logic across connection/statement)

`src/Sql/SqlSpan.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Sql;

use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Throwable;

use function preg_match;
use function strtoupper;

/**
 * @internal
 *
 * Shared, fail-isolated span helpers for SQL execution. `db.query.text` is the
 * parameterized SQL (placeholders only — no bound values).
 */
final class SqlSpan
{
    public static function start(Observability $observability, string $sql): ?Span
    {
        try {
            $operation = self::operation($sql);

            return $observability->tracer()->startSpan(
                $operation === '' ? 'SQL' : $operation,
                SpanKind::Client,
                [
                    'db.operation.name' => $operation,
                    'db.query.text' => $sql,
                ],
            );
        } catch (Throwable) {
            return null;
        }
    }

    public static function error(?Span $span, Throwable $e): void
    {
        try {
            $span?->recordException($e);
            $span?->setStatus(StatusCode::Error, $e->getMessage());
        } catch (Throwable) {
            // Telemetry must never break the query.
        }
    }

    public static function end(?Span $span): void
    {
        try {
            $span?->end();
        } catch (Throwable) {
            // Telemetry must never break the query.
        }
    }

    private static function operation(string $sql): string
    {
        if (preg_match('/^\s*([a-zA-Z]+)/', $sql, $m) === 1) {
            return strtoupper($m[1]);
        }

        return '';
    }
}
```
> Remove the now-unused `Span`/`SpanKind`/`StatusCode`/`preg_match`/`strtoupper` imports from `TracingConnection.php` (they live in `SqlSpan` now) — keep only what each file actually uses so `make phpcs` (unused-import sniff) passes.

- [ ] **Step 6: Run — expect PASS.** Then full gate + deptrac:
```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-observability-doctrine/tests/Unit
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
make cs-fix && make phpcs && make psalm
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --config-file=deptrac.yaml
```
Expected: all green; deptrac `ObservabilityDoctrine → {DoctrineDbal, DoctrineOrm, Observability}`, 0 violations.
> If Psalm complains about the DBAL `connect()`/`prepare()`/`query()`/`exec()` override signatures, mirror the exact parent signatures from the installed `Abstract*Middleware` classes.

- [ ] **Step 7: Commit**
```bash
git add packages/nexus-observability-doctrine
git -c commit.gpgsign=false commit --no-verify -m "feat(observability-doctrine): DBAL SQL query spans via tracing middleware"
```

---

## Self-Review (plan author)

- **Spec coverage (Plan 7 slice — §7 Doctrine, §8 metrics, D5/D11/D21/D25, §12):** DBAL SQL Client spans with parameterized `db.query.text` (no bound values, D5) + `db.operation.name` ✓ (Task 3); DBAL pool metrics ✓ (Task 1); ORM EM-pool metrics ✓ (Task 2); low-cardinality dims (`pool.name`, `db.operation.name`) ✓ (D11); fail-isolation + errors-propagate + disabled short-circuit ✓ (§12). **Deferred (D25, documented):** `EntityRefFactory` entity `resolve/replay/persist` spans — needs a base-package interface/events seam (follow-up). **Out of scope:** transaction spans (begin/commit/rollback — can be added to `TracingConnection` later); wiring the middleware/listeners into the pool config (Plan 9).
- **Placeholder scan:** none — complete code or exact commands; event-field and DBAL-signature verifications flagged with the exact grep to run.
- **Type consistency:** both listeners share the cached-counter + `isEnabled()` shape; metric names used in code match the test assertions. The SQL wrappers all funnel through `SqlSpan::{start,error,end}` (Client kind, `db.operation.name`/`db.query.text`), asserted in the test. `TracingDriverMiddleware` → `TracingDriver` → `TracingConnection` → `TracingStatement` chain matches the DBAL Abstract*Middleware hierarchy.

## Downstream: Plan 8 = logs correlation (MDC trace_id/span_id from the active OTel span; reconcile with the existing TraceContextMiddleware — D13). Deferred here: EntityRefFactory entity-repo spans (base-package seam, D25); DBAL transaction spans; middleware/listener wiring (Plan 9).
