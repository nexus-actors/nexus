# Nexus Doctrine DBAL Implementation Plan (Plan 1 of 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `nexus-doctrine-dbal` package — a per-thread, coroutine-aware Doctrine DBAL `ConnectionPool` plus HTTP and actor integration, on the new `feat/nexus-doctrine` branch. Plans 2 (`-orm` core) and 3 (`EntityBehavior`) build on this one.

**Architecture:** A single `ConnectionPool` primitive backed by a `Channel` abstraction (`SwooleChannel` under Swoole, `FiberChannel` under Fiber). `take()` lazily creates connections up to `max` and otherwise suspends a coroutine on the channel until `borrowTimeout`. `release()` returns the connection or destroys it on `poison: true`. HTTP integration is a per-resource lease (`ConnectionLease`) attached to the `ServerRequest` by `ConnectionScopeMiddleware` and surfaced to handlers by `ConnectionResolver` (using the existing `ParamResolverRegistry` from `2026-06-15-handler-resolver-redesign-design.md`). Actor integration is `ActorPoolBinding` injected via `Props`. A new Psalm rule in `nexus-psalm` warns when actors store `Connection`-typed properties (long-lived borrow anti-pattern). Out of scope for this plan: ORM, `EntityManagerPool`, `EntityBehavior` — those are Plans 2 and 3.

**Tech Stack:** PHP 8.5 strict, Doctrine DBAL ^4.0, Swoole 6.0+ (production), PHP Fibers (dev/test), Psalm strict-level 1, PHPCS PER-CS2.0 + Slevomat, PHPUnit 13, GrumPHP pre-commit gates, Docker (no host PHP). Branch `feat/nexus-doctrine`.

---

## Spec → Plan map

| Spec section | Tasks |
|---|---|
| Architecture overview + Runtime model | T1, T13 |
| Connection pool (config, shape, take/release) | T2–T10 |
| Background tasks (evictor, leak detector) | T11–T12 |
| Bootstrap (`SWOOLE_HOOK_ALL`) + `DoctrinePool::fromUrl()` | T13 |
| HTTP integration (lease, middleware, resolver) | T14–T17 |
| Transactions (`#[Transactional]`) | T18 |
| Pool exhaustion → 503 + wiring helper | T19–T20 |
| Actor-side access (`ActorPoolBinding`) | T21 |
| Psalm rule (`PooledConnectionInActorPropertyRule`) | T22 |
| Testing (unit, fiber, swoole, worker-pool) | T23–T25 |
| Performance + final gates | T26–T27 |

---

## File structure

**New files in `packages/nexus-doctrine-dbal/`:**

```
packages/nexus-doctrine-dbal/
├── composer.json
├── src/
│   ├── Pool/
│   │   ├── ConnectionPool.php
│   │   ├── ConnectionFactory.php                       interface
│   │   ├── DriverManagerConnectionFactory.php          default impl wrapping DBAL DriverManager
│   │   ├── PoolConfig.php
│   │   ├── PoolStats.php
│   │   ├── Channel/
│   │   │   ├── Channel.php                             interface
│   │   │   ├── SwooleChannel.php
│   │   │   └── FiberChannel.php
│   │   ├── Evictor.php
│   │   └── LeakDetector.php
│   ├── Bootstrap/
│   │   └── DoctrineBootstrap.php                       SWOOLE_HOOK_ALL
│   ├── DoctrinePool.php                                public facade fromUrl()
│   ├── Http/
│   │   ├── ConnectionLease.php
│   │   ├── ConnectionScopeMiddleware.php
│   │   ├── ConnectionResolver.php                      ParamResolver impl
│   │   ├── Attribute/
│   │   │   └── Transactional.php
│   │   ├── TransactionalDecorator.php
│   │   ├── PoolExhaustedToServiceUnavailable.php
│   │   └── DoctrineHttp.php                            install() facade
│   ├── Actor/
│   │   └── ActorPoolBinding.php
│   ├── Event/
│   │   ├── ConnectionCreated.php
│   │   ├── ConnectionDestroyed.php
│   │   ├── ConnectionPoisoned.php
│   │   ├── ConnectionTaken.php
│   │   ├── ConnectionReleased.php
│   │   └── PoolExhausted.php
│   └── Exception/
│       ├── PoolExhaustedException.php
│       ├── PoolClosedException.php
│       ├── ConnectionPoisonedException.php
│       ├── MissingConnectionScopeException.php
│       └── MissingTransactionalDependencyException.php
├── tests/
│   ├── Unit/                                           mirrors src/
│   └── Support/
│       ├── StubConnectionFactory.php
│       └── ImmediateChannel.php                        synchronous test channel
└── phpunit.xml.dist
```

**Modified files:**
- `deptrac.yaml` — add `nexus-doctrine-dbal` layer.
- `docker-compose.yml` — add `mysql` service for integration tests.
- `Makefile` — add `test-doctrine` target.
- `composer.json` (root) — add `doctrine/dbal: ^4.0` to `require-dev` if not already present transitively.
- `packages/nexus-psalm/src/` — add `PooledConnectionInActorPropertyRule` + plugin wiring.

**New integration test tree:**
- `tests/Integration/Doctrine/Fiber/`
- `tests/Integration/Doctrine/Swoole/`
- `tests/Integration/Doctrine/WorkerPool/`

**New performance test:** `tests/Performance/Doctrine/PoolTakeReleaseBench.php`

---

## Conventions

- **Docker for everything.** Test command template:
  ```bash
  docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/<file>.php
  ```
- **GrumPHP gates each commit** (PHP-CS-Fixer, PHPCS, Psalm, PHPUnit unit suite). Never use `--no-verify`.
- **Commit format:** `feat(doctrine-dbal): <what>` for new code, `test(doctrine-dbal): …`, `docs(doctrine-dbal): …`, `chore(doctrine-dbal): …`. Match the existing repo style.
- **All classes `final`**, all value objects `readonly`. PER-CS2.0 + Slevomat. Arrays with string keys sorted alphabetically. Multi-line ternaries only. Blank line before `if`/`for`/`foreach`/`while`/`switch`/`try`.
- **`#[CoversClass(ClassName::class)]`** on every test class, **`#[Test]`** on every test method, **`#[CoversNothing]`** for interface-only tests.
- **PSR-3 logging via `LoggerInterface`** injected explicitly. **PSR-14 events via `EventDispatcherInterface`**. Both nullable in constructors with `null` falling back to no-op.

---

## Task 1: Package skeleton + Deptrac layer

**Files:**
- Create: `packages/nexus-doctrine-dbal/composer.json`
- Create: `packages/nexus-doctrine-dbal/phpunit.xml.dist`
- Create: `packages/nexus-doctrine-dbal/src/.gitkeep`
- Create: `packages/nexus-doctrine-dbal/tests/.gitkeep`
- Modify: `deptrac.yaml` — add a `NexusDoctrineDbal` layer with allowed deps `[NexusCore, NexusHttp, Doctrine, PsrLog, PsrEventDispatcher, PsrHttpServerMiddleware, PsrHttpMessage, Swoole]`.
- Modify: `composer.json` (root) — add `"Monadial\\Nexus\\Doctrine\\Dbal\\Tests\\": "packages/nexus-doctrine-dbal/tests/"` to `autoload-dev` if monorepo uses path repository; otherwise rely on package autoload.

- [ ] **Step 1: Write `packages/nexus-doctrine-dbal/composer.json`**

```json
{
    "name": "nexus-actors/doctrine-dbal",
    "description": "Nexus Doctrine DBAL — coroutine-aware connection pool, HTTP scope middleware, and actor-side integration.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "doctrine/dbal": "^4.0",
        "nexus-actors/core": "dev-main",
        "nexus-actors/http": "dev-main",
        "psr/event-dispatcher": "^1.0",
        "psr/http-factory": "^1.1",
        "psr/http-message": "^2.0",
        "psr/http-server-middleware": "^1.0",
        "psr/log": "^3.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Doctrine\\Dbal\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Doctrine\\Dbal\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

- [ ] **Step 2: Write `packages/nexus-doctrine-dbal/phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="../../vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 3: Modify `deptrac.yaml`**

Add a new layer block (place alphabetically among existing `NexusDoctrine*` / `Nexus*` layers):

```yaml
  - name: NexusDoctrineDbal
    collectors:
      - type: classLike
        regex: '^Monadial\\Nexus\\Doctrine\\Dbal\\.*'
```

Add a new ruleset block:

```yaml
  NexusDoctrineDbal:
    - NexusCore
    - NexusHttp
    - Doctrine
    - PsrEventDispatcher
    - PsrHttpFactory
    - PsrHttpMessage
    - PsrHttpServerMiddleware
    - PsrLog
    - Swoole
```

(If `Doctrine` / `Swoole` collector layers don't already exist in `deptrac.yaml`, look at the existing `NexusPersistenceDbal` block and follow the same pattern — copy whatever they reference for the doctrine and swoole vendor regexes.)

- [ ] **Step 4: Update root `composer.json`** if needed

Run:
```bash
docker compose exec -T php cat composer.json | grep -A1 '"Monadial\\\\Nexus\\\\Doctrine\\\\Dbal'
```
If the path repository doesn't auto-include `nexus-doctrine-dbal`, add it under `repositories` exactly the same way `nexus-doctrine` (existing) is registered. The repo uses a `path` repo per package — see `nexus-persistence-dbal` for the existing form. Mirror.

- [ ] **Step 5: Run composer dump-autoload**

```bash
docker compose exec -T php composer dump-autoload
```
Expected: no warnings; "Generated autoload files".

- [ ] **Step 6: Verify Deptrac passes**

```bash
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyze
```
Expected: `[OK] No deps changes`. If it fails on the new layer, the new layer regex is wrong — fix and re-run.

- [ ] **Step 7: Commit**

```bash
git add packages/nexus-doctrine-dbal/composer.json packages/nexus-doctrine-dbal/phpunit.xml.dist packages/nexus-doctrine-dbal/src/.gitkeep packages/nexus-doctrine-dbal/tests/.gitkeep deptrac.yaml composer.json composer.lock
git commit -m "feat(doctrine-dbal): scaffold nexus-doctrine-dbal package"
```

---

## Task 2: `PoolConfig` value object

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Pool/PoolConfig.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Pool/PoolConfigTest.php`

- [ ] **Step 1: Write the failing test**

`packages/nexus-doctrine-dbal/tests/Unit/Pool/PoolConfigTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool;

use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PoolConfig::class)]
final class PoolConfigTest extends TestCase
{
    #[Test]
    public function defaultValuesMatchSpec(): void
    {
        $config = new PoolConfig();

        self::assertSame(16, $config->max);
        self::assertSame(2, $config->minIdle);
        self::assertTrue($config->borrowTimeout->equals(Duration::seconds(5)));
        self::assertTrue($config->idleTtl->equals(Duration::seconds(300)));
        self::assertTrue($config->acquireTtl->equals(Duration::seconds(30)));
        self::assertFalse($config->healthCheckOnBorrow);
        self::assertSame('SELECT 1', $config->validationQuery);
    }

    #[Test]
    public function customValuesOverrideDefaults(): void
    {
        $config = new PoolConfig(
            acquireTtl: Duration::seconds(60),
            borrowTimeout: Duration::seconds(1),
            healthCheckOnBorrow: true,
            idleTtl: Duration::seconds(120),
            max: 32,
            minIdle: 4,
            validationQuery: 'SELECT 42',
        );

        self::assertSame(32, $config->max);
        self::assertSame(4, $config->minIdle);
        self::assertTrue($config->borrowTimeout->equals(Duration::seconds(1)));
        self::assertTrue($config->idleTtl->equals(Duration::seconds(120)));
        self::assertTrue($config->acquireTtl->equals(Duration::seconds(60)));
        self::assertTrue($config->healthCheckOnBorrow);
        self::assertSame('SELECT 42', $config->validationQuery);
    }

    #[Test]
    public function minIdleCannotExceedMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('minIdle (10) must not exceed max (5)');

        new PoolConfig(max: 5, minIdle: 10);
    }

    #[Test]
    public function maxMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PoolConfig(max: 0);
    }
}
```

- [ ] **Step 2: Run, verify failure**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Pool/PoolConfigTest.php
```
Expected: FAIL — `Class PoolConfig not found`.

- [ ] **Step 3: Implement `PoolConfig`**

`packages/nexus-doctrine-dbal/src/Pool/PoolConfig.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool;

use InvalidArgumentException;
use Monadial\Nexus\Core\Duration;

/**
 * @psalm-api
 */
final readonly class PoolConfig
{
    public Duration $acquireTtl;
    public Duration $borrowTimeout;
    public Duration $idleTtl;

    public function __construct(
        ?Duration $acquireTtl = null,
        ?Duration $borrowTimeout = null,
        public bool $healthCheckOnBorrow = false,
        ?Duration $idleTtl = null,
        public int $max = 16,
        public int $minIdle = 2,
        public string $validationQuery = 'SELECT 1',
    ) {
        if ($max <= 0) {
            throw new InvalidArgumentException(sprintf('max (%d) must be positive', $max));
        }

        if ($minIdle > $max) {
            throw new InvalidArgumentException(sprintf('minIdle (%d) must not exceed max (%d)', $minIdle, $max));
        }

        $this->acquireTtl = $acquireTtl ?? Duration::seconds(30);
        $this->borrowTimeout = $borrowTimeout ?? Duration::seconds(5);
        $this->idleTtl = $idleTtl ?? Duration::seconds(300);
    }
}
```

- [ ] **Step 4: Verify pass**

Same command as Step 2. Expected: 4 tests, 4 assertions, OK.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-doctrine-dbal/src/Pool/PoolConfig.php packages/nexus-doctrine-dbal/tests/Unit/Pool/PoolConfigTest.php
git commit -m "feat(doctrine-dbal): add PoolConfig value object"
```

---

## Task 3: `PoolStats` value object

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Pool/PoolStats.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Pool/PoolStatsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool;

use Monadial\Nexus\Doctrine\Dbal\Pool\PoolStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PoolStats::class)]
final class PoolStatsTest extends TestCase
{
    #[Test]
    public function emptyStats(): void
    {
        $stats = PoolStats::empty();

        self::assertSame(0, $stats->idle);
        self::assertSame(0, $stats->inUse);
        self::assertSame(0, $stats->total);
        self::assertSame(0, $stats->waitingCoroutines);
        self::assertSame(0, $stats->totalBorrows);
        self::assertSame(0, $stats->totalWaits);
        self::assertSame(0, $stats->totalTimeouts);
    }

    #[Test]
    public function explicitConstructionRetainsAllValues(): void
    {
        $stats = new PoolStats(
            idle: 3,
            inUse: 5,
            total: 8,
            waitingCoroutines: 2,
            totalBorrows: 100,
            totalWaits: 10,
            totalTimeouts: 1,
        );

        self::assertSame(8, $stats->total);
        self::assertSame(2, $stats->waitingCoroutines);
        self::assertSame(100, $stats->totalBorrows);
    }
}
```

- [ ] **Step 2: Run, verify failure**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Pool/PoolStatsTest.php
```

- [ ] **Step 3: Implement**

`packages/nexus-doctrine-dbal/src/Pool/PoolStats.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool;

/**
 * @psalm-api
 */
final readonly class PoolStats
{
    public function __construct(
        public int $idle,
        public int $inUse,
        public int $total,
        public int $waitingCoroutines,
        public int $totalBorrows,
        public int $totalWaits,
        public int $totalTimeouts,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0);
    }
}
```

- [ ] **Step 4: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Pool/PoolStatsTest.php
git add packages/nexus-doctrine-dbal/src/Pool/PoolStats.php packages/nexus-doctrine-dbal/tests/Unit/Pool/PoolStatsTest.php
git commit -m "feat(doctrine-dbal): add PoolStats snapshot type"
```

---

## Task 4: `Channel` interface + `FiberChannel` (sync) implementation

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Pool/Channel/Channel.php`
- Create: `packages/nexus-doctrine-dbal/src/Pool/Channel/FiberChannel.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Pool/Channel/FiberChannelTest.php`

The `Channel` is a bounded queue with optional blocking `pop`. Under Swoole it'll be a coroutine channel; under Fiber it's a simple `SplQueue` — non-blocking, returns `null` immediately if empty.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool\Channel;

use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(FiberChannel::class)]
final class FiberChannelTest extends TestCase
{
    #[Test]
    public function pushThenPopReturnsSameItem(): void
    {
        $channel = new FiberChannel(capacity: 4);
        $item = new stdClass();

        self::assertTrue($channel->push($item));
        self::assertSame($item, $channel->pop(Duration::zero()));
    }

    #[Test]
    public function popOnEmptyReturnsNullImmediately(): void
    {
        $channel = new FiberChannel(capacity: 4);

        self::assertNull($channel->pop(Duration::seconds(1)));
    }

    #[Test]
    public function pushAtCapacityReturnsFalse(): void
    {
        $channel = new FiberChannel(capacity: 1);

        self::assertTrue($channel->push(new stdClass()));
        self::assertFalse($channel->push(new stdClass()));
    }

    #[Test]
    public function fifoOrdering(): void
    {
        $channel = new FiberChannel(capacity: 4);
        $a = new stdClass();
        $b = new stdClass();
        $channel->push($a);
        $channel->push($b);

        self::assertSame($a, $channel->pop(Duration::zero()));
        self::assertSame($b, $channel->pop(Duration::zero()));
    }

    #[Test]
    public function closeDrainsPushers(): void
    {
        $channel = new FiberChannel(capacity: 4);
        $channel->push(new stdClass());

        $channel->close();

        self::assertTrue($channel->isClosed());
        self::assertNull($channel->pop(Duration::zero()));
        self::assertFalse($channel->push(new stdClass()));
    }
}
```

- [ ] **Step 2: Run, verify failure**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Pool/Channel/FiberChannelTest.php
```

- [ ] **Step 3: Implement the `Channel` interface**

`packages/nexus-doctrine-dbal/src/Pool/Channel/Channel.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool\Channel;

use Monadial\Nexus\Core\Duration;

/**
 * @template T of object
 * @psalm-api
 */
interface Channel
{
    /**
     * @param T $item
     */
    public function push(object $item): bool;

    /**
     * @return T|null
     */
    public function pop(Duration $timeout): ?object;

    public function size(): int;

    public function close(): void;

    public function isClosed(): bool;
}
```

- [ ] **Step 4: Implement `FiberChannel`**

`packages/nexus-doctrine-dbal/src/Pool/Channel/FiberChannel.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool\Channel;

use Monadial\Nexus\Core\Duration;
use Override;
use SplQueue;

/**
 * Non-blocking, single-fiber channel backed by SplQueue. `pop()` ignores
 * the timeout — under FiberRuntime PDO will block the fiber anyway, so
 * adding a coroutine-style suspend here is pointless.
 *
 * @template T of object
 * @template-implements Channel<T>
 * @psalm-api
 */
final class FiberChannel implements Channel
{
    /** @var SplQueue<T> */
    private SplQueue $queue;
    private bool $closed = false;

    public function __construct(private readonly int $capacity)
    {
        $this->queue = new SplQueue();
    }

    #[Override]
    public function push(object $item): bool
    {
        if ($this->closed) {
            return false;
        }

        if ($this->queue->count() >= $this->capacity) {
            return false;
        }

        $this->queue->enqueue($item);

        return true;
    }

    #[Override]
    public function pop(Duration $timeout): ?object
    {
        if ($this->queue->isEmpty()) {
            return null;
        }

        return $this->queue->dequeue();
    }

    #[Override]
    public function size(): int
    {
        return $this->queue->count();
    }

    #[Override]
    public function close(): void
    {
        $this->closed = true;
    }

    #[Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }
}
```

- [ ] **Step 5: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Pool/Channel/FiberChannelTest.php
git add packages/nexus-doctrine-dbal/src/Pool/Channel/Channel.php packages/nexus-doctrine-dbal/src/Pool/Channel/FiberChannel.php packages/nexus-doctrine-dbal/tests/Unit/Pool/Channel/FiberChannelTest.php
git commit -m "feat(doctrine-dbal): add Channel interface + FiberChannel impl"
```

---

## Task 5: `SwooleChannel` implementation

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Pool/Channel/SwooleChannel.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Pool/Channel/SwooleChannelTest.php` (skipped if Swoole not loaded)

- [ ] **Step 1: Write the failing test (gated on Swoole)**

`packages/nexus-doctrine-dbal/tests/Unit/Pool/Channel/SwooleChannelTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool\Channel;

use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\SwooleChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use stdClass;

use function Swoole\Coroutine\run;

#[CoversClass(SwooleChannel::class)]
#[RequiresPhpExtension('swoole')]
final class SwooleChannelTest extends TestCase
{
    #[Test]
    public function pushPopRoundTrip(): void
    {
        run(function (): void {
            $channel = new SwooleChannel(capacity: 4);
            $item = new stdClass();

            self::assertTrue($channel->push($item));
            self::assertSame($item, $channel->pop(Duration::seconds(1)));
        });
    }

    #[Test]
    public function popSuspendsUntilPushFromAnotherCoroutine(): void
    {
        $received = null;
        run(function () use (&$received): void {
            $channel = new SwooleChannel(capacity: 4);
            $item = new stdClass();

            Coroutine::create(function () use ($channel, $item): void {
                Coroutine::sleep(0.01);
                $channel->push($item);
            });

            $received = $channel->pop(Duration::seconds(1));
        });

        self::assertNotNull($received);
    }

    #[Test]
    public function popReturnsNullOnTimeout(): void
    {
        $result = 'unset';
        run(function () use (&$result): void {
            $channel = new SwooleChannel(capacity: 4);
            $result = $channel->pop(Duration::nanos(1_000_000));
        });

        self::assertNull($result);
    }
}
```

- [ ] **Step 2: Run, verify failure**

```bash
docker compose exec -T php-swoole vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Pool/Channel/SwooleChannelTest.php
```

- [ ] **Step 3: Implement `SwooleChannel`**

`packages/nexus-doctrine-dbal/src/Pool/Channel/SwooleChannel.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool\Channel;

use Monadial\Nexus\Core\Duration;
use Override;
use Swoole\Coroutine\Channel as SwooleCoroutineChannel;

/**
 * Coroutine-aware bounded channel. `pop()` suspends the current coroutine
 * until either an item is pushed or the timeout elapses.
 *
 * @template T of object
 * @template-implements Channel<T>
 * @psalm-api
 */
final class SwooleChannel implements Channel
{
    private SwooleCoroutineChannel $channel;

    public function __construct(int $capacity)
    {
        $this->channel = new SwooleCoroutineChannel($capacity);
    }

    #[Override]
    public function push(object $item): bool
    {
        return $this->channel->push($item, 0.0) === true;
    }

    #[Override]
    public function pop(Duration $timeout): ?object
    {
        $secondsFloat = $timeout->toSecondsFloat();
        $result = $this->channel->pop($secondsFloat);

        if ($result === false) {
            return null;
        }

        return $result;
    }

    #[Override]
    public function size(): int
    {
        return $this->channel->length();
    }

    #[Override]
    public function close(): void
    {
        $this->channel->close();
    }

    #[Override]
    public function isClosed(): bool
    {
        return $this->channel->errCode === SWOOLE_CHANNEL_CLOSED;
    }
}
```

- [ ] **Step 4: Verify pass + commit**

```bash
docker compose exec -T php-swoole vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Pool/Channel/SwooleChannelTest.php
git add packages/nexus-doctrine-dbal/src/Pool/Channel/SwooleChannel.php packages/nexus-doctrine-dbal/tests/Unit/Pool/Channel/SwooleChannelTest.php
git commit -m "feat(doctrine-dbal): add SwooleChannel coroutine impl"
```

---

## Task 6: Exception hierarchy

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Exception/PoolExhaustedException.php`
- Create: `packages/nexus-doctrine-dbal/src/Exception/PoolClosedException.php`
- Create: `packages/nexus-doctrine-dbal/src/Exception/ConnectionPoisonedException.php`
- Create: `packages/nexus-doctrine-dbal/src/Exception/MissingConnectionScopeException.php`
- Create: `packages/nexus-doctrine-dbal/src/Exception/MissingTransactionalDependencyException.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Exception/ExceptionHierarchyTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Exception;

use Monadial\Nexus\Core\Exception\NexusException;
use Monadial\Nexus\Doctrine\Dbal\Exception\ConnectionPoisonedException;
use Monadial\Nexus\Doctrine\Dbal\Exception\MissingConnectionScopeException;
use Monadial\Nexus\Doctrine\Dbal\Exception\MissingTransactionalDependencyException;
use Monadial\Nexus\Doctrine\Dbal\Exception\PoolClosedException;
use Monadial\Nexus\Doctrine\Dbal\Exception\PoolExhaustedException;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PoolExhaustedException::class)]
#[CoversClass(PoolClosedException::class)]
#[CoversClass(ConnectionPoisonedException::class)]
#[CoversClass(MissingConnectionScopeException::class)]
#[CoversClass(MissingTransactionalDependencyException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    #[Test]
    public function poolExhaustedCarriesStats(): void
    {
        $stats = PoolStats::empty();
        $e = PoolExhaustedException::after('orders', $stats);

        self::assertInstanceOf(NexusException::class, $e);
        self::assertSame($stats, $e->stats);
        self::assertStringContainsString('orders', $e->getMessage());
    }

    #[Test]
    public function poolClosedExtendsNexus(): void
    {
        self::assertInstanceOf(NexusException::class, new PoolClosedException('orders'));
    }

    #[Test]
    public function connectionPoisonedExtendsNexus(): void
    {
        self::assertInstanceOf(NexusException::class, new ConnectionPoisonedException('cause'));
    }

    #[Test]
    public function missingConnectionScopeMessageHintsAtMiddleware(): void
    {
        $e = new MissingConnectionScopeException();
        self::assertStringContainsString('ConnectionScopeMiddleware', $e->getMessage());
    }

    #[Test]
    public function missingTransactionalDependencyMessage(): void
    {
        $e = MissingTransactionalDependencyException::onHandler('App\\CreateOrder');
        self::assertStringContainsString('App\\CreateOrder', $e->getMessage());
    }
}
```

- [ ] **Step 2: Run, verify failure**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Exception/ExceptionHierarchyTest.php
```

- [ ] **Step 3: Implement all five exceptions**

`packages/nexus-doctrine-dbal/src/Exception/PoolExhaustedException.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Exception;

use Monadial\Nexus\Core\Exception\NexusException;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolStats;

/**
 * @psalm-api
 */
final class PoolExhaustedException extends NexusException
{
    private function __construct(
        public readonly string $poolName,
        public readonly PoolStats $stats,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function after(string $poolName, PoolStats $stats): self
    {
        return new self(
            $poolName,
            $stats,
            sprintf(
                'Connection pool "%s" exhausted: %d in-use of %d (waiting=%d)',
                $poolName,
                $stats->inUse,
                $stats->total,
                $stats->waitingCoroutines,
            ),
        );
    }
}
```

`packages/nexus-doctrine-dbal/src/Exception/PoolClosedException.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 */
final class PoolClosedException extends NexusException
{
    public function __construct(string $poolName)
    {
        parent::__construct(sprintf('Connection pool "%s" is closed', $poolName));
    }
}
```

`packages/nexus-doctrine-dbal/src/Exception/ConnectionPoisonedException.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Exception;

use Monadial\Nexus\Core\Exception\NexusException;
use Throwable;

/**
 * @psalm-api
 */
final class ConnectionPoisonedException extends NexusException
{
    public function __construct(string $reason, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Connection poisoned: %s', $reason), 0, $previous);
    }
}
```

`packages/nexus-doctrine-dbal/src/Exception/MissingConnectionScopeException.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 */
final class MissingConnectionScopeException extends NexusException
{
    public function __construct()
    {
        parent::__construct(
            'No ConnectionLease found on the request. Did you install ConnectionScopeMiddleware in the HTTP pipeline?',
        );
    }
}
```

`packages/nexus-doctrine-dbal/src/Exception/MissingTransactionalDependencyException.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 */
final class MissingTransactionalDependencyException extends NexusException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function onHandler(string $handlerClass): self
    {
        return new self(sprintf(
            '#[Transactional] requires the handler "%s" to declare a Connection (or EntityManagerInterface) parameter.',
            $handlerClass,
        ));
    }
}
```

- [ ] **Step 4: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Exception/ExceptionHierarchyTest.php
git add packages/nexus-doctrine-dbal/src/Exception/ packages/nexus-doctrine-dbal/tests/Unit/Exception/
git commit -m "feat(doctrine-dbal): add exception hierarchy"
```

---

## Task 7: PSR-14 events

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Event/ConnectionCreated.php`
- Create: `packages/nexus-doctrine-dbal/src/Event/ConnectionDestroyed.php`
- Create: `packages/nexus-doctrine-dbal/src/Event/ConnectionPoisoned.php`
- Create: `packages/nexus-doctrine-dbal/src/Event/ConnectionTaken.php`
- Create: `packages/nexus-doctrine-dbal/src/Event/ConnectionReleased.php`
- Create: `packages/nexus-doctrine-dbal/src/Event/PoolExhausted.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Event/EventShapeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Event;

use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionCreated;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionDestroyed;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionPoisoned;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionReleased;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionTaken;
use Monadial\Nexus\Doctrine\Dbal\Event\PoolExhausted;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionCreated::class)]
#[CoversClass(ConnectionDestroyed::class)]
#[CoversClass(ConnectionPoisoned::class)]
#[CoversClass(ConnectionTaken::class)]
#[CoversClass(ConnectionReleased::class)]
#[CoversClass(PoolExhausted::class)]
final class EventShapeTest extends TestCase
{
    #[Test]
    public function connectionTakenCarriesWaitTime(): void
    {
        $e = new ConnectionTaken('orders', Duration::millis(7));
        self::assertSame('orders', $e->poolName);
        self::assertTrue($e->waitTime->equals(Duration::millis(7)));
    }

    #[Test]
    public function connectionReleasedCarriesHeldFor(): void
    {
        $e = new ConnectionReleased('orders', Duration::millis(42));
        self::assertTrue($e->heldFor->equals(Duration::millis(42)));
    }

    #[Test]
    public function poolExhaustedCarriesStats(): void
    {
        $stats = PoolStats::empty();
        $e = new PoolExhausted('orders', $stats);
        self::assertSame($stats, $e->stats);
    }

    #[Test]
    public function lifecycleEventsCarryPoolName(): void
    {
        self::assertSame('o', (new ConnectionCreated('o'))->poolName);
        self::assertSame('o', (new ConnectionDestroyed('o'))->poolName);
        self::assertSame('o', (new ConnectionPoisoned('o', 'reason'))->poolName);
    }
}
```

- [ ] **Step 2: Run, verify failure + implement events**

`packages/nexus-doctrine-dbal/src/Event/ConnectionCreated.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Event;

/** @psalm-api */
final readonly class ConnectionCreated
{
    public function __construct(public string $poolName) {}
}
```

`packages/nexus-doctrine-dbal/src/Event/ConnectionDestroyed.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Event;

/** @psalm-api */
final readonly class ConnectionDestroyed
{
    public function __construct(public string $poolName) {}
}
```

`packages/nexus-doctrine-dbal/src/Event/ConnectionPoisoned.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Event;

/** @psalm-api */
final readonly class ConnectionPoisoned
{
    public function __construct(
        public string $poolName,
        public string $reason,
    ) {}
}
```

`packages/nexus-doctrine-dbal/src/Event/ConnectionTaken.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Event;

use Monadial\Nexus\Core\Duration;

/** @psalm-api */
final readonly class ConnectionTaken
{
    public function __construct(
        public string $poolName,
        public Duration $waitTime,
    ) {}
}
```

`packages/nexus-doctrine-dbal/src/Event/ConnectionReleased.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Event;

use Monadial\Nexus\Core\Duration;

/** @psalm-api */
final readonly class ConnectionReleased
{
    public function __construct(
        public string $poolName,
        public Duration $heldFor,
    ) {}
}
```

`packages/nexus-doctrine-dbal/src/Event/PoolExhausted.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Event;

use Monadial\Nexus\Doctrine\Dbal\Pool\PoolStats;

/** @psalm-api */
final readonly class PoolExhausted
{
    public function __construct(
        public string $poolName,
        public PoolStats $stats,
    ) {}
}
```

- [ ] **Step 3: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Event/EventShapeTest.php
git add packages/nexus-doctrine-dbal/src/Event/ packages/nexus-doctrine-dbal/tests/Unit/Event/
git commit -m "feat(doctrine-dbal): add PSR-14 pool lifecycle events"
```

---

## Task 8: `ConnectionFactory` interface + `DriverManagerConnectionFactory`

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Pool/ConnectionFactory.php`
- Create: `packages/nexus-doctrine-dbal/src/Pool/DriverManagerConnectionFactory.php`
- Create: `packages/nexus-doctrine-dbal/tests/Support/StubConnectionFactory.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Pool/DriverManagerConnectionFactoryTest.php`

- [ ] **Step 1: Write the failing test**

`packages/nexus-doctrine-dbal/tests/Unit/Pool/DriverManagerConnectionFactoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Pool\DriverManagerConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DriverManagerConnectionFactory::class)]
final class DriverManagerConnectionFactoryTest extends TestCase
{
    #[Test]
    public function createReturnsConnection(): void
    {
        $factory = new DriverManagerConnectionFactory(['url' => 'sqlite3:///:memory:']);

        $conn = $factory->create();

        self::assertInstanceOf(Connection::class, $conn);
        $conn->close();
    }

    #[Test]
    public function eachCallReturnsFreshInstance(): void
    {
        $factory = new DriverManagerConnectionFactory(['url' => 'sqlite3:///:memory:']);

        $a = $factory->create();
        $b = $factory->create();

        self::assertNotSame($a, $b);
        $a->close();
        $b->close();
    }
}
```

- [ ] **Step 2: Run, verify failure + implement**

`packages/nexus-doctrine-dbal/src/Pool/ConnectionFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool;

use Doctrine\DBAL\Connection;

/**
 * @psalm-api
 */
interface ConnectionFactory
{
    public function create(): Connection;
}
```

`packages/nexus-doctrine-dbal/src/Pool/DriverManagerConnectionFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Override;

/**
 * @psalm-api
 */
final class DriverManagerConnectionFactory implements ConnectionFactory
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        private readonly array $params,
        private readonly ?Configuration $config = null,
    ) {}

    #[Override]
    public function create(): Connection
    {
        /** @psalm-suppress ArgumentTypeCoercion */
        return DriverManager::getConnection($this->params, $this->config);
    }
}
```

- [ ] **Step 3: Add test-support stub**

`packages/nexus-doctrine-dbal/tests/Support/StubConnectionFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Support;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionFactory;
use Override;
use PHPUnit\Framework\MockObject\Generator\Generator;
use RuntimeException;

/**
 * Returns mock Connection instances. Tracks the number of creations and
 * allows tests to inject pre-prepared mocks.
 *
 * @psalm-api
 */
final class StubConnectionFactory implements ConnectionFactory
{
    /** @var list<Connection> */
    private array $prepared = [];
    public int $creations = 0;

    public function prepend(Connection $conn): void
    {
        $this->prepared[] = $conn;
    }

    #[Override]
    public function create(): Connection
    {
        $this->creations++;

        if ($this->prepared !== []) {
            return array_shift($this->prepared);
        }

        /** @var Connection $mock */
        $mock = (new Generator())->testDouble(
            type: Connection::class,
            mockObject: true,
            markAsMockObject: true,
        );

        return $mock;
    }

    public function exhaustOrFail(): void
    {
        if ($this->prepared !== []) {
            throw new RuntimeException('Prepared connections not all consumed');
        }
    }
}
```

- [ ] **Step 4: Update `phpunit.xml.dist` to include `tests/Support` in autoload**

(autoload-dev already covers it via PSR-4 in `composer.json`; no change needed.)

- [ ] **Step 5: Verify pass + commit**

```bash
docker compose exec -T php-fiber composer dump-autoload
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Pool/DriverManagerConnectionFactoryTest.php
git add packages/nexus-doctrine-dbal/src/Pool/ConnectionFactory.php packages/nexus-doctrine-dbal/src/Pool/DriverManagerConnectionFactory.php packages/nexus-doctrine-dbal/tests/Support/StubConnectionFactory.php packages/nexus-doctrine-dbal/tests/Unit/Pool/DriverManagerConnectionFactoryTest.php
git commit -m "feat(doctrine-dbal): add ConnectionFactory + DriverManager impl"
```

---

## Task 9: `ConnectionPool` — take / release happy path (no blocking)

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Pool/ConnectionPool.php` (initial cut)
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Pool/ConnectionPoolTest.php`

This task implements the **non-blocking** core: lazy creation, return-to-idle, stats. Blocking (waiting on channel when `total == max`) comes in Task 10.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool;

use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionPool::class)]
final class ConnectionPoolTest extends TestCase
{
    #[Test]
    public function takeLazilyCreatesUpToMax(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 3, minIdle: 0),
            channel: new FiberChannel(3),
        );

        $a = $pool->take();
        $b = $pool->take();
        $c = $pool->take();

        self::assertSame(3, $factory->creations);
        self::assertSame(3, $pool->stats()->inUse);
        self::assertSame(0, $pool->stats()->idle);
        self::assertSame(3, $pool->stats()->total);
        self::assertSame(3, $pool->stats()->totalBorrows);

        $pool->release($a);
        $pool->release($b);
        $pool->release($c);
    }

    #[Test]
    public function releasedConnectionsAreReused(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 2, minIdle: 0),
            channel: new FiberChannel(2),
        );

        $a = $pool->take();
        $pool->release($a);
        $b = $pool->take();

        self::assertSame($a, $b);
        self::assertSame(1, $factory->creations);
        $pool->release($b);
    }

    #[Test]
    public function releaseWithPoisonDestroysConnection(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 2, minIdle: 0),
            channel: new FiberChannel(2),
        );

        $a = $pool->take();
        $pool->release($a, poison: true);

        self::assertSame(0, $pool->stats()->total);
        self::assertSame(0, $pool->stats()->idle);

        $b = $pool->take();
        self::assertNotSame($a, $b);
        self::assertSame(2, $factory->creations);
        $pool->release($b);
    }

    #[Test]
    public function withConnectionReleasesOnSuccess(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        $result = $pool->withConnection(static fn(): string => 'ok');

        self::assertSame('ok', $result);
        self::assertSame(0, $pool->stats()->inUse);
        self::assertSame(1, $pool->stats()->idle);
    }

    #[Test]
    public function withConnectionPoisonsOnThrow(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        try {
            $pool->withConnection(static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('expected throw');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame(0, $pool->stats()->total);
        self::assertSame(0, $pool->stats()->idle);
    }
}
```

- [ ] **Step 2: Run, verify failure**

- [ ] **Step 3: Implement `ConnectionPool` (initial cut — no blocking yet)**

`packages/nexus-doctrine-dbal/src/Pool/ConnectionPool.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool;

use Closure;
use Doctrine\DBAL\Connection;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionCreated;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionDestroyed;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionPoisoned as ConnectionPoisonedEvent;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionReleased;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionTaken;
use Monadial\Nexus\Doctrine\Dbal\Event\PoolExhausted as PoolExhaustedEvent;
use Monadial\Nexus\Doctrine\Dbal\Exception\PoolClosedException;
use Monadial\Nexus\Doctrine\Dbal\Exception\PoolExhaustedException;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\Channel;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SplObjectStorage;
use Throwable;

/**
 * @psalm-api
 *
 * @psalm-type BorrowMeta = array{takenAt: int}
 */
final class ConnectionPool
{
    /** @var Channel<Connection> */
    private Channel $idle;

    /** @var SplObjectStorage<Connection, BorrowMeta> */
    private SplObjectStorage $inUse;

    private int $total = 0;
    private int $totalBorrows = 0;
    private int $totalWaits = 0;
    private int $totalTimeouts = 0;
    private int $waitingCoroutines = 0;
    private bool $closed = false;

    public function __construct(
        private readonly string $name,
        private readonly ConnectionFactory $factory,
        private readonly PoolConfig $config,
        Channel $channel,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->idle = $channel;
        $this->inUse = new SplObjectStorage();
    }

    public function take(?Duration $timeout = null): Connection
    {
        if ($this->closed) {
            throw new PoolClosedException($this->name);
        }

        $startNanos = hrtime(true);
        $existing = $this->idle->pop(Duration::zero());

        if ($existing !== null) {
            return $this->markBorrowed($existing, $startNanos);
        }

        if ($this->total < $this->config->max) {
            $created = $this->factory->create();
            $this->total++;
            $this->events?->dispatch(new ConnectionCreated($this->name));

            return $this->markBorrowed($created, $startNanos);
        }

        return $this->waitForRelease($timeout ?? $this->config->borrowTimeout, $startNanos);
    }

    public function release(Connection $conn, bool $poison = false): void
    {
        if (!$this->inUse->contains($conn)) {
            return;
        }

        $meta = $this->inUse[$conn];
        $heldNanos = hrtime(true) - $meta['takenAt'];
        $this->inUse->detach($conn);

        if ($poison || $this->closed) {
            $this->total--;
            $this->safeClose($conn);
            $this->events?->dispatch(new ConnectionPoisonedEvent($this->name, $poison ? 'caller' : 'closed-pool'));
            $this->events?->dispatch(new ConnectionDestroyed($this->name));

            return;
        }

        $accepted = $this->idle->push($conn);

        if (!$accepted) {
            $this->total--;
            $this->safeClose($conn);
            $this->events?->dispatch(new ConnectionDestroyed($this->name));

            return;
        }

        $this->events?->dispatch(new ConnectionReleased($this->name, Duration::nanos($heldNanos)));
    }

    /**
     * @template T
     * @param Closure(Connection): T $fn
     * @return T
     */
    public function withConnection(Closure $fn): mixed
    {
        $conn = $this->take();
        $poison = false;

        try {
            return $fn($conn);
        } catch (Throwable $e) {
            $poison = true;
            throw $e;
        } finally {
            $this->release($conn, poison: $poison);
        }
    }

    public function close(Duration $timeout): void
    {
        $this->closed = true;
        $this->idle->close();

        $drained = $this->idle->pop(Duration::zero());

        while ($drained !== null) {
            $this->total--;
            $this->safeClose($drained);
            $this->events?->dispatch(new ConnectionDestroyed($this->name));
            $drained = $this->idle->pop(Duration::zero());
        }
    }

    public function stats(): PoolStats
    {
        return new PoolStats(
            idle: $this->idle->size(),
            inUse: $this->inUse->count(),
            total: $this->total,
            waitingCoroutines: $this->waitingCoroutines,
            totalBorrows: $this->totalBorrows,
            totalWaits: $this->totalWaits,
            totalTimeouts: $this->totalTimeouts,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    private function markBorrowed(Connection $conn, int $startNanos): Connection
    {
        /** @var BorrowMeta $meta */
        $meta = ['takenAt' => hrtime(true)];
        $this->inUse[$conn] = $meta;
        $this->totalBorrows++;

        $waitNanos = hrtime(true) - $startNanos;
        $this->events?->dispatch(new ConnectionTaken($this->name, Duration::nanos($waitNanos)));

        return $conn;
    }

    private function waitForRelease(Duration $timeout, int $startNanos): Connection
    {
        $this->totalWaits++;
        $this->waitingCoroutines++;

        try {
            $waited = $this->idle->pop($timeout);
        } finally {
            $this->waitingCoroutines--;
        }

        if ($waited === null) {
            $this->totalTimeouts++;
            $stats = $this->stats();
            $this->events?->dispatch(new PoolExhaustedEvent($this->name, $stats));

            throw PoolExhaustedException::after($this->name, $stats);
        }

        return $this->markBorrowed($waited, $startNanos);
    }

    private function safeClose(Connection $conn): void
    {
        try {
            $conn->close();
        } catch (Throwable $e) {
            $this->logger->warning('Failed to close connection cleanly: {error}', ['error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 4: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Pool/ConnectionPoolTest.php
git add packages/nexus-doctrine-dbal/src/Pool/ConnectionPool.php packages/nexus-doctrine-dbal/tests/Unit/Pool/ConnectionPoolTest.php
git commit -m "feat(doctrine-dbal): ConnectionPool take/release/withConnection happy path"
```

---

## Task 10: `ConnectionPool` — exhaustion (timeout) + close drain

**Files:**
- Modify: `packages/nexus-doctrine-dbal/tests/Unit/Pool/ConnectionPoolTest.php`
- Modify: `packages/nexus-doctrine-dbal/src/Pool/ConnectionPool.php` (no changes needed — Task 9 already covers exhaustion via `waitForRelease`; this task verifies it under `FiberChannel`'s non-blocking semantics).

Under `FiberChannel`, `pop()` returns `null` immediately when empty regardless of timeout — so exhaustion is the same as "empty channel + at-cap". Verify the exception path fires.

- [ ] **Step 1: Append exhaustion + close tests**

Add to `packages/nexus-doctrine-dbal/tests/Unit/Pool/ConnectionPoolTest.php`:

```php
    #[Test]
    public function takeThrowsWhenAtMaxAndChannelEmpty(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(borrowTimeout: Duration::millis(1), max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        $held = $pool->take();
        $this->expectException(PoolExhaustedException::class);

        try {
            $pool->take();
        } finally {
            $pool->release($held);
        }
    }

    #[Test]
    public function statsCountTimeouts(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(borrowTimeout: Duration::millis(1), max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        $held = $pool->take();

        try {
            $pool->take();
        } catch (PoolExhaustedException) {
            // expected
        }

        self::assertSame(1, $pool->stats()->totalTimeouts);
        self::assertSame(1, $pool->stats()->totalWaits);
        $pool->release($held);
    }

    #[Test]
    public function closeDrainsIdleConnections(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 3, minIdle: 0),
            channel: new FiberChannel(3),
        );

        $a = $pool->take();
        $b = $pool->take();
        $pool->release($a);
        $pool->release($b);

        $pool->close(Duration::seconds(1));

        self::assertSame(0, $pool->stats()->idle);
        self::assertSame(0, $pool->stats()->total);
    }

    #[Test]
    public function takeAfterCloseThrows(): void
    {
        $pool = new ConnectionPool(
            name: 'orders',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );
        $pool->close(Duration::seconds(1));

        $this->expectException(PoolClosedException::class);
        $pool->take();
    }
```

Add the imports at the top of the test file:
```php
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Dbal\Exception\PoolClosedException;
use Monadial\Nexus\Doctrine\Dbal\Exception\PoolExhaustedException;
```

- [ ] **Step 2: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Pool/ConnectionPoolTest.php
git add packages/nexus-doctrine-dbal/tests/Unit/Pool/ConnectionPoolTest.php
git commit -m "test(doctrine-dbal): cover ConnectionPool exhaustion and close"
```

---

## Task 11: `Evictor` background task

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Pool/Evictor.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Pool/EvictorTest.php`
- Modify: `packages/nexus-doctrine-dbal/src/Pool/ConnectionPool.php` — expose `idleAges()`, `evictIdle()`, and `warmTo(int)` methods used by the evictor.

The evictor is decoupled from the pool so it's testable without timers. It receives a pool, looks at idle connections older than `idleTtl`, destroys them, and re-warms up to `minIdle`. Under Swoole it runs as a coroutine on a timer; under Fiber it's started via `FiberScheduler::scheduleRepeatedly()`. We test the pure tick logic.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool;

use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\Evictor;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Evictor::class)]
final class EvictorTest extends TestCase
{
    #[Test]
    public function evictsConnectionsOlderThanIdleTtl(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(idleTtl: Duration::nanos(1), max: 3, minIdle: 0),
            channel: new FiberChannel(3),
        );

        $a = $pool->take();
        $pool->release($a);
        self::assertSame(1, $pool->stats()->idle);

        $evictor = new Evictor();
        $evictor->tick($pool, now: hrtime(true) + 1_000_000_000);

        self::assertSame(0, $pool->stats()->idle);
        self::assertSame(0, $pool->stats()->total);
    }

    #[Test]
    public function warmsBackToMinIdleAfterEviction(): void
    {
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(idleTtl: Duration::nanos(1), max: 5, minIdle: 2),
            channel: new FiberChannel(5),
        );
        $a = $pool->take();
        $pool->release($a);

        (new Evictor())->tick($pool, now: hrtime(true) + 1_000_000_000);

        self::assertSame(2, $pool->stats()->idle);
        self::assertSame(2, $pool->stats()->total);
    }
}
```

- [ ] **Step 2: Run, verify failure + implement**

`packages/nexus-doctrine-dbal/src/Pool/Evictor.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool;

/**
 * @psalm-api
 */
final class Evictor
{
    public function tick(ConnectionPool $pool, ?int $now = null): void
    {
        $pool->evictIdleOlderThan($now ?? hrtime(true));
        $pool->warmToMinIdle();
    }
}
```

Add to `ConnectionPool`:
```php
public function evictIdleOlderThan(int $cutoffNanos): void
{
    $kept = [];

    while (($next = $this->idle->pop(Duration::zero())) !== null) {
        $age = $cutoffNanos - $this->idleSince[spl_object_id($next)] ?? 0;
        if ($age >= $this->config->idleTtl->toNanos()) {
            $this->total--;
            $this->safeClose($next);
            $this->events?->dispatch(new ConnectionDestroyed($this->name));
            unset($this->idleSince[spl_object_id($next)]);
            continue;
        }
        $kept[] = $next;
    }

    foreach ($kept as $conn) {
        $this->idle->push($conn);
    }
}

public function warmToMinIdle(): void
{
    while ($this->total < $this->config->minIdle) {
        $fresh = $this->factory->create();
        $this->total++;
        $this->idleSince[spl_object_id($fresh)] = hrtime(true);
        $this->events?->dispatch(new ConnectionCreated($this->name));

        if (!$this->idle->push($fresh)) {
            // Channel is closed or full — destroy and back off.
            $this->total--;
            $this->safeClose($fresh);
            $this->events?->dispatch(new ConnectionDestroyed($this->name));

            return;
        }
    }
}
```

Add a property:
```php
/** @var array<int, int> */
private array $idleSince = [];
```

And update `release()` to stamp `idleSince` on accept, and `take()` to clear it on hand-out:

In `markBorrowed()`:
```php
unset($this->idleSince[spl_object_id($conn)]);
```

In `release()` after `$accepted = $this->idle->push($conn);`:
```php
if ($accepted) {
    $this->idleSince[spl_object_id($conn)] = hrtime(true);
}
```

- [ ] **Step 3: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Pool/EvictorTest.php
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Pool/ConnectionPoolTest.php
git add packages/nexus-doctrine-dbal/src/Pool/Evictor.php packages/nexus-doctrine-dbal/src/Pool/ConnectionPool.php packages/nexus-doctrine-dbal/tests/Unit/Pool/EvictorTest.php
git commit -m "feat(doctrine-dbal): add idle-connection evictor"
```

---

## Task 12: `LeakDetector` background task

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Pool/LeakDetector.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Pool/LeakDetectorTest.php`

The leak detector inspects in-use entries older than `acquireTtl` and emits PSR-3 `warning` for each.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool;

use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\LeakDetector;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

#[CoversClass(LeakDetector::class)]
final class LeakDetectorTest extends TestCase
{
    #[Test]
    public function warnsOnBorrowOlderThanAcquireTtl(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(acquireTtl: Duration::nanos(1), max: 2, minIdle: 0),
            channel: new FiberChannel(2),
            logger: $logger,
        );
        $held = $pool->take();

        (new LeakDetector())->tick($pool, now: hrtime(true) + 1_000_000_000);

        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('orders', $logger->warnings[0]);
        $pool->release($held);
    }
}
```

- [ ] **Step 2: Run, verify failure + implement**

`packages/nexus-doctrine-dbal/src/Pool/LeakDetector.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool;

/**
 * @psalm-api
 */
final class LeakDetector
{
    public function tick(ConnectionPool $pool, ?int $now = null): void
    {
        $pool->warnOnLeaks($now ?? hrtime(true));
    }
}
```

Add to `ConnectionPool`:
```php
public function warnOnLeaks(int $nowNanos): void
{
    /** @var Connection $conn */
    foreach ($this->inUse as $conn) {
        $meta = $this->inUse[$conn];
        $ageNanos = $nowNanos - $meta['takenAt'];

        if ($ageNanos < $this->config->acquireTtl->toNanos()) {
            continue;
        }

        $this->logger->warning('Connection borrow in pool "{pool}" held for {ageMs}ms (acquireTtl exceeded)', [
            'ageMs' => intdiv($ageNanos, 1_000_000),
            'pool' => $this->name,
        ]);
    }
}
```

- [ ] **Step 3: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Pool/LeakDetectorTest.php
git add packages/nexus-doctrine-dbal/src/Pool/LeakDetector.php packages/nexus-doctrine-dbal/src/Pool/ConnectionPool.php packages/nexus-doctrine-dbal/tests/Unit/Pool/LeakDetectorTest.php
git commit -m "feat(doctrine-dbal): add leak-detection tick"
```

---

## Task 13: `DoctrineBootstrap` + `DoctrinePool::fromUrl()` facade

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Bootstrap/DoctrineBootstrap.php`
- Create: `packages/nexus-doctrine-dbal/src/DoctrinePool.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Bootstrap/DoctrineBootstrapTest.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/DoctrinePoolTest.php`

- [ ] **Step 1: Write the failing tests**

`packages/nexus-doctrine-dbal/tests/Unit/Bootstrap/DoctrineBootstrapTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Bootstrap;

use Monadial\Nexus\Doctrine\Dbal\Bootstrap\DoctrineBootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineBootstrap::class)]
final class DoctrineBootstrapTest extends TestCase
{
    #[Test]
    public function enableUnderFiberRuntimeIsNoOp(): void
    {
        if (extension_loaded('swoole')) {
            self::markTestSkipped('Swoole present — covered by integration suite');
        }

        DoctrineBootstrap::enable();

        self::assertFalse(DoctrineBootstrap::isEnabled() && !extension_loaded('swoole'));
    }
}
```

`packages/nexus-doctrine-dbal/tests/Unit/DoctrinePoolTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit;

use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrinePool::class)]
final class DoctrinePoolTest extends TestCase
{
    #[Test]
    public function fromUrlBuildsAPool(): void
    {
        $pool = DoctrinePool::fromUrl(
            name: 'orders',
            url: 'sqlite3:///:memory:',
            config: new PoolConfig(max: 2, minIdle: 0),
        );

        self::assertInstanceOf(ConnectionPool::class, $pool);
        $conn = $pool->take();
        self::assertNotNull($conn);
        $pool->release($conn);
    }
}
```

- [ ] **Step 2: Run, verify failure**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Bootstrap/DoctrineBootstrapTest.php packages/nexus-doctrine-dbal/tests/Unit/DoctrinePoolTest.php
```

- [ ] **Step 3: Implement `DoctrineBootstrap`**

`packages/nexus-doctrine-dbal/src/Bootstrap/DoctrineBootstrap.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Bootstrap;

/**
 * Enables Swoole coroutine hooks for Doctrine DBAL drivers. Idempotent.
 * No-op when Swoole is not loaded (e.g. under Fiber dev runtime).
 *
 * @psalm-api
 */
final class DoctrineBootstrap
{
    private static bool $enabled = false;

    public static function enable(): void
    {
        if (self::$enabled) {
            return;
        }

        if (!extension_loaded('swoole')) {
            self::$enabled = true;

            return;
        }

        /** @psalm-suppress UndefinedConstant */
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        self::$enabled = true;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
}
```

- [ ] **Step 4: Implement `DoctrinePool` facade**

`packages/nexus-doctrine-dbal/src/DoctrinePool.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal;

use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\SwooleChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\DriverManagerConnectionFactory;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Public facade. Constructs the right `Channel` based on extension presence.
 *
 * @psalm-api
 */
final class DoctrinePool
{
    /**
     * @param array<string, mixed> $extra
     */
    public static function fromUrl(
        string $name,
        string $url,
        ?PoolConfig $config = null,
        ?EventDispatcherInterface $events = null,
        ?LoggerInterface $logger = null,
        array $extra = [],
    ): ConnectionPool {
        $config ??= new PoolConfig();
        $factory = new DriverManagerConnectionFactory(['url' => $url] + $extra);
        $channel = extension_loaded('swoole')
            ? new SwooleChannel($config->max)
            : new FiberChannel($config->max);

        return new ConnectionPool(
            name: $name,
            factory: $factory,
            config: $config,
            channel: $channel,
            events: $events,
            logger: $logger ?? new NullLogger(),
        );
    }
}
```

- [ ] **Step 5: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Bootstrap/ packages/nexus-doctrine-dbal/tests/Unit/DoctrinePoolTest.php
git add packages/nexus-doctrine-dbal/src/Bootstrap/ packages/nexus-doctrine-dbal/src/DoctrinePool.php packages/nexus-doctrine-dbal/tests/Unit/Bootstrap/ packages/nexus-doctrine-dbal/tests/Unit/DoctrinePoolTest.php
git commit -m "feat(doctrine-dbal): add Swoole bootstrap + DoctrinePool::fromUrl facade"
```

---

## Task 14: `ConnectionLease`

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Http/ConnectionLease.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Http/ConnectionLeaseTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Http;

use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionLease;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionLease::class)]
final class ConnectionLeaseTest extends TestCase
{
    #[Test]
    public function getLazilyBorrowsFromPool(): void
    {
        $factory = new StubConnectionFactory();
        $pool = $this->pool($factory);
        $lease = new ConnectionLease($pool);

        self::assertSame(0, $pool->stats()->inUse);

        $conn = $lease->get();
        self::assertSame(1, $pool->stats()->inUse);

        $conn2 = $lease->get();
        self::assertSame($conn, $conn2);
        self::assertSame(1, $pool->stats()->inUse);

        $lease->release();
        self::assertSame(0, $pool->stats()->inUse);
    }

    #[Test]
    public function releaseWithoutGetIsNoOp(): void
    {
        $pool = $this->pool(new StubConnectionFactory());

        (new ConnectionLease($pool))->release();
        self::assertSame(0, $pool->stats()->inUse);
    }

    #[Test]
    public function poisonFlagPersistsThroughRelease(): void
    {
        $factory = new StubConnectionFactory();
        $pool = $this->pool($factory);
        $lease = new ConnectionLease($pool);

        $lease->get();
        $lease->poison();
        $lease->release();

        self::assertSame(0, $pool->stats()->total);
    }

    private function pool(StubConnectionFactory $factory): ConnectionPool
    {
        return new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 2, minIdle: 0),
            channel: new FiberChannel(2),
        );
    }
}
```

- [ ] **Step 2: Run, verify failure + implement**

`packages/nexus-doctrine-dbal/src/Http/ConnectionLease.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Http;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;

/**
 * @psalm-api
 */
final class ConnectionLease
{
    private ?Connection $conn = null;
    private bool $poisoned = false;

    public function __construct(private readonly ConnectionPool $pool) {}

    public function get(): Connection
    {
        return $this->conn ??= $this->pool->take();
    }

    public function poison(): void
    {
        $this->poisoned = true;
    }

    public function release(): void
    {
        if ($this->conn === null) {
            return;
        }

        $this->pool->release($this->conn, poison: $this->poisoned);
        $this->conn = null;
    }
}
```

- [ ] **Step 3: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Http/ConnectionLeaseTest.php
git add packages/nexus-doctrine-dbal/src/Http/ConnectionLease.php packages/nexus-doctrine-dbal/tests/Unit/Http/ConnectionLeaseTest.php
git commit -m "feat(doctrine-dbal): add ConnectionLease for per-request scope"
```

---

## Task 15: `ConnectionScopeMiddleware`

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Http/ConnectionScopeMiddleware.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Http/ConnectionScopeMiddlewareTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Http;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionLease;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionScopeMiddleware;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

#[CoversClass(ConnectionScopeMiddleware::class)]
final class ConnectionScopeMiddlewareTest extends TestCase
{
    #[Test]
    public function attachesLeaseAndReleasesAfterHandle(): void
    {
        $factory = new StubConnectionFactory();
        $pool = $this->pool($factory);
        $middleware = new ConnectionScopeMiddleware($pool);

        /** @var ?ConnectionLease $observed */
        $observed = null;
        $handler = new class ($observed) implements RequestHandlerInterface {
            public function __construct(public ?ConnectionLease &$observed) {}

            #[Override]
            public function handle(ServerRequestInterface $req): ResponseInterface
            {
                $this->observed = $req->getAttribute(ConnectionLease::class);
                self::assertInstanceOf(ConnectionLease::class, $this->observed);
                $this->observed->get();

                return new Response(200);
            }
        };

        $factory17 = new Psr17Factory();
        $request = $factory17->createServerRequest('GET', '/');
        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $pool->stats()->inUse);
        self::assertSame(1, $pool->stats()->idle);
    }

    #[Test]
    public function poisonsLeaseOnException(): void
    {
        $factory = new StubConnectionFactory();
        $pool = $this->pool($factory);
        $middleware = new ConnectionScopeMiddleware($pool);

        $handler = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $req): ResponseInterface
            {
                $req->getAttribute(ConnectionLease::class)->get();

                throw new RuntimeException('boom');
            }
        };

        $factory17 = new Psr17Factory();

        try {
            $middleware->process($factory17->createServerRequest('GET', '/'), $handler);
            self::fail('expected throw');
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(0, $pool->stats()->total);
    }

    private function pool(StubConnectionFactory $factory): ConnectionPool
    {
        return new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 2, minIdle: 0),
            channel: new FiberChannel(2),
        );
    }
}
```

- [ ] **Step 2: Run, verify failure + implement**

`packages/nexus-doctrine-dbal/src/Http/ConnectionScopeMiddleware.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Http;

use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * @psalm-api
 */
final readonly class ConnectionScopeMiddleware implements MiddlewareInterface
{
    public function __construct(private ConnectionPool $pool) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $lease = new ConnectionLease($this->pool);
        $request = $request->withAttribute(ConnectionLease::class, $lease);

        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            $lease->poison();

            throw $e;
        } finally {
            $lease->release();
        }
    }
}
```

- [ ] **Step 3: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Http/ConnectionScopeMiddlewareTest.php
git add packages/nexus-doctrine-dbal/src/Http/ConnectionScopeMiddleware.php packages/nexus-doctrine-dbal/tests/Unit/Http/ConnectionScopeMiddlewareTest.php
git commit -m "feat(doctrine-dbal): add ConnectionScopeMiddleware"
```

---

## Task 16: `ConnectionResolver` (`ParamResolver` impl)

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Http/ConnectionResolver.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Http/ConnectionResolverTest.php`

Integrates with the shared `ParamResolverRegistry` from `2026-06-15-handler-resolver-redesign-design.md`. The resolver fires on parameter type `Doctrine\DBAL\Connection`, looks up `ConnectionLease::class` from `HttpRequestContext`, and calls `->get()`.

- [ ] **Step 1: Inspect the existing `ParamResolver` interface to confirm signatures**

```bash
docker compose exec -T php-fiber cat packages/nexus-http/src/Handler/Resolver/ParamResolver.php packages/nexus-http/src/Handler/Resolver/HttpRequestContext.php
```

The signatures of `compile()` and `resolve()` are reused exactly. `compile()` runs once per handler class at boot; `resolve()` runs once per request.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Http;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Exception\MissingConnectionScopeException;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionLease;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionResolver;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

#[CoversClass(ConnectionResolver::class)]
final class ConnectionResolverTest extends TestCase
{
    #[Test]
    public function compileMatchesConnectionTypedParameter(): void
    {
        $resolver = new ConnectionResolver();
        $reflection = new ReflectionFunction(static function (Connection $c): void {});
        $param = $reflection->getParameters()[0];

        $metadata = $resolver->compile($param, new CompileContext('handler', new ResolverServices()));

        self::assertNotNull($metadata);
        self::assertSame($resolver, $metadata->resolver);
    }

    #[Test]
    public function compileSkipsNonConnectionParameter(): void
    {
        $resolver = new ConnectionResolver();
        $reflection = new ReflectionFunction(static function (int $i): void {});
        $param = $reflection->getParameters()[0];

        $metadata = $resolver->compile($param, new CompileContext('handler', new ResolverServices()));

        self::assertNull($metadata);
    }

    #[Test]
    public function resolveReturnsBorrowedConnection(): void
    {
        $pool = new ConnectionPool(
            name: 'orders',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );
        $lease = new ConnectionLease($pool);
        $request = (new Psr17Factory())->createServerRequest('GET', '/')->withAttribute(ConnectionLease::class, $lease);

        $resolver = new ConnectionResolver();
        $reflection = new ReflectionFunction(static function (Connection $c): void {});
        $metadata = $resolver->compile(
            $reflection->getParameters()[0],
            new CompileContext('handler', new ResolverServices()),
        );

        $value = $resolver->resolve($metadata, new HttpRequestContext($request, new ResolverServices()));

        self::assertInstanceOf(Connection::class, $value);
        $lease->release();
    }

    #[Test]
    public function resolveThrowsWhenScopeMissing(): void
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/');
        $resolver = new ConnectionResolver();
        $reflection = new ReflectionFunction(static function (Connection $c): void {});
        $metadata = $resolver->compile(
            $reflection->getParameters()[0],
            new CompileContext('handler', new ResolverServices()),
        );

        $this->expectException(MissingConnectionScopeException::class);
        $resolver->resolve($metadata, new HttpRequestContext($request, new ResolverServices()));
    }
}
```

- [ ] **Step 3: Run, verify failure**

- [ ] **Step 4: Implement `ConnectionResolver`**

`packages/nexus-doctrine-dbal/src/Http/ConnectionResolver.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Http;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Exception\MissingConnectionScopeException;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Override;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @psalm-api
 */
final class ConnectionResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType) {
            return null;
        }

        if ($type->getName() !== Connection::class) {
            return null;
        }

        return new ParamMetadata(
            resolver: $this,
            name: $param->getName(),
            type: Connection::class,
        );
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): Connection
    {
        if (!$ctx instanceof HttpRequestContext) {
            throw new MissingConnectionScopeException();
        }

        $lease = $ctx->request()->getAttribute(ConnectionLease::class);

        if (!$lease instanceof ConnectionLease) {
            throw new MissingConnectionScopeException();
        }

        return $lease->get();
    }
}
```

(Note: `HttpRequestContext::request()` is the accessor name from the existing redesign spec; if the field is `public readonly ServerRequestInterface $request` instead, adapt accordingly. The test will tell you.)

- [ ] **Step 5: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Http/ConnectionResolverTest.php
git add packages/nexus-doctrine-dbal/src/Http/ConnectionResolver.php packages/nexus-doctrine-dbal/tests/Unit/Http/ConnectionResolverTest.php
git commit -m "feat(doctrine-dbal): add ConnectionResolver param resolver"
```

---

## Task 17: `PoolExhaustedToServiceUnavailable` middleware

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Http/PoolExhaustedToServiceUnavailable.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Http/PoolExhaustedToServiceUnavailableTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Http;

use Monadial\Nexus\Doctrine\Dbal\Exception\PoolExhaustedException;
use Monadial\Nexus\Doctrine\Dbal\Http\PoolExhaustedToServiceUnavailable;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolStats;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(PoolExhaustedToServiceUnavailable::class)]
final class PoolExhaustedToServiceUnavailableTest extends TestCase
{
    #[Test]
    public function maps503WithRetryAfter(): void
    {
        $middleware = new PoolExhaustedToServiceUnavailable(new Psr17Factory());

        $handler = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $req): ResponseInterface
            {
                throw PoolExhaustedException::after('orders', PoolStats::empty());
            }
        };

        $response = $middleware->process(
            (new Psr17Factory())->createServerRequest('GET', '/'),
            $handler,
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function nonPoolExceptionsBubble(): void
    {
        $middleware = new PoolExhaustedToServiceUnavailable(new Psr17Factory());

        $handler = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $req): ResponseInterface
            {
                throw new \RuntimeException('something else');
            }
        };

        $this->expectException(\RuntimeException::class);
        $middleware->process((new Psr17Factory())->createServerRequest('GET', '/'), $handler);
    }
}
```

- [ ] **Step 2: Run, verify failure + implement**

`packages/nexus-doctrine-dbal/src/Http/PoolExhaustedToServiceUnavailable.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Http;

use Monadial\Nexus\Doctrine\Dbal\Exception\PoolExhaustedException;
use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @psalm-api
 */
final readonly class PoolExhaustedToServiceUnavailable implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactoryInterface $responses,
        private int $retryAfterSeconds = 1,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (PoolExhaustedException) {
            return $this->responses->createResponse(503)
                ->withHeader('Retry-After', (string) $this->retryAfterSeconds);
        }
    }
}
```

- [ ] **Step 3: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Http/PoolExhaustedToServiceUnavailableTest.php
git add packages/nexus-doctrine-dbal/src/Http/PoolExhaustedToServiceUnavailable.php packages/nexus-doctrine-dbal/tests/Unit/Http/PoolExhaustedToServiceUnavailableTest.php
git commit -m "feat(doctrine-dbal): add PoolExhausted -> 503 middleware"
```

---

## Task 18: `#[Transactional]` attribute + decorator

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Http/Attribute/Transactional.php`
- Create: `packages/nexus-doctrine-dbal/src/Http/TransactionalDecorator.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Http/TransactionalDecoratorTest.php`

The decorator wraps a `RequestHandlerInterface` chosen by a route. When the handler class has `#[Transactional]`, the decorator opens a transaction on the `ConnectionLease`-provided `Connection` and commits on success / rolls back on throw. Plan 2 will add an ORM path; this plan is DBAL-only.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Http;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Http\Attribute\Transactional;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionLease;
use Monadial\Nexus\Doctrine\Dbal\Http\TransactionalDecorator;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(Transactional::class)]
#[CoversClass(TransactionalDecorator::class)]
final class TransactionalDecoratorTest extends TestCase
{
    #[Test]
    public function commitsOnSuccess(): void
    {
        $mock = $this->createMock(Connection::class);
        $mock->expects(self::once())->method('beginTransaction');
        $mock->expects(self::once())->method('commit');
        $mock->expects(self::never())->method('rollBack');

        $factory = new StubConnectionFactory();
        $factory->prepend($mock);
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );
        $lease = new ConnectionLease($pool);

        $inner = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $req): ResponseInterface { return new Response(200); }
        };

        $request = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withAttribute(ConnectionLease::class, $lease);
        $decorator = new TransactionalDecorator($inner);

        $decorator->handle($request);

        $lease->release();
    }

    #[Test]
    public function rollsBackOnThrow(): void
    {
        $mock = $this->createMock(Connection::class);
        $mock->expects(self::once())->method('beginTransaction');
        $mock->expects(self::never())->method('commit');
        $mock->expects(self::once())->method('rollBack');

        $factory = new StubConnectionFactory();
        $factory->prepend($mock);
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );
        $lease = new ConnectionLease($pool);

        $inner = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $req): ResponseInterface { throw new \RuntimeException('boom'); }
        };

        $request = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withAttribute(ConnectionLease::class, $lease);

        try {
            (new TransactionalDecorator($inner))->handle($request);
            self::fail('expected throw');
        } catch (\RuntimeException) {
            // expected
        }

        $lease->release();
    }
}
```

- [ ] **Step 2: Run, verify failure + implement**

`packages/nexus-doctrine-dbal/src/Http/Attribute/Transactional.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Http\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Transactional
{
    public function __construct() {}
}
```

`packages/nexus-doctrine-dbal/src/Http/TransactionalDecorator.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Http;

use Monadial\Nexus\Doctrine\Dbal\Exception\MissingConnectionScopeException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * @psalm-api
 */
final readonly class TransactionalDecorator implements RequestHandlerInterface
{
    public function __construct(private RequestHandlerInterface $inner) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $lease = $request->getAttribute(ConnectionLease::class);

        if (!$lease instanceof ConnectionLease) {
            throw new MissingConnectionScopeException();
        }

        $conn = $lease->get();
        $conn->beginTransaction();

        try {
            $response = $this->inner->handle($request);
            $conn->commit();

            return $response;
        } catch (Throwable $e) {
            $conn->rollBack();

            throw $e;
        }
    }
}
```

(Wiring `#[Transactional]` into the route pipeline is left to `DoctrineHttp::install()` in Task 19 — it scans handler class attributes and wraps in `TransactionalDecorator`.)

- [ ] **Step 3: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Http/TransactionalDecoratorTest.php
git add packages/nexus-doctrine-dbal/src/Http/Attribute/ packages/nexus-doctrine-dbal/src/Http/TransactionalDecorator.php packages/nexus-doctrine-dbal/tests/Unit/Http/TransactionalDecoratorTest.php
git commit -m "feat(doctrine-dbal): add #[Transactional] attribute + decorator"
```

---

## Task 19: `DoctrineHttp::install()` facade

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Http/DoctrineHttp.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Http/DoctrineHttpTest.php`

Bundles the wiring: registers `ConnectionResolver` with the `ParamResolverRegistry`, pushes `ConnectionScopeMiddleware` and `PoolExhaustedToServiceUnavailable` onto the pipeline.

- [ ] **Step 1: Look up the `NexusApp` registration methods**

```bash
docker compose exec -T php-fiber grep -rn 'public function use\|public function resolvers' packages/nexus-http/src/ packages/nexus-app/src/
```

Find the actual method names — write the implementation against them.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Http;

use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionResolver;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionScopeMiddleware;
use Monadial\Nexus\Doctrine\Dbal\Http\DoctrineHttp;
use Monadial\Nexus\Doctrine\Dbal\Http\PoolExhaustedToServiceUnavailable;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineHttp::class)]
final class DoctrineHttpTest extends TestCase
{
    #[Test]
    public function installRegistersResolverAndMiddleware(): void
    {
        $pool = new ConnectionPool(
            name: 'orders',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );
        $registry = new ParamResolverRegistry();
        /** @var list<object> $middlewares */
        $middlewares = [];

        DoctrineHttp::install(
            registry: $registry,
            middlewares: $middlewares,
            connPool: $pool,
        );

        $hasConnectionResolver = false;
        foreach ($registry->all() as $resolver) {
            if ($resolver instanceof ConnectionResolver) {
                $hasConnectionResolver = true;
            }
        }
        self::assertTrue($hasConnectionResolver);

        self::assertNotEmpty(array_filter($middlewares, static fn(object $m): bool => $m instanceof ConnectionScopeMiddleware));
        self::assertNotEmpty(array_filter($middlewares, static fn(object $m): bool => $m instanceof PoolExhaustedToServiceUnavailable));
    }
}
```

(`ParamResolverRegistry::all()` must exist; if it doesn't, the test asserts via whatever inspector method the registry exposes. Adapt the test to the registry's surface.)

- [ ] **Step 3: Run, verify failure + implement**

`packages/nexus-doctrine-dbal/src/Http/DoctrineHttp.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Http;

use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * Wiring facade for HTTP integration. Registers resolvers and pushes
 * middleware onto the pipeline. Pass `connPool` to enable DBAL Connection
 * injection. The Plan 2 equivalent adds `emPool` for ORM.
 *
 * @psalm-api
 */
final class DoctrineHttp
{
    /**
     * @param list<object> $middlewares
     * @param-out list<object> $middlewares
     */
    public static function install(
        ParamResolverRegistry $registry,
        array &$middlewares,
        ConnectionPool $connPool,
        ?ResponseFactoryInterface $responseFactory = null,
    ): void {
        $registry->add(new ConnectionResolver());
        $middlewares[] = new ConnectionScopeMiddleware($connPool);
        $middlewares[] = new PoolExhaustedToServiceUnavailable($responseFactory ?? new Psr17Factory());
    }
}
```

- [ ] **Step 4: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Http/DoctrineHttpTest.php
git add packages/nexus-doctrine-dbal/src/Http/DoctrineHttp.php packages/nexus-doctrine-dbal/tests/Unit/Http/DoctrineHttpTest.php
git commit -m "feat(doctrine-dbal): add DoctrineHttp::install facade"
```

---

## Task 20: HTTP integration regression gate

- [ ] **Step 1: Run the full nexus-http unit suite**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-http/tests/Unit
```
Expected: all green. The new resolver shouldn't affect existing tests.

- [ ] **Step 2: Run the full nexus-doctrine-dbal unit suite**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit
```
Expected: all green.

- [ ] **Step 3: Run Deptrac**

```bash
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyze
```
Expected: no new violations.

- [ ] **Step 4: Run Psalm on the new package**

```bash
docker compose exec -T php vendor/bin/psalm --no-cache packages/nexus-doctrine-dbal/
```
Expected: no errors. If Psalm complains about unused-suppress or missing returns, fix in place.

(No commit — this is a verification step. If anything fails, fix it and amend the previous task's commit, then re-run.)

---

## Task 21: `ActorPoolBinding` helper

**Files:**
- Create: `packages/nexus-doctrine-dbal/src/Actor/ActorPoolBinding.php`
- Create: `packages/nexus-doctrine-dbal/tests/Unit/Actor/ActorPoolBindingTest.php`

(`EntityManagerPool` is null in this binding — that field is introduced in Plan 2; here it remains nullable to allow Plan-2 backfill without changing the shape.)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Actor;

use Monadial\Nexus\Doctrine\Dbal\Actor\ActorPoolBinding;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorPoolBinding::class)]
final class ActorPoolBindingTest extends TestCase
{
    #[Test]
    public function exposesConnPool(): void
    {
        $pool = new ConnectionPool(
            name: 'orders',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        $binding = new ActorPoolBinding($pool);

        self::assertSame($pool, $binding->connPool);
    }
}
```

- [ ] **Step 2: Run, verify failure + implement**

`packages/nexus-doctrine-dbal/src/Actor/ActorPoolBinding.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Actor;

use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;

/**
 * Ergonomic carrier injected into actors via Props::fromFactory().
 * Plan 2 extends this with an optional EntityManagerPool field.
 *
 * @psalm-api
 */
final readonly class ActorPoolBinding
{
    public function __construct(public ConnectionPool $connPool) {}
}
```

- [ ] **Step 3: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-dbal/tests/Unit/Actor/ActorPoolBindingTest.php
git add packages/nexus-doctrine-dbal/src/Actor/ActorPoolBinding.php packages/nexus-doctrine-dbal/tests/Unit/Actor/ActorPoolBindingTest.php
git commit -m "feat(doctrine-dbal): add ActorPoolBinding carrier"
```

---

## Task 22: `PooledConnectionInActorPropertyRule` Psalm rule

**Files:**
- Create: `packages/nexus-psalm/src/Hook/PooledConnectionInActorPropertyRule.php`
- Modify: `packages/nexus-psalm/src/Plugin.php` — register the hook.
- Create: `packages/nexus-psalm/tests/Unit/Hook/PooledConnectionInActorPropertyRuleTest.php`

- [ ] **Step 1: Inspect an existing Psalm hook for shape**

```bash
docker compose exec -T php-fiber ls packages/nexus-psalm/src/Hook/
docker compose exec -T php-fiber cat packages/nexus-psalm/src/Hook/ReadonlyMessageRule.php
docker compose exec -T php-fiber cat packages/nexus-psalm/src/Plugin.php
```

(`ReadonlyMessageRule` is the closest analog — class-level rule firing on property types. Follow its shape.)

- [ ] **Step 2: Write the failing test**

Look at any of the existing `PooledConnection*` test files for the pattern (Psalm plugin tests in this repo use Psalm's `ProjectAnalyzer` directly per CLAUDE.md). Use the same harness:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Unit\Hook;

use Monadial\Nexus\Psalm\Tests\Support\PsalmTestCase;
use PHPUnit\Framework\Attributes\Test;

final class PooledConnectionInActorPropertyRuleTest extends PsalmTestCase
{
    #[Test]
    public function flagsConnectionPropertyOnActorHandler(): void
    {
        $code = <<<'PHP'
        <?php
        namespace App;
        use Doctrine\DBAL\Connection;
        use Monadial\Nexus\Core\Actor\ActorHandler;
        use Monadial\Nexus\Core\Actor\ActorContext;
        use Monadial\Nexus\Core\Actor\Behavior;
        final class Bad implements ActorHandler {
            public function __construct(private readonly Connection $conn) {}
            public function handle(ActorContext $ctx, object $message): Behavior {
                return Behavior::same();
            }
        }
        PHP;

        $this->assertHasIssue($code, 'NexusPooledConnectionInActorProperty');
    }

    #[Test]
    public function doesNotFlagOnRegularServiceClass(): void
    {
        $code = <<<'PHP'
        <?php
        namespace App;
        use Doctrine\DBAL\Connection;
        final class Service {
            public function __construct(private readonly Connection $conn) {}
        }
        PHP;

        $this->assertNoIssue($code, 'NexusPooledConnectionInActorProperty');
    }
}
```

(If `PsalmTestCase` doesn't exist with `assertHasIssue` / `assertNoIssue` helpers, follow whatever harness the other `nexus-psalm` tests use — read e.g. `packages/nexus-psalm/tests/Unit/Hook/ReadonlyMessageRuleTest.php` and copy its pattern.)

- [ ] **Step 3: Run, verify failure + implement**

`packages/nexus-psalm/src/Hook/PooledConnectionInActorPropertyRule.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Psalm\Codebase;
use Psalm\Issue\PluginIssue;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;

final class PooledConnectionInActorPropertyRule implements AfterClassLikeAnalysisInterface
{
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event): ?bool
    {
        $storage = $event->getClassLikeStorage();
        $fqcn = $storage->name;

        if (!self::implementsActorHandler($event->getCodebase(), $fqcn)) {
            return null;
        }

        foreach ($storage->properties as $name => $property) {
            $type = $property->type;

            if ($type === null) {
                continue;
            }

            $typeStr = (string) $type;

            if (
                !str_contains($typeStr, 'Doctrine\DBAL\Connection')
                && !str_contains($typeStr, 'Doctrine\ORM\EntityManagerInterface')
            ) {
                continue;
            }

            IssueBuffer::accepts(new class ("Property \"{$name}\" holds a pooled resource for the actor's whole lifetime — this defeats the pool. See plan §4.3.", $property->location) extends PluginIssue {
                public static string $issue_type = 'NexusPooledConnectionInActorProperty';
            });
        }

        return null;
    }

    private static function implementsActorHandler(Codebase $codebase, string $fqcn): bool
    {
        try {
            $impls = $codebase->classlikes->getClassExtendsAndImplements($fqcn);
        } catch (\Throwable) {
            return false;
        }

        return in_array('Monadial\\Nexus\\Core\\Actor\\ActorHandler', $impls, true)
            || in_array('Monadial\\Nexus\\Core\\Actor\\StatefulActorHandler', $impls, true);
    }
}
```

- [ ] **Step 4: Register hook in `packages/nexus-psalm/src/Plugin.php`**

Find the existing `__invoke()` method that registers `ReadonlyMessageRule` and add the same registration call for `PooledConnectionInActorPropertyRule`. Match the existing pattern exactly.

- [ ] **Step 5: Verify pass + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-psalm/tests/Unit/Hook/PooledConnectionInActorPropertyRuleTest.php
git add packages/nexus-psalm/src/Hook/PooledConnectionInActorPropertyRule.php packages/nexus-psalm/src/Plugin.php packages/nexus-psalm/tests/Unit/Hook/PooledConnectionInActorPropertyRuleTest.php
git commit -m "feat(psalm): add PooledConnectionInActorPropertyRule"
```

---

## Task 23: Fiber integration test (real SQLite, no Swoole)

**Files:**
- Create: `tests/Integration/Doctrine/Fiber/ConnectionPoolFiberTest.php`

This proves the pool works against a real DBAL connection end-to-end without Swoole hooks. Uses in-memory SQLite so no extra service is needed.

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Doctrine\Fiber;

use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConnectionPoolFiberTest extends TestCase
{
    #[Test]
    public function takeReleaseExecutesRealQuery(): void
    {
        $pool = DoctrinePool::fromUrl(
            name: 'test',
            url: 'sqlite3:///:memory:',
            config: new PoolConfig(max: 2, minIdle: 1),
        );

        $value = $pool->withConnection(
            static fn($conn): int => (int) $conn->fetchOne('SELECT 42'),
        );

        self::assertSame(42, $value);
        $pool->close(\Monadial\Nexus\Core\Duration::seconds(1));
    }
}
```

- [ ] **Step 2: Run**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit tests/Integration/Doctrine/Fiber/
```
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Doctrine/Fiber/
git commit -m "test(doctrine-dbal): add Fiber integration test"
```

---

## Task 24: Swoole integration test (coroutine borrow concurrency)

**Files:**
- Create: `tests/Integration/Doctrine/Swoole/ConcurrentBorrowTest.php`

Proves multiple coroutines can wait on the same pool concurrently and that `take()` correctly suspends until release. Uses SQLite for simplicity — the goal is concurrency semantics, not driver-specific behavior.

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Doctrine\Swoole;

use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Dbal\Bootstrap\DoctrineBootstrap;
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\WaitGroup;

use function Swoole\Coroutine\go;
use function Swoole\Coroutine\run;

#[RequiresPhpExtension('swoole')]
final class ConcurrentBorrowTest extends TestCase
{
    #[Test]
    public function tenCoroutinesShareTwoConnections(): void
    {
        DoctrineBootstrap::enable();
        $results = [];
        run(function () use (&$results): void {
            $pool = DoctrinePool::fromUrl(
                name: 'concurrent',
                url: 'sqlite3:///:memory:',
                config: new PoolConfig(borrowTimeout: Duration::seconds(5), max: 2, minIdle: 0),
            );
            $wg = new WaitGroup();

            for ($i = 0; $i < 10; $i++) {
                $wg->add();
                go(function () use ($pool, $wg, $i, &$results): void {
                    $results[$i] = $pool->withConnection(
                        static fn($c): int => (int) $c->fetchOne('SELECT 42'),
                    );
                    $wg->done();
                });
            }

            $wg->wait();
        });

        self::assertCount(10, $results);
        foreach ($results as $r) {
            self::assertSame(42, $r);
        }
    }
}
```

- [ ] **Step 2: Run**

```bash
docker compose exec -T php-swoole vendor/bin/phpunit tests/Integration/Doctrine/Swoole/
```
Expected: PASS within `borrowTimeout`.

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Doctrine/Swoole/
git commit -m "test(doctrine-dbal): add Swoole concurrent-borrow integration test"
```

---

## Task 25: Worker-pool integration test (per-thread isolation)

**Files:**
- Create: `tests/Integration/Doctrine/WorkerPool/PerThreadPoolIsolationTest.php`

Verifies that pools constructed inside `WorkerStartHandler::onWorkerStart()` are thread-local — no cross-thread leakage. Reuses the existing worker-pool test scaffolding pattern from `tests/Integration/WorkerPool/`.

- [ ] **Step 1: Read an existing worker-pool integration test for the harness shape**

```bash
docker compose exec -T php-swoole ls tests/Integration/WorkerPool/
docker compose exec -T php-swoole head -80 tests/Integration/WorkerPool/<one of them>.php
```

- [ ] **Step 2: Write the test mirroring that pattern**

The test spawns a 2-thread worker pool. Each thread's `WorkerStartHandler::onWorkerStart()` creates its own `ConnectionPool` and stores it in a thread-local map. From within each thread, an actor takes/releases connections; we assert that each thread only sees its own pool's stats (no cross-thread leakage).

The exact harness call differs based on `WorkerPoolApp` shape — read `examples/nexus-wallet-app/public/server.php` for the canonical bootstrap, then mirror.

- [ ] **Step 3: Run**

```bash
docker compose exec -T php-swoole vendor/bin/phpunit tests/Integration/Doctrine/WorkerPool/
```
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/Doctrine/WorkerPool/
git commit -m "test(doctrine-dbal): verify per-thread pool isolation under worker-pool-swoole"
```

---

## Task 26: Performance benchmark

**Files:**
- Create: `tests/Performance/Doctrine/PoolTakeReleaseBench.php`

Measures take/release throughput under Swoole — target < 50 µs avg per round trip with no contention.

- [ ] **Step 1: Write the benchmark**

```php
<?php

declare(strict_types=1);

namespace Tests\Performance\Doctrine;

use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Dbal\Bootstrap\DoctrineBootstrap;
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Swoole\Coroutine\run;

#[RequiresPhpExtension('swoole')]
#[Group('performance')]
final class PoolTakeReleaseBench extends TestCase
{
    #[Test]
    public function takeReleaseAvgUnder50us(): void
    {
        DoctrineBootstrap::enable();
        $avgNs = 0;
        run(function () use (&$avgNs): void {
            $pool = DoctrinePool::fromUrl(
                name: 'bench',
                url: 'sqlite3:///:memory:',
                config: new PoolConfig(max: 1, minIdle: 1),
            );
            $iterations = 100_000;
            $start = hrtime(true);

            for ($i = 0; $i < $iterations; $i++) {
                $conn = $pool->take(Duration::seconds(1));
                $pool->release($conn);
            }

            $avgNs = intdiv(hrtime(true) - $start, $iterations);
        });

        self::assertLessThan(50_000, $avgNs, sprintf('avg per take+release: %d ns', $avgNs));
    }
}
```

- [ ] **Step 2: Run**

```bash
docker compose exec -T php-swoole vendor/bin/phpunit --group=performance tests/Performance/Doctrine/
```

Expected: PASS. If it fails, the threshold may be too aggressive for CI hardware — adjust the assertion to a measured baseline and document the figure as a comment.

- [ ] **Step 3: Add Makefile target**

In `Makefile`, alongside existing `test-fiber`/`test-swoole`:

```makefile
test-doctrine:
	docker compose exec -T php-fiber vendor/bin/phpunit tests/Integration/Doctrine/Fiber/
	docker compose exec -T php-swoole vendor/bin/phpunit tests/Integration/Doctrine/Swoole/ tests/Integration/Doctrine/WorkerPool/
```

- [ ] **Step 4: Commit**

```bash
git add tests/Performance/Doctrine/ Makefile
git commit -m "test(doctrine-dbal): add take/release throughput benchmark + make target"
```

---

## Task 27: Final repo-wide gate + plan-completion commit

- [ ] **Step 1: Run the full unit suite**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages
```
Expected: all green. No new failures elsewhere.

- [ ] **Step 2: Run all linters**

```bash
docker compose exec -T php-fiber vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose exec -T php-fiber vendor/bin/phpcs
docker compose exec -T php vendor/bin/psalm --no-cache
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyze
```

Each must be green. Fix any infraction inline, run again.

- [ ] **Step 3: Run integration suites**

```bash
make test-fiber
make test-swoole
make test-cluster
make test-doctrine
```

All green.

- [ ] **Step 4: Verify branch state**

```bash
git status
git log --oneline feat/nexus-http..HEAD
```

Expected: 25+ commits on `feat/nexus-doctrine`. Working tree clean (except the two pre-existing unstaged files `.deptrac.cache` and `BodySizeLimitMiddleware.php`).

- [ ] **Step 5: Push the branch (with user approval)**

Ask the user before pushing. Don't push without confirmation. When approved:

```bash
git push -u origin feat/nexus-doctrine
```

---

## Self-review checklist (run BEFORE handing off)

- [ ] Every spec section in `2026-06-16-nexus-doctrine-async-design.md` for `nexus-doctrine-dbal` is covered by at least one task here (ORM-only sections belong to Plans 2 & 3 and are deliberately absent).
- [ ] No `TBD` / `TODO` strings in this plan. (`grep -E 'TBD|TODO|FIXME' docs/superpowers/plans/2026-06-16-nexus-doctrine-dbal.md`)
- [ ] Type and method names are consistent across tasks: `ConnectionPool::take()` / `release()` / `withConnection()` / `close()` / `stats()` / `name()`; `ConnectionLease::get()` / `poison()` / `release()`; `PoolConfig` named-only constructor; `Evictor::tick()` / `LeakDetector::tick()`.
- [ ] Every commit message uses the `feat(doctrine-dbal):` / `test(doctrine-dbal):` / `feat(psalm):` prefix per repo convention.
- [ ] Every test command uses `docker compose exec -T php-fiber` (or `php-swoole` for Swoole-only paths). No host PHP commands.
- [ ] Plan 2 (`nexus-doctrine-orm` core) and Plan 3 (`EntityBehavior`) are referenced as upcoming, not assumed delivered.

---

**Next plans (write after Plan 1 is in):**

- **Plan 2 — `nexus-doctrine-orm` core:** `EntityManagerPool`, `PooledEntityManager` decorator, `EntityManagerLease`, `EntityManagerScopeMiddleware`, `EntityManagerResolver`, `EntityManagerFactory`, ORM-path `#[Transactional]`, `DoctrineHttp::installOrm()`. Adds optional `?EntityManagerPool $emPool` field to `ActorPoolBinding`.
- **Plan 3 — `EntityBehavior` DSL:** `EntityBehavior`, `EntityBehaviorBuilder`, `EntityEffect`, `EntityReplayPolicy` (`Fail` / `CreateIfMissing` / `OnDemand`), `EntityRefFactory`, `EntityConflictException`, Psalm `EntityBehaviorReturnTypeProvider`, `MissingTransactionalDeclarationRule`.

