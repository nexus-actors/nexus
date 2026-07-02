# Nexus Doctrine ORM Core Implementation Plan (Plan 2 of 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `nexus-doctrine-orm` package core — `EntityManagerPool` + `PooledEntityManager` decorator + HTTP scope middleware/resolver + `EntityManagerFactory` + ORM-path `#[Transactional]`. After this plan, applications can inject `EntityManagerInterface` into HTTP handlers exactly like `Connection` from Plan 1. Plan 3 then adds the `EntityBehavior` DSL on top.

**Architecture:** `EntityManagerPool` is a sibling primitive to `ConnectionPool` (Plan 1), not a wrapper. Each pooled EM owns its own DBAL connection for its lifetime in the pool. Internally the EM pool maintains a **private** `ConnectionPool` (reusing the Plan 1 class), so the connection-management primitive is shared but user-visible budgets stay independent. `PooledEntityManager` is a thin `EntityManagerDecorator` over `EntityManagerInterface` — handlers see a normal EM. Release semantics: on `release()` the EM is `clear()`-ed and pushed back to pool; if `$em->isOpen() === false` (Doctrine closes EM after flush failure), the EM is destroyed and the slot recycled. HTTP integration mirrors Plan 1 — a `EntityManagerLease` attribute, an `EntityManagerScopeMiddleware`, an `EntityManagerResolver`. ORM-path `#[Transactional]` shares the attribute with Plan 1 but wraps via `EntityManagerInterface::wrapInTransaction(...)`.

**Tech Stack:** PHP 8.5 strict, Doctrine ORM ^3.0, Doctrine DBAL ^4.0 (transitively via Plan 1), Psalm strict-level 1, PHPCS PER-CS2.0 + Slevomat, PHPUnit 13, GrumPHP. Branch `feat/nexus-doctrine` (continues from Plan 1).

**Depends on:** Plan 1 (`2026-06-16-nexus-doctrine-dbal.md`) merged. All `nexus-doctrine-dbal` symbols (`ConnectionPool`, `PoolConfig`, `Channel`, `FiberChannel`, `SwooleChannel`, `ConnectionLease`, `ConnectionScopeMiddleware`, `Transactional` attribute, `TransactionalDecorator`, `MissingTransactionalDependencyException`, `ActorPoolBinding`, `DoctrineHttp`) are available.

---

## Spec → Plan map

| Spec section | Tasks |
|---|---|
| EM pool primitive (config, shape, take/release) | T2–T7 |
| `EntityManagerFactory` + `DefaultEntityManagerFactory` | T3 |
| `PooledEntityManager` decorator | T4 |
| HTTP integration (lease, middleware, resolver) | T8–T10 |
| `#[Transactional]` ORM path | T11 |
| `DoctrineHttp::installOrm()` extension | T12 |
| Actor-side: extend `ActorPoolBinding` with `?emPool` | T13 |
| Tests (unit, fiber, swoole, worker-pool) | T14–T16 |
| Final gates | T17 |

---

## File structure

**New files in `packages/nexus-doctrine-orm/`:**

```
packages/nexus-doctrine-orm/
├── composer.json
├── src/
│   ├── Pool/
│   │   ├── EntityManagerPool.php
│   │   ├── EntityManagerFactory.php                    interface
│   │   ├── DefaultEntityManagerFactory.php             builds EM from a borrowed Connection
│   │   ├── PooledEntityManager.php                     extends Doctrine\ORM\Decorator\EntityManagerDecorator
│   │   ├── EmPoolConfig.php
│   │   └── EmPoolStats.php
│   ├── DoctrineEmPool.php                              public facade forConfig()
│   ├── Http/
│   │   ├── EntityManagerLease.php
│   │   ├── EntityManagerScopeMiddleware.php
│   │   ├── EntityManagerResolver.php
│   │   ├── TransactionalEmDecorator.php                ORM path for #[Transactional]
│   │   └── DoctrineOrmHttp.php                         installOrm() facade
│   ├── Event/
│   │   ├── EntityManagerCreated.php
│   │   ├── EntityManagerCleared.php
│   │   └── EntityManagerEvicted.php
│   └── Exception/
│       └── MissingEntityManagerScopeException.php
├── tests/
│   ├── Unit/
│   └── Support/
│       ├── StubEntityManagerFactory.php
│       └── InMemoryOrmFixture.php                      SQLite + bare metadata
└── phpunit.xml.dist
```

**Modified files:**
- `deptrac.yaml` — add `NexusDoctrineOrm` layer (allowed deps `[NexusCore, NexusHttp, NexusDoctrineDbal, Doctrine, PsrLog, PsrEventDispatcher, PsrHttpServerMiddleware, PsrHttpMessage]`).
- `composer.json` (root) — register `nexus-doctrine-orm` path repo if needed (mirror existing pattern).
- `packages/nexus-doctrine-dbal/src/Actor/ActorPoolBinding.php` — add `public readonly ?EntityManagerPool $emPool = null` field (last param so existing constructions stay valid).
- `Makefile` — extend `test-doctrine` target.

---

## Conventions

Same as Plan 1:
- Docker for everything (`docker compose exec -T php-fiber vendor/bin/phpunit …`).
- GrumPHP gates each commit.
- Commit format `feat(doctrine-orm): …`, `test(doctrine-orm): …`.
- All classes `final`, all value objects `readonly`, PER-CS2.0 + Slevomat, named-only constructors for value objects.
- `#[CoversClass]` + `#[Test]` on every test.
- PSR-3 + PSR-14 nullable in constructors.

---

## Task 1: Package skeleton + Deptrac layer

**Files:**
- Create: `packages/nexus-doctrine-orm/composer.json`
- Create: `packages/nexus-doctrine-orm/phpunit.xml.dist`
- Create: `packages/nexus-doctrine-orm/src/.gitkeep`, `tests/.gitkeep`
- Modify: `deptrac.yaml`
- Modify: root `composer.json` (path repo registration if needed)

- [ ] **Step 1: Write `packages/nexus-doctrine-orm/composer.json`**

```json
{
    "name": "nexus-actors/doctrine-orm",
    "description": "Nexus Doctrine ORM — pooled EntityManager, HTTP middleware, and EntityBehavior DSL.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "doctrine/dbal": "^4.0",
        "doctrine/orm": "^3.0",
        "nexus-actors/core": "dev-main",
        "nexus-actors/doctrine-dbal": "dev-main",
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
            "Monadial\\Nexus\\Doctrine\\Orm\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Doctrine\\Orm\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

- [ ] **Step 2: Write `packages/nexus-doctrine-orm/phpunit.xml.dist`**

Same shape as Plan 1's package phpunit config, namespace adjusted:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="../../vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
    </testsuites>
    <source><include><directory>src</directory></include></source>
</phpunit>
```

- [ ] **Step 3: Modify `deptrac.yaml`**

Add layer:
```yaml
  - name: NexusDoctrineOrm
    collectors:
      - type: classLike
        regex: '^Monadial\\Nexus\\Doctrine\\Orm\\.*'
```

Add ruleset:
```yaml
  NexusDoctrineOrm:
    - NexusCore
    - NexusHttp
    - NexusDoctrineDbal
    - Doctrine
    - PsrEventDispatcher
    - PsrHttpFactory
    - PsrHttpMessage
    - PsrHttpServerMiddleware
    - PsrLog
```

- [ ] **Step 4: Register path repo + dump autoload**

```bash
docker compose exec -T php composer dump-autoload
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyze
```

Both expected to succeed.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-doctrine-orm/composer.json packages/nexus-doctrine-orm/phpunit.xml.dist packages/nexus-doctrine-orm/src/.gitkeep packages/nexus-doctrine-orm/tests/.gitkeep deptrac.yaml composer.json composer.lock
git commit -m "feat(doctrine-orm): scaffold nexus-doctrine-orm package"
```

---

## Task 2: `EmPoolConfig` + `EmPoolStats`

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Pool/EmPoolConfig.php`
- Create: `packages/nexus-doctrine-orm/src/Pool/EmPoolStats.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Pool/EmPoolConfigTest.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Pool/EmPoolStatsTest.php`

- [ ] **Step 1: Write failing tests**

`packages/nexus-doctrine-orm/tests/Unit/Pool/EmPoolConfigTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Pool;

use InvalidArgumentException;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmPoolConfig::class)]
final class EmPoolConfigTest extends TestCase
{
    #[Test]
    public function defaultsMatchSpec(): void
    {
        $config = new EmPoolConfig();

        self::assertSame(16, $config->max);
        self::assertSame(2, $config->minIdle);
        self::assertTrue($config->borrowTimeout->equals(Duration::seconds(5)));
        self::assertTrue($config->clearOnReturn);
        self::assertSame(1000, $config->recreateAfter);
    }

    #[Test]
    public function rejectsInvalidMax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmPoolConfig(max: 0);
    }

    #[Test]
    public function rejectsMinIdleAboveMax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmPoolConfig(max: 2, minIdle: 5);
    }
}
```

`packages/nexus-doctrine-orm/tests/Unit/Pool/EmPoolStatsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Pool;

use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmPoolStats::class)]
final class EmPoolStatsTest extends TestCase
{
    #[Test]
    public function empty(): void
    {
        $s = EmPoolStats::empty();
        self::assertSame(0, $s->idle);
        self::assertSame(0, $s->inUse);
        self::assertSame(0, $s->total);
        self::assertSame(0, $s->totalBorrows);
        self::assertSame(0, $s->totalEvictions);
    }
}
```

- [ ] **Step 2: Implement**

`packages/nexus-doctrine-orm/src/Pool/EmPoolConfig.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Pool;

use InvalidArgumentException;
use Monadial\Nexus\Core\Duration;

/** @psalm-api */
final readonly class EmPoolConfig
{
    public Duration $borrowTimeout;

    public function __construct(
        ?Duration $borrowTimeout = null,
        public bool $clearOnReturn = true,
        public int $max = 16,
        public int $minIdle = 2,
        public int $recreateAfter = 1000,
    ) {
        if ($max <= 0) {
            throw new InvalidArgumentException(sprintf('max (%d) must be positive', $max));
        }

        if ($minIdle > $max) {
            throw new InvalidArgumentException(sprintf('minIdle (%d) must not exceed max (%d)', $minIdle, $max));
        }

        if ($recreateAfter < 0) {
            throw new InvalidArgumentException(sprintf('recreateAfter (%d) must be >= 0', $recreateAfter));
        }

        $this->borrowTimeout = $borrowTimeout ?? Duration::seconds(5);
    }
}
```

`packages/nexus-doctrine-orm/src/Pool/EmPoolStats.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Pool;

/** @psalm-api */
final readonly class EmPoolStats
{
    public function __construct(
        public int $idle,
        public int $inUse,
        public int $total,
        public int $totalBorrows,
        public int $totalEvictions,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0);
    }
}
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Pool/EmPoolConfigTest.php packages/nexus-doctrine-orm/tests/Unit/Pool/EmPoolStatsTest.php
git add packages/nexus-doctrine-orm/src/Pool/EmPoolConfig.php packages/nexus-doctrine-orm/src/Pool/EmPoolStats.php packages/nexus-doctrine-orm/tests/Unit/Pool/
git commit -m "feat(doctrine-orm): add EmPoolConfig + EmPoolStats"
```

---

## Task 3: `EntityManagerFactory` interface + `DefaultEntityManagerFactory`

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Pool/EntityManagerFactory.php`
- Create: `packages/nexus-doctrine-orm/src/Pool/DefaultEntityManagerFactory.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Pool/DefaultEntityManagerFactoryTest.php`
- Create: `packages/nexus-doctrine-orm/tests/Support/StubEntityManagerFactory.php`

The factory takes a borrowed `Connection` and a `Doctrine\ORM\Configuration` and returns a `Doctrine\ORM\EntityManagerInterface`. It does NOT take from the pool — the EM pool is the one that owns connection borrow lifecycle.

- [ ] **Step 1: Write failing test**

`packages/nexus-doctrine-orm/tests/Unit/Pool/DefaultEntityManagerFactoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Pool;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultEntityManagerFactory::class)]
final class DefaultEntityManagerFactoryTest extends TestCase
{
    #[Test]
    public function createBindsEmToProvidedConnection(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(paths: [], isDevMode: true);
        $factory = new DefaultEntityManagerFactory($config);
        $conn = DriverManager::getConnection(['url' => 'sqlite3:///:memory:']);

        $em = $factory->create($conn);

        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertSame($conn, $em->getConnection());
        $em->close();
    }
}
```

- [ ] **Step 2: Implement interface + default**

`packages/nexus-doctrine-orm/src/Pool/EntityManagerFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Pool;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/** @psalm-api */
interface EntityManagerFactory
{
    public function create(Connection $connection): EntityManagerInterface;
}
```

`packages/nexus-doctrine-orm/src/Pool/DefaultEntityManagerFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Pool;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Override;

/** @psalm-api */
final readonly class DefaultEntityManagerFactory implements EntityManagerFactory
{
    public function __construct(private Configuration $configuration) {}

    #[Override]
    public function create(Connection $connection): EntityManagerInterface
    {
        return new EntityManager($connection, $this->configuration);
    }
}
```

- [ ] **Step 3: Write stub factory**

`packages/nexus-doctrine-orm/tests/Support/StubEntityManagerFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;
use Override;
use PHPUnit\Framework\MockObject\Generator\Generator;
use RuntimeException;

/** @psalm-api */
final class StubEntityManagerFactory implements EntityManagerFactory
{
    /** @var list<EntityManagerInterface> */
    private array $prepared = [];
    public int $creations = 0;

    public function prepend(EntityManagerInterface $em): void
    {
        $this->prepared[] = $em;
    }

    #[Override]
    public function create(Connection $connection): EntityManagerInterface
    {
        $this->creations++;

        if ($this->prepared !== []) {
            return array_shift($this->prepared);
        }

        /** @var EntityManagerInterface $mock */
        $mock = (new Generator())->testDouble(
            type: EntityManagerInterface::class,
            mockObject: true,
            markAsMockObject: true,
        );

        return $mock;
    }

    public function exhaustOrFail(): void
    {
        if ($this->prepared !== []) {
            throw new RuntimeException('Prepared EMs not all consumed');
        }
    }
}
```

- [ ] **Step 4: Verify + commit**

```bash
docker compose exec -T php-fiber composer dump-autoload
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Pool/DefaultEntityManagerFactoryTest.php
git add packages/nexus-doctrine-orm/src/Pool/EntityManagerFactory.php packages/nexus-doctrine-orm/src/Pool/DefaultEntityManagerFactory.php packages/nexus-doctrine-orm/tests/Support/StubEntityManagerFactory.php packages/nexus-doctrine-orm/tests/Unit/Pool/DefaultEntityManagerFactoryTest.php
git commit -m "feat(doctrine-orm): add EntityManagerFactory + default impl"
```

---

## Task 4: `PooledEntityManager` decorator

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Pool/PooledEntityManager.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Pool/PooledEntityManagerTest.php`

`PooledEntityManager` extends `Doctrine\ORM\Decorator\EntityManagerDecorator` (which delegates every `EntityManagerInterface` method by default), so we only need to add the borrow/return bookkeeping.

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Pool;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\PooledEntityManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PooledEntityManager::class)]
final class PooledEntityManagerTest extends TestCase
{
    #[Test]
    public function delegatesIsOpenToInner(): void
    {
        $inner = $this->createMock(EntityManagerInterface::class);
        $inner->method('isOpen')->willReturn(false);

        $pem = new PooledEntityManager($inner);
        self::assertFalse($pem->isOpen());
    }

    #[Test]
    public function exposesBorrowCount(): void
    {
        $inner = $this->createMock(EntityManagerInterface::class);
        $pem = new PooledEntityManager($inner);

        self::assertSame(0, $pem->borrowCount());
        $pem->markBorrowed();
        self::assertSame(1, $pem->borrowCount());
        $pem->markBorrowed();
        self::assertSame(2, $pem->borrowCount());
    }

    #[Test]
    public function clearDelegatesToInner(): void
    {
        $inner = $this->createMock(EntityManagerInterface::class);
        $inner->expects(self::once())->method('clear');

        (new PooledEntityManager($inner))->clear();
    }
}
```

- [ ] **Step 2: Implement**

`packages/nexus-doctrine-orm/src/Pool/PooledEntityManager.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Pool;

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;

/** @psalm-api */
final class PooledEntityManager extends EntityManagerDecorator
{
    private int $borrows = 0;

    public function __construct(EntityManagerInterface $wrapped)
    {
        parent::__construct($wrapped);
    }

    public function markBorrowed(): void
    {
        $this->borrows++;
    }

    public function borrowCount(): int
    {
        return $this->borrows;
    }
}
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Pool/PooledEntityManagerTest.php
git add packages/nexus-doctrine-orm/src/Pool/PooledEntityManager.php packages/nexus-doctrine-orm/tests/Unit/Pool/PooledEntityManagerTest.php
git commit -m "feat(doctrine-orm): add PooledEntityManager decorator"
```

---

## Task 5: `MissingEntityManagerScopeException` + PSR-14 events

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Exception/MissingEntityManagerScopeException.php`
- Create: `packages/nexus-doctrine-orm/src/Event/EntityManagerCreated.php`
- Create: `packages/nexus-doctrine-orm/src/Event/EntityManagerCleared.php`
- Create: `packages/nexus-doctrine-orm/src/Event/EntityManagerEvicted.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Exception/MissingEntityManagerScopeExceptionTest.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Event/EventShapeTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Unit/Exception/MissingEntityManagerScopeExceptionTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Exception;

use Monadial\Nexus\Core\Exception\NexusException;
use Monadial\Nexus\Doctrine\Orm\Exception\MissingEntityManagerScopeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissingEntityManagerScopeException::class)]
final class MissingEntityManagerScopeExceptionTest extends TestCase
{
    #[Test]
    public function messageHintsAtMiddleware(): void
    {
        $e = new MissingEntityManagerScopeException();
        self::assertInstanceOf(NexusException::class, $e);
        self::assertStringContainsString('EntityManagerScopeMiddleware', $e->getMessage());
    }
}
```

`tests/Unit/Event/EventShapeTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Event;

use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCleared;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCreated;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerEvicted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityManagerCreated::class)]
#[CoversClass(EntityManagerCleared::class)]
#[CoversClass(EntityManagerEvicted::class)]
final class EventShapeTest extends TestCase
{
    #[Test]
    public function eventsCarryPoolName(): void
    {
        self::assertSame('o', (new EntityManagerCreated('o'))->poolName);
        self::assertSame('o', (new EntityManagerCleared('o'))->poolName);
        self::assertSame('closed', (new EntityManagerEvicted('o', 'closed'))->reason);
    }
}
```

- [ ] **Step 2: Implement**

`packages/nexus-doctrine-orm/src/Exception/MissingEntityManagerScopeException.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/** @psalm-api */
final class MissingEntityManagerScopeException extends NexusException
{
    public function __construct()
    {
        parent::__construct('No EntityManagerLease found on the request. Did you install EntityManagerScopeMiddleware in the HTTP pipeline?');
    }
}
```

`packages/nexus-doctrine-orm/src/Event/EntityManagerCreated.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Event;

/** @psalm-api */
final readonly class EntityManagerCreated
{
    public function __construct(public string $poolName) {}
}
```

`packages/nexus-doctrine-orm/src/Event/EntityManagerCleared.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Event;

/** @psalm-api */
final readonly class EntityManagerCleared
{
    public function __construct(public string $poolName) {}
}
```

`packages/nexus-doctrine-orm/src/Event/EntityManagerEvicted.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Event;

/** @psalm-api */
final readonly class EntityManagerEvicted
{
    public function __construct(
        public string $poolName,
        public string $reason,
    ) {}
}
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Exception/ packages/nexus-doctrine-orm/tests/Unit/Event/
git add packages/nexus-doctrine-orm/src/Exception/ packages/nexus-doctrine-orm/src/Event/ packages/nexus-doctrine-orm/tests/Unit/Exception/ packages/nexus-doctrine-orm/tests/Unit/Event/
git commit -m "feat(doctrine-orm): add ORM-scope exception + lifecycle events"
```

---

## Task 6: `EntityManagerPool` — take / release happy path

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Pool/EntityManagerPool.php` (initial cut)
- Create: `packages/nexus-doctrine-orm/tests/Unit/Pool/EntityManagerPoolTest.php`

The pool internally owns a private `ConnectionPool` (from `nexus-doctrine-dbal`). On `take()`:
1. Pop an existing EM from idle channel if any.
2. Otherwise lazily: `take` a connection from the private conn pool + factory.create($conn) + wrap in `PooledEntityManager`.

On `release()`:
1. If pool closed → destroy, return connection.
2. If EM is closed (`isOpen() === false`) → destroy, return connection, emit `EntityManagerEvicted('closed')`.
3. If `clearOnReturn` → `$em->clear()`, emit `EntityManagerCleared`.
4. If `borrowCount() >= recreateAfter` → destroy, return connection, emit `EntityManagerEvicted('recreate-after')`.
5. Otherwise push back to idle channel.

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Pool;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Doctrine\Orm\Pool\PooledEntityManager;
use Monadial\Nexus\Doctrine\Orm\Tests\Support\StubEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityManagerPool::class)]
final class EntityManagerPoolTest extends TestCase
{
    #[Test]
    public function takeLazilyConstructsUpToMax(): void
    {
        $emFactory = new StubEntityManagerFactory();
        $emFactory->prepend($this->openEm());
        $emFactory->prepend($this->openEm());

        $pool = $this->pool($emFactory, new EmPoolConfig(max: 2, minIdle: 0));

        $a = $pool->take();
        $b = $pool->take();

        self::assertInstanceOf(PooledEntityManager::class, $a);
        self::assertInstanceOf(PooledEntityManager::class, $b);
        self::assertSame(2, $emFactory->creations);
        self::assertSame(2, $pool->stats()->inUse);

        $pool->release($a);
        $pool->release($b);
    }

    #[Test]
    public function releaseClearsAndReuses(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->expects(self::once())->method('clear');

        $emFactory = new StubEntityManagerFactory();
        $emFactory->prepend($em);
        $pool = $this->pool($emFactory, new EmPoolConfig(clearOnReturn: true, max: 1, minIdle: 0));

        $a = $pool->take();
        $pool->release($a);
        $b = $pool->take();

        self::assertSame($a, $b);
        self::assertSame(1, $emFactory->creations);
        $pool->release($b);
    }

    #[Test]
    public function closedEmIsEvicted(): void
    {
        $closedEm = $this->createMock(EntityManagerInterface::class);
        $closedEm->method('isOpen')->willReturn(false);

        $emFactory = new StubEntityManagerFactory();
        $emFactory->prepend($closedEm);
        $emFactory->prepend($this->openEm());

        $pool = $this->pool($emFactory, new EmPoolConfig(max: 1, minIdle: 0));

        $a = $pool->take();
        $pool->release($a);
        self::assertSame(0, $pool->stats()->total);

        $b = $pool->take();
        self::assertNotSame($a, $b);
        $pool->release($b);
    }

    #[Test]
    public function withEntityManagerReleasesOnSuccess(): void
    {
        $emFactory = new StubEntityManagerFactory();
        $emFactory->prepend($this->openEm());
        $pool = $this->pool($emFactory, new EmPoolConfig(max: 1, minIdle: 0));

        $result = $pool->withEntityManager(static fn(EntityManagerInterface $em): string => 'ok');

        self::assertSame('ok', $result);
        self::assertSame(0, $pool->stats()->inUse);
    }

    private function openEm(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);

        return $em;
    }

    private function pool(StubEntityManagerFactory $emFactory, EmPoolConfig $config): EntityManagerPool
    {
        $connPool = new ConnectionPool(
            name: 'em-private',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: $config->max, minIdle: 0),
            channel: new FiberChannel($config->max),
        );

        return new EntityManagerPool(
            name: 'orders',
            factory: $emFactory,
            connPool: $connPool,
            config: $config,
            channel: new FiberChannel($config->max),
        );
    }
}
```

- [ ] **Step 2: Implement**

`packages/nexus-doctrine-orm/src/Pool/EntityManagerPool.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Pool;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\Channel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCleared;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCreated;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerEvicted;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SplObjectStorage;
use Throwable;

/**
 * @psalm-api
 *
 * @psalm-type EmMeta = array{conn: \Doctrine\DBAL\Connection}
 */
final class EntityManagerPool
{
    /** @var Channel<PooledEntityManager> */
    private Channel $idle;

    /** @var SplObjectStorage<PooledEntityManager, EmMeta> */
    private SplObjectStorage $live;

    private int $total = 0;
    private int $inUse = 0;
    private int $totalBorrows = 0;
    private int $totalEvictions = 0;
    private bool $closed = false;

    public function __construct(
        private readonly string $name,
        private readonly EntityManagerFactory $factory,
        private readonly ConnectionPool $connPool,
        private readonly EmPoolConfig $config,
        Channel $channel,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->idle = $channel;
        $this->live = new SplObjectStorage();
    }

    public function take(?Duration $timeout = null): PooledEntityManager
    {
        if ($this->closed) {
            throw new \Monadial\Nexus\Doctrine\Dbal\Exception\PoolClosedException($this->name);
        }

        $existing = $this->idle->pop(Duration::zero());

        if ($existing !== null) {
            return $this->markBorrowed($existing);
        }

        if ($this->total < $this->config->max) {
            return $this->createAndBorrow();
        }

        $waited = $this->idle->pop($timeout ?? $this->config->borrowTimeout);

        if ($waited === null) {
            throw \Monadial\Nexus\Doctrine\Dbal\Exception\PoolExhaustedException::after(
                $this->name,
                new \Monadial\Nexus\Doctrine\Dbal\Pool\PoolStats(
                    idle: $this->idle->size(),
                    inUse: $this->inUse,
                    total: $this->total,
                    waitingCoroutines: 0,
                    totalBorrows: $this->totalBorrows,
                    totalWaits: 0,
                    totalTimeouts: 0,
                ),
            );
        }

        return $this->markBorrowed($waited);
    }

    public function release(PooledEntityManager $em): void
    {
        if (!$this->live->contains($em)) {
            return;
        }

        $this->inUse--;
        $meta = $this->live[$em];

        if ($this->closed) {
            $this->destroy($em, 'closed-pool');

            return;
        }

        if (!$em->isOpen()) {
            $this->destroy($em, 'em-closed');

            return;
        }

        if ($this->config->recreateAfter > 0 && $em->borrowCount() >= $this->config->recreateAfter) {
            $this->destroy($em, 'recreate-after');

            return;
        }

        if ($this->config->clearOnReturn) {
            $em->clear();
            $this->events?->dispatch(new EntityManagerCleared($this->name));
        }

        $accepted = $this->idle->push($em);

        if (!$accepted) {
            $this->destroy($em, 'channel-full');
        }
    }

    /**
     * @template T
     * @param Closure(EntityManagerInterface): T $fn
     * @return T
     */
    public function withEntityManager(Closure $fn): mixed
    {
        $em = $this->take();

        try {
            return $fn($em);
        } finally {
            $this->release($em);
        }
    }

    public function close(Duration $timeout): void
    {
        $this->closed = true;
        $this->idle->close();
        $drained = $this->idle->pop(Duration::zero());

        while ($drained !== null) {
            $this->destroy($drained, 'closed-pool');
            $drained = $this->idle->pop(Duration::zero());
        }
    }

    public function stats(): EmPoolStats
    {
        return new EmPoolStats(
            idle: $this->idle->size(),
            inUse: $this->inUse,
            total: $this->total,
            totalBorrows: $this->totalBorrows,
            totalEvictions: $this->totalEvictions,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    private function createAndBorrow(): PooledEntityManager
    {
        $conn = $this->connPool->take();

        try {
            $inner = $this->factory->create($conn);
        } catch (Throwable $e) {
            $this->connPool->release($conn, poison: true);

            throw $e;
        }

        $em = new PooledEntityManager($inner);
        $this->live[$em] = ['conn' => $conn];
        $this->total++;
        $this->events?->dispatch(new EntityManagerCreated($this->name));

        return $this->markBorrowed($em);
    }

    private function markBorrowed(PooledEntityManager $em): PooledEntityManager
    {
        $em->markBorrowed();
        $this->inUse++;
        $this->totalBorrows++;

        return $em;
    }

    private function destroy(PooledEntityManager $em, string $reason): void
    {
        $meta = $this->live[$em] ?? null;
        $this->live->detach($em);
        $this->total--;
        $this->totalEvictions++;

        try {
            $em->close();
        } catch (Throwable $e) {
            $this->logger->warning('Failed to close EM cleanly: {error}', ['error' => $e->getMessage()]);
        }

        if ($meta !== null) {
            $this->connPool->release($meta['conn']);
        }

        $this->events?->dispatch(new EntityManagerEvicted($this->name, $reason));
    }
}
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Pool/EntityManagerPoolTest.php
git add packages/nexus-doctrine-orm/src/Pool/EntityManagerPool.php packages/nexus-doctrine-orm/tests/Unit/Pool/EntityManagerPoolTest.php
git commit -m "feat(doctrine-orm): EntityManagerPool take/release happy path"
```

---

## Task 7: `DoctrineEmPool::forConfig()` public facade

**Files:**
- Create: `packages/nexus-doctrine-orm/src/DoctrineEmPool.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/DoctrineEmPoolTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit;

use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineEmPool::class)]
final class DoctrineEmPoolTest extends TestCase
{
    #[Test]
    public function forConfigBuildsPool(): void
    {
        $pool = DoctrineEmPool::forConfig(
            name: 'orders',
            connParams: ['url' => 'sqlite3:///:memory:'],
            ormSetup: ORMSetup::createAttributeMetadataConfiguration(paths: [], isDevMode: true),
            config: new EmPoolConfig(max: 2, minIdle: 0),
        );

        self::assertInstanceOf(EntityManagerPool::class, $pool);
        $em = $pool->take();
        self::assertTrue($em->isOpen());
        $pool->release($em);
    }
}
```

- [ ] **Step 2: Implement**

`packages/nexus-doctrine-orm/src/DoctrineEmPool.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm;

use Doctrine\ORM\Configuration;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\SwooleChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\DriverManagerConnectionFactory;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** @psalm-api */
final class DoctrineEmPool
{
    /**
     * @param array<string, mixed> $connParams
     */
    public static function forConfig(
        string $name,
        array $connParams,
        Configuration $ormSetup,
        ?EmPoolConfig $config = null,
        ?EventDispatcherInterface $events = null,
        ?LoggerInterface $logger = null,
    ): EntityManagerPool {
        $config ??= new EmPoolConfig();
        $log = $logger ?? new NullLogger();
        $useSwoole = extension_loaded('swoole');

        $connPool = new ConnectionPool(
            name: $name . '-conn',
            factory: new DriverManagerConnectionFactory($connParams),
            config: new PoolConfig(max: $config->max, minIdle: 0),
            channel: $useSwoole ? new SwooleChannel($config->max) : new FiberChannel($config->max),
            events: $events,
            logger: $log,
        );

        return new EntityManagerPool(
            name: $name,
            factory: new DefaultEntityManagerFactory($ormSetup),
            connPool: $connPool,
            config: $config,
            channel: $useSwoole ? new SwooleChannel($config->max) : new FiberChannel($config->max),
            events: $events,
            logger: $log,
        );
    }
}
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/DoctrineEmPoolTest.php
git add packages/nexus-doctrine-orm/src/DoctrineEmPool.php packages/nexus-doctrine-orm/tests/Unit/DoctrineEmPoolTest.php
git commit -m "feat(doctrine-orm): add DoctrineEmPool::forConfig facade"
```

---

## Task 8: `EntityManagerLease`

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Http/EntityManagerLease.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Http/EntityManagerLeaseTest.php`

Mirrors `ConnectionLease` from Plan 1 exactly, but no poison flag — EM "poison" semantics are implicit in `isOpen() === false` and handled by the pool on release.

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Http;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerLease;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityManagerLease::class)]
final class EntityManagerLeaseTest extends TestCase
{
    #[Test]
    public function getLazilyBorrows(): void
    {
        $pool = DoctrineEmPool::forConfig(
            name: 'orders',
            connParams: ['url' => 'sqlite3:///:memory:'],
            ormSetup: ORMSetup::createAttributeMetadataConfiguration(paths: [], isDevMode: true),
            config: new EmPoolConfig(max: 1, minIdle: 0),
        );

        $lease = new EntityManagerLease($pool);
        self::assertSame(0, $pool->stats()->inUse);

        $a = $lease->get();
        self::assertInstanceOf(EntityManagerInterface::class, $a);
        self::assertSame(1, $pool->stats()->inUse);

        $b = $lease->get();
        self::assertSame($a, $b);

        $lease->release();
        self::assertSame(0, $pool->stats()->inUse);
    }

    #[Test]
    public function releaseWithoutGetIsNoOp(): void
    {
        $pool = DoctrineEmPool::forConfig(
            name: 'orders',
            connParams: ['url' => 'sqlite3:///:memory:'],
            ormSetup: ORMSetup::createAttributeMetadataConfiguration(paths: [], isDevMode: true),
            config: new EmPoolConfig(max: 1, minIdle: 0),
        );

        (new EntityManagerLease($pool))->release();
        self::assertSame(0, $pool->stats()->inUse);
    }
}
```

- [ ] **Step 2: Implement**

`packages/nexus-doctrine-orm/src/Http/EntityManagerLease.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Http;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Doctrine\Orm\Pool\PooledEntityManager;

/** @psalm-api */
final class EntityManagerLease
{
    private ?PooledEntityManager $em = null;

    public function __construct(private readonly EntityManagerPool $pool) {}

    public function get(): EntityManagerInterface
    {
        return $this->em ??= $this->pool->take();
    }

    public function release(): void
    {
        if ($this->em === null) {
            return;
        }

        $this->pool->release($this->em);
        $this->em = null;
    }
}
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Http/EntityManagerLeaseTest.php
git add packages/nexus-doctrine-orm/src/Http/EntityManagerLease.php packages/nexus-doctrine-orm/tests/Unit/Http/EntityManagerLeaseTest.php
git commit -m "feat(doctrine-orm): add EntityManagerLease"
```

---

## Task 9: `EntityManagerScopeMiddleware`

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Http/EntityManagerScopeMiddleware.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Http/EntityManagerScopeMiddlewareTest.php`

Same shape as `ConnectionScopeMiddleware` from Plan 1.

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Http;

use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerLease;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerScopeMiddleware;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(EntityManagerScopeMiddleware::class)]
final class EntityManagerScopeMiddlewareTest extends TestCase
{
    #[Test]
    public function attachesLeaseAndReleasesAfterHandle(): void
    {
        $pool = DoctrineEmPool::forConfig(
            name: 'orders',
            connParams: ['url' => 'sqlite3:///:memory:'],
            ormSetup: ORMSetup::createAttributeMetadataConfiguration(paths: [], isDevMode: true),
            config: new EmPoolConfig(max: 1, minIdle: 0),
        );
        $middleware = new EntityManagerScopeMiddleware($pool);

        $handler = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $req): ResponseInterface
            {
                $lease = $req->getAttribute(EntityManagerLease::class);
                self::assertInstanceOf(EntityManagerLease::class, $lease);
                $lease->get();

                return new Response(200);
            }
        };

        $response = $middleware->process((new Psr17Factory())->createServerRequest('GET', '/'), $handler);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $pool->stats()->inUse);
    }
}
```

- [ ] **Step 2: Implement**

`packages/nexus-doctrine-orm/src/Http/EntityManagerScopeMiddleware.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Http;

use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** @psalm-api */
final readonly class EntityManagerScopeMiddleware implements MiddlewareInterface
{
    public function __construct(private EntityManagerPool $pool) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $lease = new EntityManagerLease($this->pool);
        $request = $request->withAttribute(EntityManagerLease::class, $lease);

        try {
            return $handler->handle($request);
        } finally {
            $lease->release();
        }
    }
}
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Http/EntityManagerScopeMiddlewareTest.php
git add packages/nexus-doctrine-orm/src/Http/EntityManagerScopeMiddleware.php packages/nexus-doctrine-orm/tests/Unit/Http/EntityManagerScopeMiddlewareTest.php
git commit -m "feat(doctrine-orm): add EntityManagerScopeMiddleware"
```

---

## Task 10: `EntityManagerResolver` (`ParamResolver` impl)

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Http/EntityManagerResolver.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Http/EntityManagerResolverTest.php`

Fires on parameter type `Doctrine\ORM\EntityManagerInterface`. Same shape as `ConnectionResolver` from Plan 1.

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Http;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Exception\MissingEntityManagerScopeException;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerLease;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerResolver;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

#[CoversClass(EntityManagerResolver::class)]
final class EntityManagerResolverTest extends TestCase
{
    #[Test]
    public function compileMatchesEmTypedParameter(): void
    {
        $resolver = new EntityManagerResolver();
        $reflection = new ReflectionFunction(static function (EntityManagerInterface $em): void {});

        $metadata = $resolver->compile(
            $reflection->getParameters()[0],
            new CompileContext('handler', new ResolverServices()),
        );

        self::assertNotNull($metadata);
    }

    #[Test]
    public function compileSkipsNonEm(): void
    {
        $reflection = new ReflectionFunction(static function (string $s): void {});
        $metadata = (new EntityManagerResolver())->compile(
            $reflection->getParameters()[0],
            new CompileContext('handler', new ResolverServices()),
        );

        self::assertNull($metadata);
    }

    #[Test]
    public function resolveReturnsBorrowedEm(): void
    {
        $pool = DoctrineEmPool::forConfig(
            name: 'orders',
            connParams: ['url' => 'sqlite3:///:memory:'],
            ormSetup: ORMSetup::createAttributeMetadataConfiguration(paths: [], isDevMode: true),
            config: new EmPoolConfig(max: 1, minIdle: 0),
        );
        $lease = new EntityManagerLease($pool);
        $request = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withAttribute(EntityManagerLease::class, $lease);

        $resolver = new EntityManagerResolver();
        $reflection = new ReflectionFunction(static function (EntityManagerInterface $em): void {});
        $metadata = $resolver->compile($reflection->getParameters()[0], new CompileContext('h', new ResolverServices()));

        $value = $resolver->resolve($metadata, new HttpRequestContext($request, new ResolverServices()));

        self::assertInstanceOf(EntityManagerInterface::class, $value);
        $lease->release();
    }

    #[Test]
    public function resolveThrowsWhenScopeMissing(): void
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/');
        $resolver = new EntityManagerResolver();
        $reflection = new ReflectionFunction(static function (EntityManagerInterface $em): void {});
        $metadata = $resolver->compile($reflection->getParameters()[0], new CompileContext('h', new ResolverServices()));

        $this->expectException(MissingEntityManagerScopeException::class);
        $resolver->resolve($metadata, new HttpRequestContext($request, new ResolverServices()));
    }
}
```

- [ ] **Step 2: Implement**

`packages/nexus-doctrine-orm/src/Http/EntityManagerResolver.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Http;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Exception\MissingEntityManagerScopeException;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Override;
use ReflectionNamedType;
use ReflectionParameter;

/** @psalm-api */
final class EntityManagerResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType) {
            return null;
        }

        if ($type->getName() !== EntityManagerInterface::class) {
            return null;
        }

        return new ParamMetadata(
            resolver: $this,
            name: $param->getName(),
            type: EntityManagerInterface::class,
        );
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): EntityManagerInterface
    {
        if (!$ctx instanceof HttpRequestContext) {
            throw new MissingEntityManagerScopeException();
        }

        $lease = $ctx->request()->getAttribute(EntityManagerLease::class);

        if (!$lease instanceof EntityManagerLease) {
            throw new MissingEntityManagerScopeException();
        }

        return $lease->get();
    }
}
```

(If the `HttpRequestContext` field is `public readonly ServerRequestInterface $request` instead of an accessor, adapt — same caveat as Plan 1 Task 16.)

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Http/EntityManagerResolverTest.php
git add packages/nexus-doctrine-orm/src/Http/EntityManagerResolver.php packages/nexus-doctrine-orm/tests/Unit/Http/EntityManagerResolverTest.php
git commit -m "feat(doctrine-orm): add EntityManagerResolver"
```

---

## Task 11: `TransactionalEmDecorator` — ORM path for `#[Transactional]`

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Http/TransactionalEmDecorator.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Http/TransactionalEmDecoratorTest.php`

Wraps the inner handler in `EntityManagerInterface::wrapInTransaction(...)`. Reuses the `#[Transactional]` attribute from Plan 1's `nexus-doctrine-dbal`.

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Http;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Exception\MissingEntityManagerScopeException;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerLease;
use Monadial\Nexus\Doctrine\Orm\Http\TransactionalEmDecorator;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Doctrine\Orm\Pool\PooledEntityManager;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(TransactionalEmDecorator::class)]
final class TransactionalEmDecoratorTest extends TestCase
{
    #[Test]
    public function wrapsHandlerInTransaction(): void
    {
        $innerEm = $this->createMock(EntityManagerInterface::class);
        $innerEm->method('isOpen')->willReturn(true);
        $innerEm->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn(callable $fn): mixed => $fn($innerEm));

        $lease = $this->leaseReturning($innerEm);

        $handler = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $req): ResponseInterface { return new Response(200); }
        };

        $request = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withAttribute(EntityManagerLease::class, $lease);

        $response = (new TransactionalEmDecorator($handler))->handle($request);
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function throwsWhenLeaseMissing(): void
    {
        $handler = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $req): ResponseInterface { return new Response(200); }
        };

        $this->expectException(MissingEntityManagerScopeException::class);
        (new TransactionalEmDecorator($handler))->handle(
            (new Psr17Factory())->createServerRequest('GET', '/'),
        );
    }

    private function leaseReturning(EntityManagerInterface $em): EntityManagerLease
    {
        $pem = new PooledEntityManager($em);
        $pool = $this->createMock(EntityManagerPool::class);
        $pool->method('take')->willReturn($pem);

        return new EntityManagerLease($pool);
    }
}
```

- [ ] **Step 2: Implement**

`packages/nexus-doctrine-orm/src/Http/TransactionalEmDecorator.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Http;

use Monadial\Nexus\Doctrine\Orm\Exception\MissingEntityManagerScopeException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** @psalm-api */
final readonly class TransactionalEmDecorator implements RequestHandlerInterface
{
    public function __construct(private RequestHandlerInterface $inner) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $lease = $request->getAttribute(EntityManagerLease::class);

        if (!$lease instanceof EntityManagerLease) {
            throw new MissingEntityManagerScopeException();
        }

        $em = $lease->get();

        return $em->wrapInTransaction(fn(): ResponseInterface => $this->inner->handle($request));
    }
}
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Http/TransactionalEmDecoratorTest.php
git add packages/nexus-doctrine-orm/src/Http/TransactionalEmDecorator.php packages/nexus-doctrine-orm/tests/Unit/Http/TransactionalEmDecoratorTest.php
git commit -m "feat(doctrine-orm): add #[Transactional] ORM-path decorator"
```

---

## Task 12: `DoctrineOrmHttp::installOrm()` facade

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Http/DoctrineOrmHttp.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Http/DoctrineOrmHttpTest.php`

Bundles the ORM-side HTTP wiring: registers `EntityManagerResolver`, pushes `EntityManagerScopeMiddleware`. Reuses `PoolExhaustedToServiceUnavailable` from Plan 1 — the same exception fires from the EM pool's internal connection pool.

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Http;

use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Http\DoctrineOrmHttp;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerResolver;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerScopeMiddleware;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineOrmHttp::class)]
final class DoctrineOrmHttpTest extends TestCase
{
    #[Test]
    public function installRegistersResolverAndMiddleware(): void
    {
        $pool = DoctrineEmPool::forConfig(
            name: 'orders',
            connParams: ['url' => 'sqlite3:///:memory:'],
            ormSetup: ORMSetup::createAttributeMetadataConfiguration(paths: [], isDevMode: true),
            config: new EmPoolConfig(max: 1, minIdle: 0),
        );

        $registry = new ParamResolverRegistry();
        /** @var list<object> $middlewares */
        $middlewares = [];

        DoctrineOrmHttp::installOrm(registry: $registry, middlewares: $middlewares, emPool: $pool);

        $hasResolver = false;
        foreach ($registry->all() as $r) {
            if ($r instanceof EntityManagerResolver) {
                $hasResolver = true;
            }
        }
        self::assertTrue($hasResolver);
        self::assertNotEmpty(array_filter($middlewares, static fn(object $m): bool => $m instanceof EntityManagerScopeMiddleware));
    }
}
```

- [ ] **Step 2: Implement**

`packages/nexus-doctrine-orm/src/Http/DoctrineOrmHttp.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Http;

use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;

/** @psalm-api */
final class DoctrineOrmHttp
{
    /**
     * @param list<object> $middlewares
     * @param-out list<object> $middlewares
     */
    public static function installOrm(
        ParamResolverRegistry $registry,
        array &$middlewares,
        EntityManagerPool $emPool,
    ): void {
        $registry->add(new EntityManagerResolver());
        $middlewares[] = new EntityManagerScopeMiddleware($emPool);
    }
}
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Http/DoctrineOrmHttpTest.php
git add packages/nexus-doctrine-orm/src/Http/DoctrineOrmHttp.php packages/nexus-doctrine-orm/tests/Unit/Http/DoctrineOrmHttpTest.php
git commit -m "feat(doctrine-orm): add DoctrineOrmHttp::installOrm facade"
```

---

## Task 13: Extend `ActorPoolBinding` with optional `?EntityManagerPool $emPool`

**Files:**
- Modify: `packages/nexus-doctrine-dbal/src/Actor/ActorPoolBinding.php`
- Modify: `packages/nexus-doctrine-dbal/tests/Unit/Actor/ActorPoolBindingTest.php`

The Plan 1 binding only carries the conn pool. Plan 2 extends it without breaking existing constructions. The field has to live in `nexus-doctrine-dbal` to avoid a downward dep, so the type must be `?\Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool` — that introduces a soft dep on the ORM package. Resolved by:

- Adding `nexus-actors/doctrine-orm` to `nexus-actors/doctrine-dbal`'s `suggest` block (not `require`).
- Allowing the ORM layer in Deptrac to be referenced **only** from `ActorPoolBinding` — add a single-class exception in `deptrac.yaml`.

Alternative (cleaner): create a separate `OrmActorPoolBinding` in `nexus-doctrine-orm`, leaving `ActorPoolBinding` untouched. Choose this — it keeps DBAL truly independent. Reverting the `ActorPoolBinding` change and adding a sibling instead.

- [ ] **Step 1: Create the ORM binding instead of modifying DBAL's**

`packages/nexus-doctrine-orm/src/Actor/OrmActorPoolBinding.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Actor;

use Monadial\Nexus\Doctrine\Dbal\Actor\ActorPoolBinding;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;

/**
 * Carries both connection and EM pool. Extends Plan 1's DBAL-only binding
 * via composition so DBAL stays independent of the ORM package.
 *
 * @psalm-api
 */
final readonly class OrmActorPoolBinding
{
    public function __construct(
        public ActorPoolBinding $base,
        public EntityManagerPool $emPool,
    ) {}
}
```

- [ ] **Step 2: Write failing test**

`packages/nexus-doctrine-orm/tests/Unit/Actor/OrmActorPoolBindingTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Actor;

use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Doctrine\Dbal\Actor\ActorPoolBinding;
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Orm\Actor\OrmActorPoolBinding;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrmActorPoolBinding::class)]
final class OrmActorPoolBindingTest extends TestCase
{
    #[Test]
    public function carriesBothPools(): void
    {
        $conn = DoctrinePool::fromUrl('orders-conn', 'sqlite3:///:memory:', new PoolConfig(max: 1, minIdle: 0));
        $em = DoctrineEmPool::forConfig(
            name: 'orders-em',
            connParams: ['url' => 'sqlite3:///:memory:'],
            ormSetup: ORMSetup::createAttributeMetadataConfiguration(paths: [], isDevMode: true),
            config: new EmPoolConfig(max: 1, minIdle: 0),
        );

        $binding = new OrmActorPoolBinding(new ActorPoolBinding($conn), $em);

        self::assertSame($conn, $binding->base->connPool);
        self::assertSame($em, $binding->emPool);
    }
}
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Actor/OrmActorPoolBindingTest.php
git add packages/nexus-doctrine-orm/src/Actor/OrmActorPoolBinding.php packages/nexus-doctrine-orm/tests/Unit/Actor/OrmActorPoolBindingTest.php
git commit -m "feat(doctrine-orm): add OrmActorPoolBinding (composes DBAL binding + EM pool)"
```

---

## Task 14: Fiber integration test — real EM round trip

**Files:**
- Create: `tests/Integration/Doctrine/Fiber/EntityManagerPoolFiberTest.php`
- Create: `tests/Integration/Doctrine/Fiber/Fixture/Item.php`

Use SQLite + a one-entity fixture (`Item { id, name }`). Schema is created in the test's `setUp`. Verifies the full stack: pool take → persist → flush → release → reborrow shows the persisted row.

- [ ] **Step 1: Write the fixture entity**

`tests/Integration/Doctrine/Fiber/Fixture/Item.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Doctrine\Fiber\Fixture;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'items')]
class Item
{
    #[Id]
    #[GeneratedValue]
    #[Column]
    public ?int $id = null;

    #[Column]
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
```

- [ ] **Step 2: Write the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Doctrine\Fiber;

use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Doctrine\Fiber\Fixture\Item;

final class EntityManagerPoolFiberTest extends TestCase
{
    #[Test]
    public function persistAndReadBack(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/Fixture'],
            isDevMode: true,
        );
        $pool = DoctrineEmPool::forConfig(
            name: 'items',
            connParams: ['url' => 'sqlite3:///:memory:'],
            ormSetup: $config,
            config: new EmPoolConfig(max: 1, minIdle: 0),
        );

        $em = $pool->take();
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Item::class)]);
        $item = new Item('keyboard');
        $em->persist($item);
        $em->flush();
        $id = $item->id;
        $pool->release($em);

        $emRead = $pool->take();
        $reloaded = $emRead->find(Item::class, $id);
        self::assertNotNull($reloaded);
        self::assertSame('keyboard', $reloaded->name);
        $pool->release($emRead);

        $pool->close(Duration::seconds(1));
    }
}
```

- [ ] **Step 3: Run + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit tests/Integration/Doctrine/Fiber/EntityManagerPoolFiberTest.php
git add tests/Integration/Doctrine/Fiber/
git commit -m "test(doctrine-orm): Fiber integration test for EntityManagerPool"
```

---

## Task 15: Swoole integration test — concurrent EM borrows

**Files:**
- Create: `tests/Integration/Doctrine/Swoole/ConcurrentEmBorrowTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Doctrine\Swoole;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Doctrine\Dbal\Bootstrap\DoctrineBootstrap;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\WaitGroup;

use function Swoole\Coroutine\go;
use function Swoole\Coroutine\run;

#[RequiresPhpExtension('swoole')]
final class ConcurrentEmBorrowTest extends TestCase
{
    #[Test]
    public function eightCoroutinesShareTwoEms(): void
    {
        DoctrineBootstrap::enable();
        $results = [];
        run(function () use (&$results): void {
            $pool = DoctrineEmPool::forConfig(
                name: 'concurrent-em',
                connParams: ['url' => 'sqlite3:///:memory:'],
                ormSetup: ORMSetup::createAttributeMetadataConfiguration(paths: [], isDevMode: true),
                config: new EmPoolConfig(max: 2, minIdle: 0),
            );
            $wg = new WaitGroup();

            for ($i = 0; $i < 8; $i++) {
                $wg->add();
                go(function () use ($pool, $wg, $i, &$results): void {
                    $results[$i] = $pool->withEntityManager(
                        static fn(EntityManagerInterface $em): int => (int) $em->getConnection()->fetchOne('SELECT 42'),
                    );
                    $wg->done();
                });
            }

            $wg->wait();
        });

        self::assertCount(8, $results);
        foreach ($results as $r) {
            self::assertSame(42, $r);
        }
    }
}
```

- [ ] **Step 2: Run + commit**

```bash
docker compose exec -T php-swoole vendor/bin/phpunit tests/Integration/Doctrine/Swoole/ConcurrentEmBorrowTest.php
git add tests/Integration/Doctrine/Swoole/ConcurrentEmBorrowTest.php
git commit -m "test(doctrine-orm): Swoole concurrent EM borrow integration"
```

---

## Task 16: Update Makefile target

**Files:**
- Modify: `Makefile` — `test-doctrine` target extended

- [ ] **Step 1: Update target**

In `Makefile`, replace the `test-doctrine` target added in Plan 1's Task 26 with:

```makefile
test-doctrine:
	docker compose exec -T php-fiber vendor/bin/phpunit tests/Integration/Doctrine/Fiber/
	docker compose exec -T php-swoole vendor/bin/phpunit tests/Integration/Doctrine/Swoole/ tests/Integration/Doctrine/WorkerPool/
```

(Same as Plan 1 — verifying it still runs all Doctrine integration paths now that ORM tests landed.)

- [ ] **Step 2: Run + commit**

```bash
make test-doctrine
git add Makefile
git commit -m "chore(doctrine-orm): keep Makefile test-doctrine target current"
```

(If Makefile is already up to date from Plan 1, this commit is a no-op — skip.)

---

## Task 17: Final repo-wide gate

- [ ] **Step 1: Run all unit suites**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages
```
Expected: all green.

- [ ] **Step 2: Run all linters**

```bash
docker compose exec -T php-fiber vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose exec -T php-fiber vendor/bin/phpcs
docker compose exec -T php vendor/bin/psalm --no-cache
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyze
```
All green.

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

Expected: ~16 new commits since Plan 1's last task. Working tree clean (except the two pre-existing unstaged files).

- [ ] **Step 5: Push (with user approval)**

Ask the user before pushing. When approved:

```bash
git push origin feat/nexus-doctrine
```

---

## Self-review checklist (run BEFORE handing off)

- [ ] Every ORM-spec section in `2026-06-16-nexus-doctrine-async-design.md` (EM pool, EM lease, EM middleware, EM resolver, ORM `#[Transactional]`) is covered by a task here. `EntityBehavior` belongs to Plan 3 and is intentionally absent.
- [ ] No `TBD` / `TODO` strings in this plan: `grep -E 'TBD|TODO|FIXME' docs/superpowers/plans/2026-06-16-nexus-doctrine-orm-core.md`.
- [ ] Type and method names are consistent: `EntityManagerPool::take()` / `release()` / `withEntityManager()` / `close()` / `stats()`; `EntityManagerLease::get()` / `release()`; `PooledEntityManager::markBorrowed()` / `borrowCount()`; `EmPoolConfig` named-only.
- [ ] All commit messages use `feat(doctrine-orm):` / `test(doctrine-orm):` / `chore(doctrine-orm):` prefix.
- [ ] `nexus-doctrine-dbal` is untouched after Plan 1 (no retroactive modifications to `ActorPoolBinding` — Plan 2 introduces a sibling `OrmActorPoolBinding` instead, keeping DBAL fully independent of ORM).
- [ ] Plan 3 (`EntityBehavior`) deferred items are explicitly called out, not assumed delivered.

---

**Next plan:**

- **Plan 3 — `EntityBehavior` DSL:** `EntityEffect` (with `thenRun` / `thenReply` composers), `EntityReplayPolicy` (`Fail` / `CreateIfMissing` / `OnDemand`), `EntityBehaviorBuilder`, `EntityBehavior::create()`, internal runner that wires PreStart-load → command dispatch → flush, `EntityRefFactory` with single-writer naming, `EntityConflictException` (wraps `OptimisticLockException`), Psalm `EntityBehaviorReturnTypeProvider` + `MissingTransactionalDeclarationRule`.

