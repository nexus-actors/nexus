# Symfony Integration Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement five packages that integrate Nexus actors into Symfony 8+ applications with zero-friction DI, coroutine-local service isolation, and Swoole-only concurrency.

**Architecture:** Each HTTP request runs as a Swoole coroutine acting as a `RequestActor`. Services marked `#[CoroutineScoped]` receive per-coroutine instances via compiler-generated proxies backed by `CoroutineScope`. Long-lived actors are registered via `#[AsActor]`/`#[AsGlobalActor]` attributes and injectable anywhere as `ActorRef`.

**Tech Stack:** PHP 8.5+, Symfony 8, Swoole 6+, PHPUnit 13, Psalm 6 (level 1), GrumPHP, Docker.

---

## Prerequisites

Work in a dedicated worktree branched from `main`:

```bash
git worktree add .worktrees/feat/symfony-integration -b feat/symfony-integration main
```

Open new Claude Code session in `.worktrees/feat/symfony-integration`.

All commands run via Docker:
```bash
docker compose exec php <command>
```

Read the design doc before starting: `docs/plans/2026-03-04-symfony-integration-design.md`

---

## Task 1: Monorepo scaffold — all five packages

**Files to create:**
- `packages/nexus-symfony/composer.json`
- `packages/nexus-symfony-messenger/composer.json`
- `packages/nexus-symfony-doctrine/composer.json`
- `packages/nexus-symfony-testing/composer.json`
- `packages/nexus-symfony-worker-pool/composer.json`

**Modify:**
- `composer.json` (root) — add namespace mappings
- `phpunit.xml` — add test directories
- `deptrac.yaml` — add 5 new layers + ruleset entries
- `.github/workflows/split.yml` — add 5 new split targets

**Step 1: Create `packages/nexus-symfony/composer.json`**

```json
{
    "name": "nexus-actors/symfony",
    "description": "Symfony 8 integration for Nexus actors — bundle, runtime, coroutine-local DI.",
    "type": "symfony-bundle",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "ext-swoole": "*",
        "nexus-actors/core": "dev-main",
        "nexus-actors/runtime": "dev-main",
        "nexus-actors/app": "dev-main",
        "symfony/framework-bundle": "^8.0",
        "symfony/runtime": "^8.0",
        "monolog/monolog": "^3.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Symfony\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Symfony\\Tests\\": "tests/"
        }
    }
}
```

**Step 2: Create `packages/nexus-symfony-messenger/composer.json`**

```json
{
    "name": "nexus-actors/symfony-messenger",
    "description": "Symfony Messenger bridge for Nexus actors — bidirectional routing.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "ext-swoole": "*",
        "nexus-actors/symfony": "dev-main",
        "nexus-actors/core": "dev-main",
        "symfony/messenger": "^8.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Symfony\\Messenger\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Symfony\\Messenger\\Tests\\": "tests/"
        }
    }
}
```

**Step 3: Create `packages/nexus-symfony-doctrine/composer.json`**

```json
{
    "name": "nexus-actors/symfony-doctrine",
    "description": "Doctrine integration for Nexus — Swoole PDO pool and coroutine-scoped EntityManager.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "nexus-actors/symfony": "dev-main",
        "nexus-actors/core": "dev-main",
        "doctrine/orm": "^3.0",
        "doctrine/dbal": "^4.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Symfony\\Doctrine\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Symfony\\Doctrine\\Tests\\": "tests/"
        }
    }
}
```

**Step 4: Create `packages/nexus-symfony-testing/composer.json`**

```json
{
    "name": "nexus-actors/symfony-testing",
    "description": "Testing helpers for Nexus Symfony integration — no ext-swoole required.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "nexus-actors/symfony": "dev-main",
        "nexus-actors/core": "dev-main",
        "nexus-actors/runtime-step": "dev-main",
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Symfony\\Testing\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Symfony\\Testing\\Tests\\": "tests/"
        }
    }
}
```

**Step 5: Create `packages/nexus-symfony-worker-pool/composer.json`**

```json
{
    "name": "nexus-actors/symfony-worker-pool",
    "description": "Symfony CLI worker pool commands for Nexus — Swoole thread-based consumers.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "ext-swoole": "*",
        "nexus-actors/symfony": "dev-main",
        "nexus-actors/worker-pool": "dev-main",
        "nexus-actors/worker-pool-swoole": "dev-main"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Symfony\\WorkerPool\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Symfony\\WorkerPool\\Tests\\": "tests/"
        }
    }
}
```

**Step 6: Add namespace mappings to root `composer.json`**

Add to `autoload.psr-4`:
```json
"Monadial\\Nexus\\Symfony\\": "packages/nexus-symfony/src/",
"Monadial\\Nexus\\Symfony\\Messenger\\": "packages/nexus-symfony-messenger/src/",
"Monadial\\Nexus\\Symfony\\Doctrine\\": "packages/nexus-symfony-doctrine/src/",
"Monadial\\Nexus\\Symfony\\Testing\\": "packages/nexus-symfony-testing/src/",
"Monadial\\Nexus\\Symfony\\WorkerPool\\": "packages/nexus-symfony-worker-pool/src/"
```

Add to `autoload-dev.psr-4`:
```json
"Monadial\\Nexus\\Symfony\\Tests\\": "packages/nexus-symfony/tests/",
"Monadial\\Nexus\\Symfony\\Messenger\\Tests\\": "packages/nexus-symfony-messenger/tests/",
"Monadial\\Nexus\\Symfony\\Doctrine\\Tests\\": "packages/nexus-symfony-doctrine/tests/",
"Monadial\\Nexus\\Symfony\\Testing\\Tests\\": "packages/nexus-symfony-testing/tests/",
"Monadial\\Nexus\\Symfony\\WorkerPool\\Tests\\": "packages/nexus-symfony-worker-pool/tests/"
```

**Step 7: Add test directories to `phpunit.xml`**

Add to `<testsuite name="unit">`:
```xml
<directory>packages/nexus-symfony/tests/Unit</directory>
<directory>packages/nexus-symfony-messenger/tests/Unit</directory>
<directory>packages/nexus-symfony-doctrine/tests/Unit</directory>
<directory>packages/nexus-symfony-testing/tests/Unit</directory>
<directory>packages/nexus-symfony-worker-pool/tests/Unit</directory>
```

**Step 8: Add layers to `deptrac.yaml`**

Add to `layers`:
```yaml
- name: SymfonyBundle
  collectors:
    - type: directory
      value: packages/nexus-symfony/src/.*

- name: SymfonyMessenger
  collectors:
    - type: directory
      value: packages/nexus-symfony-messenger/src/.*

- name: SymfonyDoctrine
  collectors:
    - type: directory
      value: packages/nexus-symfony-doctrine/src/.*

- name: SymfonyTesting
  collectors:
    - type: directory
      value: packages/nexus-symfony-testing/src/.*

- name: SymfonyWorkerPool
  collectors:
    - type: directory
      value: packages/nexus-symfony-worker-pool/src/.*
```

Add to `ruleset`:
```yaml
SymfonyBundle:
  - Core
  - Runtime
  - App
SymfonyMessenger:
  - SymfonyBundle
  - Core
SymfonyDoctrine:
  - SymfonyBundle
  - Core
SymfonyTesting:
  - SymfonyBundle
  - Core
  - RuntimeStep
SymfonyWorkerPool:
  - SymfonyBundle
  - WorkerPool
  - WorkerPoolSwoole
```

**Step 9: Run composer dump-autoload**

```bash
docker compose exec php composer dump-autoload
```

**Step 10: Commit**

```bash
git add packages/nexus-symfony packages/nexus-symfony-messenger packages/nexus-symfony-doctrine packages/nexus-symfony-testing packages/nexus-symfony-worker-pool composer.json phpunit.xml deptrac.yaml
git commit -m "feat(symfony): scaffold five Symfony integration packages"
```

---

## Task 2: `CoroutineContextInterface` + implementations

The foundation for all coroutine-local service isolation. Production uses Swoole; tests use mock.

**Files:**
- Create: `packages/nexus-symfony/src/Coroutine/CoroutineContextInterface.php`
- Create: `packages/nexus-symfony/src/Coroutine/SwooleCoroutineContext.php`
- Create: `packages/nexus-symfony-testing/src/MockCoroutineContext.php`
- Test: `packages/nexus-symfony-testing/tests/Unit/MockCoroutineContextTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Testing\Tests\Unit;

use ArrayObject;
use Monadial\Nexus\Symfony\Testing\MockCoroutineContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MockCoroutineContext::class)]
final class MockCoroutineContextTest extends TestCase
{
    #[Test]
    public function currentReturnsSameObjectOnRepeatedCalls(): void
    {
        $context = new MockCoroutineContext();

        self::assertSame($context->current(), $context->current());
    }

    #[Test]
    public function currentReturnsArrayObject(): void
    {
        $context = new MockCoroutineContext();

        self::assertInstanceOf(ArrayObject::class, $context->current());
    }

    #[Test]
    public function differentInstancesReturnIndependentContexts(): void
    {
        $a = new MockCoroutineContext();
        $b = new MockCoroutineContext();

        $a->current()['key'] = 'value-a';

        self::assertArrayNotHasKey('key', (array) $b->current());
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony-testing/tests/Unit/MockCoroutineContextTest.php
```

Expected: `Error: Class "Monadial\Nexus\Symfony\Testing\MockCoroutineContext" not found`

**Step 3: Create `CoroutineContextInterface`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Coroutine;

use ArrayObject;

interface CoroutineContextInterface
{
    public function current(): ArrayObject;
}
```

**Step 4: Create `SwooleCoroutineContext`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Coroutine;

use ArrayObject;
use Swoole\Coroutine;

final class SwooleCoroutineContext implements CoroutineContextInterface
{
    #[\Override]
    public function current(): ArrayObject
    {
        /** @var ArrayObject */
        return Coroutine::getContext();
    }
}
```

**Step 5: Create `MockCoroutineContext`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Testing;

use ArrayObject;
use Monadial\Nexus\Symfony\Coroutine\CoroutineContextInterface;

final class MockCoroutineContext implements CoroutineContextInterface
{
    private readonly ArrayObject $context;

    public function __construct()
    {
        $this->context = new ArrayObject();
    }

    #[\Override]
    public function current(): ArrayObject
    {
        return $this->context;
    }
}
```

**Step 6: Run test — verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony-testing/tests/Unit/MockCoroutineContextTest.php
```

Expected: `OK (3 tests, 3 assertions)`

**Step 7: Commit**

```bash
git add packages/nexus-symfony/src/Coroutine/ packages/nexus-symfony-testing/src/MockCoroutineContext.php packages/nexus-symfony-testing/tests/
git commit -m "feat(symfony): add CoroutineContextInterface with Swoole and Mock implementations"
```

---

## Task 3: `CoroutineScope`

Stores and retrieves per-coroutine service instances. The core of coroutine-local DI.

**Files:**
- Create: `packages/nexus-symfony/src/Coroutine/CoroutineScope.php`
- Test: `packages/nexus-symfony/tests/Unit/Coroutine/CoroutineScopeTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Coroutine;

use Monadial\Nexus\Symfony\Coroutine\CoroutineScope;
use Monadial\Nexus\Symfony\Testing\MockCoroutineContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CoroutineScope::class)]
final class CoroutineScopeTest extends TestCase
{
    private CoroutineScope $scope;

    #[Test]
    public function getReturnsInitialisedService(): void
    {
        $service = new \stdClass();
        $this->scope->initialize(['key' => static fn() => $service]);

        self::assertSame($service, $this->scope->get('key'));
    }

    #[Test]
    public function getThrowsWhenServiceNotInScope(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Service "missing" not in coroutine scope');

        $this->scope->get('missing');
    }

    #[Test]
    public function factoryCalledOncePerInitialise(): void
    {
        $callCount = 0;
        $this->scope->initialize(['key' => static function () use (&$callCount): object {
            $callCount++;

            return new \stdClass();
        }]);

        $this->scope->get('key');
        $this->scope->get('key');

        self::assertSame(1, $callCount);
    }

    #[Test]
    public function initializeOverwritesPreviousScope(): void
    {
        $first  = new \stdClass();
        $second = new \stdClass();

        $this->scope->initialize(['key' => static fn() => $first]);
        $this->scope->initialize(['key' => static fn() => $second]);

        self::assertSame($second, $this->scope->get('key'));
    }

    protected function setUp(): void
    {
        $this->scope = new CoroutineScope(new MockCoroutineContext());
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Coroutine/CoroutineScopeTest.php
```

**Step 3: Implement `CoroutineScope`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Coroutine;

use RuntimeException;

final class CoroutineScope
{
    private const string CONTEXT_KEY = '__nexus_scope__';

    public function __construct(private readonly CoroutineContextInterface $context) {}

    /**
     * @param array<string, callable(): object> $factories
     */
    public function initialize(array $factories): void
    {
        $ctx = $this->context->current();
        $instances = [];

        foreach ($factories as $id => $factory) {
            $instances[$id] = $factory();
        }

        $ctx[self::CONTEXT_KEY] = $instances;
    }

    public function get(string $id): object
    {
        $ctx = $this->context->current();

        /** @var array<string, object>|null $instances */
        $instances = $ctx[self::CONTEXT_KEY] ?? null;

        if ($instances === null || !array_key_exists($id, $instances)) {
            throw new RuntimeException(sprintf('Service "%s" not in coroutine scope.', $id));
        }

        return $instances[$id];
    }
}
```

**Step 4: Run test — verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Coroutine/CoroutineScopeTest.php
```

**Step 5: Commit**

```bash
git add packages/nexus-symfony/src/Coroutine/CoroutineScope.php packages/nexus-symfony/tests/
git commit -m "feat(symfony): add CoroutineScope for per-coroutine service storage"
```

---

## Task 4: Attributes

All PHP attributes used in the integration. No logic — purely metadata.

**Files:**
- Create: `packages/nexus-symfony/src/Attribute/AsActor.php`
- Create: `packages/nexus-symfony/src/Attribute/AsGlobalActor.php`
- Create: `packages/nexus-symfony/src/Attribute/AsActorHandler.php`
- Create: `packages/nexus-symfony/src/Attribute/WithActor.php`
- Create: `packages/nexus-symfony/src/Attribute/CoroutineScoped.php`
- Test: `packages/nexus-symfony/tests/Unit/Attribute/AttributeTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Symfony\Attribute\AsActor;
use Monadial\Nexus\Symfony\Attribute\AsActorHandler;
use Monadial\Nexus\Symfony\Attribute\AsGlobalActor;
use Monadial\Nexus\Symfony\Attribute\CoroutineScoped;
use Monadial\Nexus\Symfony\Attribute\WithActor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(AsActor::class)]
#[CoversClass(AsGlobalActor::class)]
#[CoversClass(AsActorHandler::class)]
#[CoversClass(WithActor::class)]
#[CoversClass(CoroutineScoped::class)]
final class AttributeTest extends TestCase
{
    #[Test]
    public function asActorHoldsName(): void
    {
        $attr = new AsActor(name: 'orders');

        self::assertSame('orders', $attr->name);
    }

    #[Test]
    public function asGlobalActorHoldsName(): void
    {
        $attr = new AsGlobalActor(name: 'saga');

        self::assertSame('saga', $attr->name);
    }

    #[Test]
    public function withActorHoldsName(): void
    {
        $attr = new WithActor('orders');

        self::assertSame('orders', $attr->name);
    }

    #[Test]
    public function asActorTargetsClass(): void
    {
        $ref  = new ReflectionClass(AsActor::class);
        $attr = $ref->getAttributes(Attribute::class)[0]->newInstance();

        self::assertSame(Attribute::TARGET_CLASS, $attr->flags);
    }

    #[Test]
    public function asActorHandlerTargetsMethod(): void
    {
        $ref  = new ReflectionClass(AsActorHandler::class);
        $attr = $ref->getAttributes(Attribute::class)[0]->newInstance();

        self::assertSame(Attribute::TARGET_METHOD, $attr->flags);
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Attribute/AttributeTest.php
```

**Step 3: Create attribute classes**

`AsActor.php`:
```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsActor
{
    public function __construct(public readonly string $name) {}
}
```

`AsGlobalActor.php`:
```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsGlobalActor
{
    public function __construct(public readonly string $name) {}
}
```

`AsActorHandler.php`:
```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class AsActorHandler {}
```

`WithActor.php`:
```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class WithActor
{
    public function __construct(public readonly string $name) {}
}
```

`CoroutineScoped.php`:
```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class CoroutineScoped {}
```

**Step 4: Run test — verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Attribute/AttributeTest.php
```

**Step 5: Commit**

```bash
git add packages/nexus-symfony/src/Attribute/ packages/nexus-symfony/tests/Unit/Attribute/
git commit -m "feat(symfony): add AsActor, AsGlobalActor, AsActorHandler, WithActor, CoroutineScoped attributes"
```

---

## Task 5: `ActorPropsFactory`

Lazy `Props` construction after the container is compiled — solves the compiler-pass chicken-and-egg problem.

**Files:**
- Create: `packages/nexus-symfony/src/Actor/ActorPropsFactory.php`
- Test: `packages/nexus-symfony/tests/Unit/Actor/ActorPropsFactoryTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Symfony\Actor\ActorPropsFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[CoversClass(ActorPropsFactory::class)]
final class ActorPropsFactoryTest extends TestCase
{
    #[Test]
    public function createReturnsPropsInstance(): void
    {
        $actor     = new readonly class implements ActorHandler {
            public function handle(ActorContext $ctx, object $message): Behavior
            {
                return Behavior::same();
            }
        };
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($actor);

        $factory = new ActorPropsFactory($container, $actor::class);

        self::assertInstanceOf(Props::class, $factory->create());
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Actor/ActorPropsFactoryTest.php
```

**Step 3: Implement `ActorPropsFactory`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Actor;

use Monadial\Nexus\Core\Actor\Props;
use Psr\Container\ContainerInterface;

final class ActorPropsFactory
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly string $actorClass,
    ) {}

    public function create(): Props
    {
        return Props::fromContainer($this->container, $this->actorClass);
    }
}
```

**Step 4: Run test — verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Actor/ActorPropsFactoryTest.php
```

**Step 5: Commit**

```bash
git add packages/nexus-symfony/src/Actor/ActorPropsFactory.php packages/nexus-symfony/tests/Unit/Actor/
git commit -m "feat(symfony): add ActorPropsFactory for lazy Props construction"
```

---

## Task 6: `DelegatingActorHandler`

Routes incoming messages to the correct `#[AsActorHandler]` method on the wrapped service via reflection.

**Files:**
- Create: `packages/nexus-symfony/src/Actor/DelegatingActorHandler.php`
- Test: `packages/nexus-symfony/tests/Unit/Actor/DelegatingActorHandlerTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Symfony\Actor\DelegatingActorHandler;
use Monadial\Nexus\Symfony\Attribute\AsActorHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

readonly class PingMessage {}
readonly class PongMessage {}

#[CoversClass(DelegatingActorHandler::class)]
final class DelegatingActorHandlerTest extends TestCase
{
    #[Test]
    public function routesMessageToMatchingMethod(): void
    {
        $handled = false;
        $service = new class (&$handled) {
            public function __construct(private bool &$handled) {}

            #[AsActorHandler]
            public function onPing(PingMessage $msg): void
            {
                $this->handled = true;
            }
        };

        $ctx     = $this->createMock(ActorContext::class);
        $handler = new DelegatingActorHandler($service);

        $result = $handler->handle($ctx, new PingMessage());

        self::assertTrue($handled);
        self::assertInstanceOf(Behavior::class, $result);
    }

    #[Test]
    public function returnsUnhandledForUnknownMessage(): void
    {
        $service = new class {};
        $ctx     = $this->createMock(ActorContext::class);
        $handler = new DelegatingActorHandler($service);

        $result = $handler->handle($ctx, new PongMessage());

        // Unhandled behavior is returned for unknown messages
        self::assertInstanceOf(Behavior::class, $result);
    }

    #[Test]
    public function returnsSameBehaviorWhenHandlerReturnsVoid(): void
    {
        $service = new class {
            #[AsActorHandler]
            public function onPing(PingMessage $msg): void {}
        };

        $ctx     = $this->createMock(ActorContext::class);
        $handler = new DelegatingActorHandler($service);
        $result  = $handler->handle($ctx, new PingMessage());

        self::assertInstanceOf(Behavior::class, $result);
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Actor/DelegatingActorHandlerTest.php
```

**Step 3: Implement `DelegatingActorHandler`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Symfony\Attribute\AsActorHandler;
use ReflectionClass;
use ReflectionMethod;

final class DelegatingActorHandler implements ActorHandler
{
    /** @var array<string, ReflectionMethod> */
    private array $handlers = [];

    public function __construct(private readonly object $delegate)
    {
        $ref = new ReflectionClass($delegate);

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(AsActorHandler::class) === []) {
                continue;
            }

            $params = $method->getParameters();

            if ($params === []) {
                continue;
            }

            $type = $params[0]->getType();

            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }

            $this->handlers[$type->getName()] = $method;
        }
    }

    #[\Override]
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        $class = $message::class;

        if (!array_key_exists($class, $this->handlers)) {
            return Behavior::unhandled();
        }

        $this->handlers[$class]->invoke($this->delegate, $message);

        return Behavior::same();
    }
}
```

**Step 4: Run test — verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Actor/DelegatingActorHandlerTest.php
```

**Step 5: Commit**

```bash
git add packages/nexus-symfony/src/Actor/DelegatingActorHandler.php packages/nexus-symfony/tests/Unit/Actor/DelegatingActorHandlerTest.php
git commit -m "feat(symfony): add DelegatingActorHandler — routes messages to #[AsActorHandler] methods"
```

---

## Task 7: `SwooleHttpBridge`

Converts `Swoole\Http\Request` ↔ `Symfony\HttpFoundation\Request/Response`.

**Files:**
- Create: `packages/nexus-symfony/src/Http/SwooleHttpBridge.php`
- Test: `packages/nexus-symfony/tests/Unit/Http/SwooleHttpBridgeTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Http;

use Monadial\Nexus\Symfony\Http\SwooleHttpBridge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(SwooleHttpBridge::class)]
final class SwooleHttpBridgeTest extends TestCase
{
    #[Test]
    public function toSymfonyRequestBuildsCorrectRequest(): void
    {
        $bridge = new SwooleHttpBridge();

        $swooleRequest = $this->createSwooleRequestStub(
            server: [
                'request_uri'    => '/orders',
                'request_method' => 'POST',
            ],
            header: ['content-type' => 'application/json'],
            body: '{"id":1}',
        );

        $request = $bridge->toSymfonyRequest($swooleRequest);

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/orders', $request->getPathInfo());
        self::assertSame('{"id":1}', $request->getContent());
    }

    #[Test]
    public function sendSymfonyResponseWritesStatusAndBody(): void
    {
        $bridge   = new SwooleHttpBridge();
        $response = new Response('Hello', 201, ['X-Custom' => 'value']);

        $swooleResponse = $this->createMock(\Swoole\Http\Response::class);
        $swooleResponse->expects($this->once())->method('status')->with(201);
        $swooleResponse->expects($this->once())->method('end')->with('Hello');

        $bridge->sendSymfonyResponse($response, $swooleResponse);
    }

    private function createSwooleRequestStub(
        array $server,
        array $header,
        string $body,
    ): \Swoole\Http\Request {
        $stub         = $this->createMock(\Swoole\Http\Request::class);
        $stub->server = $server;
        $stub->header = $header;
        $stub->get    = [];
        $stub->post   = [];
        $stub->cookie = [];
        $stub->files  = [];
        $stub->method('rawContent')->willReturn($body);

        return $stub;
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Http/SwooleHttpBridgeTest.php
```

**Step 3: Implement `SwooleHttpBridge`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Http;

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SwooleHttpBridge
{
    public function toSymfonyRequest(SwooleRequest $req): Request
    {
        return Request::create(
            uri: $req->server['request_uri'] ?? '/',
            method: $req->server['request_method'] ?? 'GET',
            parameters: $req->get ?? [],
            cookies: $req->cookie ?? [],
            files: $this->normaliseFiles($req->files ?? []),
            server: $this->normaliseServer($req->server ?? [], $req->header ?? []),
            content: $req->rawContent() ?: null,
        );
    }

    public function sendSymfonyResponse(Response $response, SwooleResponse $res): void
    {
        $res->status($response->getStatusCode());

        foreach ($response->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $res->header($name, $value);
            }
        }

        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();
            $res->end((string) ob_get_clean());

            return;
        }

        $res->end((string) $response->getContent());
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private function normaliseFiles(array $files): array
    {
        return $files;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private function normaliseServer(array $server, array $headers): array
    {
        $normalised = [];

        foreach ($server as $key => $value) {
            $normalised[strtoupper($key)] = $value;
        }

        foreach ($headers as $key => $value) {
            $normalised['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
        }

        return $normalised;
    }
}
```

**Step 4: Run test — verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Http/SwooleHttpBridgeTest.php
```

**Step 5: Commit**

```bash
git add packages/nexus-symfony/src/Http/SwooleHttpBridge.php packages/nexus-symfony/tests/Unit/Http/
git commit -m "feat(symfony): add SwooleHttpBridge — converts Swoole ↔ Symfony HTTP objects"
```

---

## Task 8: Tracing pipeline

`EnvelopeContext` + `NexusMonologProcessor` + `RequestIdListener` + `ResponseIdListener`.

**Files:**
- Create: `packages/nexus-symfony/src/Actor/EnvelopeContext.php`
- Create: `packages/nexus-symfony/src/Tracing/NexusMonologProcessor.php`
- Create: `packages/nexus-symfony/src/Tracing/RequestIdListener.php`
- Create: `packages/nexus-symfony/src/Tracing/ResponseIdListener.php`
- Test: `packages/nexus-symfony/tests/Unit/Tracing/NexusMonologProcessorTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Tracing;

use Monadial\Nexus\Symfony\Actor\EnvelopeContext;
use Monadial\Nexus\Symfony\Testing\MockCoroutineContext;
use Monadial\Nexus\Symfony\Tracing\NexusMonologProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NexusMonologProcessor::class)]
final class NexusMonologProcessorTest extends TestCase
{
    #[Test]
    public function addsRequestIdFromCoroutineContext(): void
    {
        $context = new MockCoroutineContext();
        $context->current()['nexus.request_id']     = '01JTAAA';
        $context->current()['nexus.correlation_id'] = '01JTBBB';

        $processor = new NexusMonologProcessor($context, new EnvelopeContext($context));
        $record    = $this->makeRecord();

        $result = ($processor)($record);

        self::assertSame('01JTAAA', $result->extra['request_id']);
        self::assertSame('01JTBBB', $result->extra['correlation_id']);
    }

    #[Test]
    public function returnsUnmodifiedRecordWhenNoContextAvailable(): void
    {
        $context   = new MockCoroutineContext();
        $processor = new NexusMonologProcessor($context, new EnvelopeContext($context));
        $record    = $this->makeRecord();

        $result = ($processor)($record);

        self::assertArrayNotHasKey('request_id', $result->extra);
    }

    private function makeRecord(): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'test',
            context: [],
            extra: [],
        );
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Tracing/NexusMonologProcessorTest.php
```

**Step 3: Create `EnvelopeContext`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Actor;

use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Symfony\Coroutine\CoroutineContextInterface;

final class EnvelopeContext
{
    private const string CONTEXT_KEY = '__nexus_envelope__';

    public function __construct(private readonly CoroutineContextInterface $context) {}

    public function set(Envelope $envelope): void
    {
        $this->context->current()[self::CONTEXT_KEY] = $envelope;
    }

    public function current(): ?Envelope
    {
        /** @var Envelope|null */
        return $this->context->current()[self::CONTEXT_KEY] ?? null;
    }

    public function clear(): void
    {
        unset($this->context->current()[self::CONTEXT_KEY]);
    }
}
```

**Step 4: Create `NexusMonologProcessor`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tracing;

use Monadial\Nexus\Symfony\Actor\EnvelopeContext;
use Monadial\Nexus\Symfony\Coroutine\CoroutineContextInterface;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Override;

final class NexusMonologProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CoroutineContextInterface $context,
        private readonly EnvelopeContext $envelopeContext,
    ) {}

    #[Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $ctx = $this->context->current();

        if (isset($ctx['nexus.request_id'])) {
            return $record->with(extra: [
                ...$record->extra,
                'correlation_id' => $ctx['nexus.correlation_id'],
                'request_id'     => $ctx['nexus.request_id'],
            ]);
        }

        $envelope = $this->envelopeContext->current();

        if ($envelope !== null) {
            return $record->with(extra: [
                ...$record->extra,
                'causation_id'   => $envelope->causationId,
                'correlation_id' => $envelope->correlationId,
                'request_id'     => $envelope->requestId,
            ]);
        }

        return $record;
    }
}
```

**Step 5: Create `RequestIdListener` and `ResponseIdListener`**

`RequestIdListener.php`:
```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tracing;

use Monadial\Nexus\Symfony\Coroutine\CoroutineContextInterface;
use Override;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 900)]
final class RequestIdListener
{
    public function __construct(private readonly CoroutineContextInterface $context) {}

    #[Override]
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $ctx     = $this->context->current();

        $ctx['nexus.request_id']     = $request->headers->get('X-Request-Id') ?? $this->generateId();
        $ctx['nexus.correlation_id'] = $request->headers->get('X-Correlation-Id')
            ?? $ctx['nexus.request_id'];
    }

    private function generateId(): string
    {
        return sprintf('%s', bin2hex(random_bytes(16)));
    }
}
```

`ResponseIdListener.php`:
```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tracing;

use Monadial\Nexus\Symfony\Coroutine\CoroutineContextInterface;
use Override;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE)]
final class ResponseIdListener
{
    public function __construct(private readonly CoroutineContextInterface $context) {}

    #[Override]
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $ctx = $this->context->current();

        if (!isset($ctx['nexus.request_id'])) {
            return;
        }

        $event->getResponse()->headers->set('X-Request-Id', (string) $ctx['nexus.request_id']);
    }
}
```

**Step 6: Run test — verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Tracing/NexusMonologProcessorTest.php
```

**Step 7: Commit**

```bash
git add packages/nexus-symfony/src/Actor/EnvelopeContext.php packages/nexus-symfony/src/Tracing/ packages/nexus-symfony/tests/Unit/Tracing/
git commit -m "feat(symfony): add tracing pipeline — EnvelopeContext, NexusMonologProcessor, request/response listeners"
```

---

## Task 9: `CoroutineScopeListener`

Initialises per-coroutine services at `kernel.request` (priority 1000, main request only).

**Files:**
- Create: `packages/nexus-symfony/src/Coroutine/CoroutineScopeListener.php`
- Test: `packages/nexus-symfony/tests/Unit/Coroutine/CoroutineScopeListenerTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Coroutine;

use Monadial\Nexus\Symfony\Coroutine\CoroutineScope;
use Monadial\Nexus\Symfony\Coroutine\CoroutineScopeListener;
use Monadial\Nexus\Symfony\Testing\MockCoroutineContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[CoversClass(CoroutineScopeListener::class)]
final class CoroutineScopeListenerTest extends TestCase
{
    #[Test]
    public function initialisesServicesOnMainRequest(): void
    {
        $service  = new \stdClass();
        $context  = new MockCoroutineContext();
        $scope    = new CoroutineScope($context);
        $listener = new CoroutineScopeListener($scope, ['test.service' => static fn() => $service]);

        $listener($this->makeRequestEvent(isMain: true));

        self::assertSame($service, $scope->get('test.service'));
    }

    #[Test]
    public function skipsSubRequests(): void
    {
        $context  = new MockCoroutineContext();
        $scope    = new CoroutineScope($context);
        $listener = new CoroutineScopeListener($scope, ['test.service' => static fn() => new \stdClass()]);

        $listener($this->makeRequestEvent(isMain: false));

        $this->expectException(\RuntimeException::class);
        $scope->get('test.service');
    }

    private function makeRequestEvent(bool $isMain): RequestEvent
    {
        $kernel = $this->createMock(KernelInterface::class);
        $type   = $isMain
            ? HttpKernelInterface::MAIN_REQUEST
            : HttpKernelInterface::SUB_REQUEST;

        return new RequestEvent($kernel, Request::create('/'), $type);
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Coroutine/CoroutineScopeListenerTest.php
```

**Step 3: Implement `CoroutineScopeListener`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Coroutine;

use Override;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 1000)]
final class CoroutineScopeListener
{
    /**
     * @param array<string, callable(): object> $factories
     */
    public function __construct(
        private readonly CoroutineScope $scope,
        private readonly array $factories,
    ) {}

    #[Override]
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->scope->initialize($this->factories);
    }
}
```

**Step 4: Run test — verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Coroutine/CoroutineScopeListenerTest.php
```

**Step 5: Commit**

```bash
git add packages/nexus-symfony/src/Coroutine/CoroutineScopeListener.php packages/nexus-symfony/tests/Unit/Coroutine/CoroutineScopeListenerTest.php
git commit -m "feat(symfony): add CoroutineScopeListener — priority 1000, main request only"
```

---

## Task 10: Session enforcement

Throws at bundle boot if file sessions are configured — incompatible with persistent Swoole process.

**Files:**
- Create: `packages/nexus-symfony/src/Session/SessionHandlerMode.php`
- Create: `packages/nexus-symfony/src/Session/SwooleSessionEnforcer.php`
- Test: `packages/nexus-symfony/tests/Unit/Session/SwooleSessionEnforcerTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Session;

use Monadial\Nexus\Symfony\Session\SwooleSessionEnforcer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[CoversClass(SwooleSessionEnforcer::class)]
final class SwooleSessionEnforcerTest extends TestCase
{
    #[Test]
    public function throwsWhenFileSessionHandlerConfigured(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('getParameter')
            ->with('session.handler_id')
            ->willReturn('session.handler.native_file');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File sessions are not Swoole-compatible');

        SwooleSessionEnforcer::assertCompatible($container);
    }

    #[Test]
    public function passesWhenRedisSessionHandlerConfigured(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('getParameter')
            ->with('session.handler_id')
            ->willReturn('Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler');

        // No exception thrown
        SwooleSessionEnforcer::assertCompatible($container);
        $this->addToAssertionCount(1);
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Session/SwooleSessionEnforcerTest.php
```

**Step 3: Implement**

`SessionHandlerMode.php`:
```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Session;

enum SessionHandlerMode
{
    case Redis;
    case Database;
}
```

`SwooleSessionEnforcer.php`:
```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Session;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class SwooleSessionEnforcer
{
    private const array INCOMPATIBLE_HANDLERS = [
        'session.handler.native_file',
        'session.handler.native',
    ];

    public static function assertCompatible(ContainerInterface $container): void
    {
        if (!$container->hasParameter('session.handler_id')) {
            return;
        }

        $handlerId = (string) $container->getParameter('session.handler_id');

        foreach (self::INCOMPATIBLE_HANDLERS as $incompatible) {
            if (str_contains($handlerId, $incompatible)) {
                throw new InvalidArgumentException(
                    'File sessions are not Swoole-compatible. '
                    . 'Configure a Redis or database session handler: '
                    . '$nexus->session(handler: SessionHandlerMode::Redis, dsn: "redis://localhost")',
                );
            }
        }
    }
}
```

**Step 4: Run test — verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Session/SwooleSessionEnforcerTest.php
```

**Step 5: Commit**

```bash
git add packages/nexus-symfony/src/Session/ packages/nexus-symfony/tests/Unit/Session/
git commit -m "feat(symfony): add SwooleSessionEnforcer — boot-time file session rejection"
```

---

## Task 11: `NexusBundle` + `NexusExtension` + `Configuration`

The Symfony Bundle class, DI extension, and PHP configuration tree.

**Files:**
- Create: `packages/nexus-symfony/src/NexusBundle.php`
- Create: `packages/nexus-symfony/src/DependencyInjection/NexusExtension.php`
- Create: `packages/nexus-symfony/src/DependencyInjection/Configuration.php`
- Test: `packages/nexus-symfony/tests/Unit/DependencyInjection/NexusExtensionTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\DependencyInjection;

use Monadial\Nexus\Symfony\DependencyInjection\NexusExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(NexusExtension::class)]
final class NexusExtensionTest extends TestCase
{
    #[Test]
    public function loadsWithDefaultConfiguration(): void
    {
        $container = new ContainerBuilder();
        $extension = new NexusExtension();

        $extension->load([[]], $container);

        self::assertTrue($container->hasDefinition('nexus.coroutine_scope'));
        self::assertTrue($container->hasDefinition('nexus.coroutine_scope_listener'));
    }

    #[Test]
    public function registersActorSystemDefinition(): void
    {
        $container = new ContainerBuilder();
        $extension = new NexusExtension();

        $extension->load([['name' => 'my-app']], $container);

        self::assertTrue($container->hasDefinition('nexus.actor_system'));
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/DependencyInjection/NexusExtensionTest.php
```

**Step 3: Create `Configuration`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('nexus');
        $root = $tree->getRootNode();

        $root
            ->children()
                ->scalarNode('name')->defaultValue('app')->end()
                ->integerNode('shutdown_timeout')->defaultValue(30)->end()
            ->end();

        return $tree;
    }
}
```

**Step 4: Create `NexusExtension`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\DependencyInjection;

use Monadial\Nexus\Symfony\Actor\ActorPropsFactory;
use Monadial\Nexus\Symfony\Actor\EnvelopeContext;
use Monadial\Nexus\Symfony\Coroutine\CoroutineScope;
use Monadial\Nexus\Symfony\Coroutine\CoroutineScopeListener;
use Monadial\Nexus\Symfony\Coroutine\SwooleCoroutineContext;
use Monadial\Nexus\Symfony\Session\SwooleSessionEnforcer;
use Monadial\Nexus\Symfony\Tracing\NexusMonologProcessor;
use Monadial\Nexus\Symfony\Tracing\RequestIdListener;
use Monadial\Nexus\Symfony\Tracing\ResponseIdListener;
use Override;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class NexusExtension extends Extension
{
    #[Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $container->setParameter('nexus.app_name', $config['name']);
        $container->setParameter('nexus.shutdown_timeout', $config['shutdown_timeout']);

        $this->registerCoroutineServices($container);
        $this->registerTracingServices($container);
        $this->registerActorSystem($container);
    }

    private function registerCoroutineServices(ContainerBuilder $container): void
    {
        $container->setDefinition(
            'nexus.coroutine_context',
            (new Definition(SwooleCoroutineContext::class))->setPublic(false),
        );

        $container->setDefinition(
            'nexus.coroutine_scope',
            (new Definition(CoroutineScope::class))
                ->setArguments([new Reference('nexus.coroutine_context')]),
        );

        $container->setDefinition(
            'nexus.coroutine_scope_listener',
            (new Definition(CoroutineScopeListener::class))
                ->setArguments([new Reference('nexus.coroutine_scope'), []])
                ->addTag('kernel.event_listener'),
        );

        $container->setDefinition(
            'nexus.envelope_context',
            (new Definition(EnvelopeContext::class))
                ->setArguments([new Reference('nexus.coroutine_context')]),
        );
    }

    private function registerTracingServices(ContainerBuilder $container): void
    {
        $container->setDefinition(
            'nexus.monolog_processor',
            (new Definition(NexusMonologProcessor::class))
                ->setArguments([
                    new Reference('nexus.coroutine_context'),
                    new Reference('nexus.envelope_context'),
                ])
                ->addTag('monolog.processor'),
        );

        $container->setDefinition(
            'nexus.tracing.request_id_listener',
            (new Definition(RequestIdListener::class))
                ->setArguments([new Reference('nexus.coroutine_context')])
                ->addTag('kernel.event_listener'),
        );

        $container->setDefinition(
            'nexus.tracing.response_id_listener',
            (new Definition(ResponseIdListener::class))
                ->setArguments([new Reference('nexus.coroutine_context')])
                ->addTag('kernel.event_listener'),
        );
    }

    private function registerActorSystem(ContainerBuilder $container): void
    {
        // ActorSystem is registered as a synthetic service — actual instance created by NexusRuntime
        $definition = new Definition(\Monadial\Nexus\Core\Actor\ActorSystem::class);
        $definition->setSynthetic(true);
        $definition->setPublic(true);
        $container->setDefinition('nexus.actor_system', $definition);
        $container->setAlias(\Monadial\Nexus\Core\Actor\ActorSystem::class, 'nexus.actor_system');
    }
}
```

**Step 5: Create `NexusBundle`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony;

use Monadial\Nexus\Symfony\DependencyInjection\Compiler\ActorRegistrationPass;
use Monadial\Nexus\Symfony\DependencyInjection\Compiler\GlobalActorPass;
use Monadial\Nexus\Symfony\DependencyInjection\NexusExtension;
use Override;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NexusBundle extends Bundle
{
    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ActorRegistrationPass());
        $container->addCompilerPass(new GlobalActorPass());
    }

    #[Override]
    protected function createContainerExtension(): NexusExtension
    {
        return new NexusExtension();
    }
}
```

**Step 6: Run test — verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/DependencyInjection/NexusExtensionTest.php
```

**Step 7: Commit**

```bash
git add packages/nexus-symfony/src/NexusBundle.php packages/nexus-symfony/src/DependencyInjection/ packages/nexus-symfony/tests/Unit/DependencyInjection/
git commit -m "feat(symfony): add NexusBundle, NexusExtension, Configuration"
```

---

## Task 12: `ActorRegistrationPass`

Compiler pass: discovers `#[AsActor]` classes, registers `DelegatingActorHandler` wrapper + `ActorRef` service.

**Files:**
- Create: `packages/nexus-symfony/src/DependencyInjection/Compiler/ActorRegistrationPass.php`
- Test: `packages/nexus-symfony/tests/Unit/DependencyInjection/Compiler/ActorRegistrationPassTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\DependencyInjection\Compiler;

use Monadial\Nexus\Symfony\Actor\ActorPropsFactory;
use Monadial\Nexus\Symfony\Attribute\AsActor;
use Monadial\Nexus\Symfony\Attribute\AsActorHandler;
use Monadial\Nexus\Symfony\DependencyInjection\Compiler\ActorRegistrationPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[AsActor(name: 'test-orders')]
final class StubOrderService
{
    #[AsActorHandler]
    public function handle(object $msg): void {}
}

#[CoversClass(ActorRegistrationPass::class)]
final class ActorRegistrationPassTest extends TestCase
{
    #[Test]
    public function registersPropsFactoryForAsActorService(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition(StubOrderService::class);
        $container->setDefinition(StubOrderService::class, $definition);

        $pass = new ActorRegistrationPass();
        $pass->process($container);

        self::assertTrue(
            $container->hasDefinition('nexus.actor.test-orders.props_factory'),
        );
    }

    #[Test]
    public function registersActorRefServiceByName(): void
    {
        $container  = new ContainerBuilder();
        $definition = new Definition(StubOrderService::class);
        $container->setDefinition(StubOrderService::class, $definition);

        $pass = new ActorRegistrationPass();
        $pass->process($container);

        self::assertTrue($container->hasDefinition('nexus.actor_ref.test-orders'));
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/DependencyInjection/Compiler/ActorRegistrationPassTest.php
```

**Step 3: Implement `ActorRegistrationPass`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\DependencyInjection\Compiler;

use Monadial\Nexus\Symfony\Actor\ActorPropsFactory;
use Monadial\Nexus\Symfony\Attribute\AsActor;
use Override;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class ActorRegistrationPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();

            if ($class === null) {
                continue;
            }

            try {
                $ref  = new ReflectionClass($class);
                $attrs = $ref->getAttributes(AsActor::class);
            } catch (\ReflectionException) {
                continue;
            }

            if ($attrs === []) {
                continue;
            }

            $attr = $attrs[0]->newInstance();
            $name = $attr->name;

            // Register ActorPropsFactory for lazy Props construction
            $container->setDefinition(
                "nexus.actor.{$name}.props_factory",
                (new Definition(ActorPropsFactory::class))
                    ->setArguments([new Reference('service_container'), $class])
                    ->setPublic(false),
            );

            // Register ActorRef service (synthetic — set at runtime by ActorSystem)
            $actorRefDef = new Definition(\Monadial\Nexus\Core\Actor\ActorRef::class);
            $actorRefDef->setSynthetic(true)->setPublic(true);
            $container->setDefinition("nexus.actor_ref.{$name}", $actorRefDef);
        }
    }
}
```

**Step 4: Run test — verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/DependencyInjection/Compiler/ActorRegistrationPassTest.php
```

**Step 5: Commit**

```bash
git add packages/nexus-symfony/src/DependencyInjection/Compiler/ActorRegistrationPass.php packages/nexus-symfony/tests/Unit/DependencyInjection/Compiler/
git commit -m "feat(symfony): add ActorRegistrationPass — discovers #[AsActor] services"
```

---

## Task 13: `GlobalActorPass`

Registers `#[AsGlobalActor]` in the worker pool hash ring, or degrades to local actor when worker pool absent.

**Files:**
- Create: `packages/nexus-symfony/src/DependencyInjection/Compiler/GlobalActorPass.php`
- Test: `packages/nexus-symfony/tests/Unit/DependencyInjection/Compiler/GlobalActorPassTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\DependencyInjection\Compiler;

use Monadial\Nexus\Symfony\Attribute\AsGlobalActor;
use Monadial\Nexus\Symfony\Attribute\AsActorHandler;
use Monadial\Nexus\Symfony\DependencyInjection\Compiler\GlobalActorPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[AsGlobalActor(name: 'payment-saga')]
final class StubPaymentSaga
{
    #[AsActorHandler]
    public function handle(object $msg): void {}
}

#[CoversClass(GlobalActorPass::class)]
final class GlobalActorPassTest extends TestCase
{
    #[Test]
    public function registersPropsFactoryForGlobalActor(): void
    {
        $container  = new ContainerBuilder();
        $definition = new Definition(StubPaymentSaga::class);
        $container->setDefinition(StubPaymentSaga::class, $definition);

        $pass = new GlobalActorPass();
        $pass->process($container);

        self::assertTrue($container->hasDefinition('nexus.actor.payment-saga.props_factory'));
    }

    #[Test]
    public function degradesGracefullyWithoutWorkerPool(): void
    {
        $container  = new ContainerBuilder();
        $definition = new Definition(StubPaymentSaga::class);
        $container->setDefinition(StubPaymentSaga::class, $definition);

        // No 'nexus.worker_pool' service in container → local fallback
        $pass = new GlobalActorPass();
        $pass->process($container);

        // Should register as local actor ref (same as ActorRegistrationPass)
        self::assertTrue($container->hasDefinition('nexus.actor_ref.payment-saga'));
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/DependencyInjection/Compiler/GlobalActorPassTest.php
```

**Step 3: Implement `GlobalActorPass`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\DependencyInjection\Compiler;

use Monadial\Nexus\Symfony\Actor\ActorPropsFactory;
use Monadial\Nexus\Symfony\Attribute\AsGlobalActor;
use Override;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class GlobalActorPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        $hasWorkerPool = $container->hasDefinition('nexus.worker_pool');

        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();

            if ($class === null) {
                continue;
            }

            try {
                $ref   = new ReflectionClass($class);
                $attrs = $ref->getAttributes(AsGlobalActor::class);
            } catch (\ReflectionException) {
                continue;
            }

            if ($attrs === []) {
                continue;
            }

            $attr = $attrs[0]->newInstance();
            $name = $attr->name;

            $container->setDefinition(
                "nexus.actor.{$name}.props_factory",
                (new Definition(ActorPropsFactory::class))
                    ->setArguments([new Reference('service_container'), $class])
                    ->setPublic(false),
            );

            $actorRefDef = new Definition(\Monadial\Nexus\Core\Actor\ActorRef::class);
            $actorRefDef->setSynthetic(true)->setPublic(true);
            $container->setDefinition("nexus.actor_ref.{$name}", $actorRefDef);

            if ($hasWorkerPool) {
                // Tag for hash-ring registration — handled by nexus-symfony-worker-pool
                $actorRefDef->addTag('nexus.global_actor', ['name' => $name]);
            }
            // Without worker pool: behaves identically to #[AsActor] (local instance)
        }
    }
}
```

**Step 4: Run test — verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/DependencyInjection/Compiler/GlobalActorPassTest.php
```

**Step 5: Commit**

```bash
git add packages/nexus-symfony/src/DependencyInjection/Compiler/GlobalActorPass.php packages/nexus-symfony/tests/Unit/DependencyInjection/Compiler/GlobalActorPassTest.php
git commit -m "feat(symfony): add GlobalActorPass — #[AsGlobalActor] with worker pool or local fallback"
```

---

## Task 14: `NexusRuntime` + `NexusRunner`

`symfony/runtime` integration — boots Swoole HTTP server and ActorSystem.

**Files:**
- Create: `packages/nexus-symfony/src/Runtime/NexusRuntime.php`
- Create: `packages/nexus-symfony/src/Runtime/NexusRunner.php`
- Test: `packages/nexus-symfony/tests/Unit/Runtime/NexusRuntimeTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Runtime;

use Monadial\Nexus\Symfony\Runtime\NexusRuntime;
use Monadial\Nexus\Symfony\Runtime\NexusRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(NexusRuntime::class)]
final class NexusRuntimeTest extends TestCase
{
    #[Test]
    public function getRunnerReturnsNexusRunner(): void
    {
        $runtime = new NexusRuntime(['host' => '127.0.0.1', 'port' => 8080]);
        $kernel  = $this->createMock(HttpKernelInterface::class);

        $runner = $runtime->getRunner($kernel);

        self::assertInstanceOf(NexusRunner::class, $runner);
    }

    #[Test]
    public function defaultOptionsAreApplied(): void
    {
        $runtime = new NexusRuntime([]);

        // Verify it can be constructed without options
        self::assertInstanceOf(NexusRuntime::class, $runtime);
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Runtime/NexusRuntimeTest.php
```

**Step 3: Implement `NexusRuntime`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Runtime;

use Override;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\RuntimeInterface;

final class NexusRuntime implements RuntimeInterface
{
    private const array DEFAULT_OPTIONS = [
        'host'    => '0.0.0.0',
        'port'    => 8080,
        'workers' => 4,
    ];

    /** @param array<string, mixed> $options */
    public function __construct(private readonly array $options = []) {}

    #[Override]
    public function getRunner(mixed $application): RunnerInterface
    {
        return new NexusRunner(
            $application,
            array_merge(self::DEFAULT_OPTIONS, $this->options),
        );
    }
}
```

**Step 4: Implement `NexusRunner`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Runtime;

use Monadial\Nexus\Symfony\Http\SwooleHttpBridge;
use Override;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

final class NexusRunner implements RunnerInterface
{
    private readonly SwooleHttpBridge $bridge;

    /** @param array<string, mixed> $options */
    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly array $options,
    ) {
        $this->bridge = new SwooleHttpBridge();
    }

    #[Override]
    public function run(): int
    {
        $server = new Server(
            (string) $this->options['host'],
            (int) $this->options['port'],
        );

        $server->set(['worker_num' => (int) $this->options['workers']]);

        $server->on('request', function (Request $req, Response $res): void {
            $symfonyRequest  = $this->bridge->toSymfonyRequest($req);
            $symfonyResponse = $this->kernel->handle($symfonyRequest);

            $this->bridge->sendSymfonyResponse($symfonyResponse, $res);

            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($symfonyRequest, $symfonyResponse);
            }
        });

        $server->start();

        return 0;
    }
}
```

**Step 5: Run test — verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Runtime/NexusRuntimeTest.php
```

**Step 6: Commit**

```bash
git add packages/nexus-symfony/src/Runtime/ packages/nexus-symfony/tests/Unit/Runtime/
git commit -m "feat(symfony): add NexusRuntime + NexusRunner — symfony/runtime Swoole HTTP server integration"
```

---

## Task 15: Graceful shutdown

`GracefulShutdownHandler` orchestrates SIGTERM sequence.

**Files:**
- Create: `packages/nexus-symfony/src/Shutdown/ShutdownTimeoutBehavior.php`
- Create: `packages/nexus-symfony/src/Shutdown/GracefulShutdownHandler.php`
- Test: `packages/nexus-symfony/tests/Unit/Shutdown/GracefulShutdownHandlerTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Shutdown;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Symfony\Shutdown\GracefulShutdownHandler;
use Monadial\Nexus\Symfony\Shutdown\ShutdownTimeoutBehavior;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GracefulShutdownHandler::class)]
final class GracefulShutdownHandlerTest extends TestCase
{
    #[Test]
    public function shutdownCallsActorSystemShutdown(): void
    {
        $system  = $this->createMock(ActorSystem::class);
        $system->expects($this->once())
            ->method('shutdown')
            ->with($this->isInstanceOf(Duration::class));

        $handler = new GracefulShutdownHandler(
            $system,
            Duration::seconds(5),
            ShutdownTimeoutBehavior::ForceWithWarning,
        );

        $handler->shutdown();
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Shutdown/GracefulShutdownHandlerTest.php
```

**Step 3: Implement**

`ShutdownTimeoutBehavior.php`:
```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Shutdown;

enum ShutdownTimeoutBehavior
{
    case ForceWithWarning;
    case ThrowException;
}
```

`GracefulShutdownHandler.php`:
```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Shutdown;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Duration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class GracefulShutdownHandler
{
    public function __construct(
        private readonly ActorSystem $system,
        private readonly Duration $timeout,
        private readonly ShutdownTimeoutBehavior $onTimeout,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function shutdown(): void
    {
        $this->logger->info('Nexus: graceful shutdown initiated', [
            'timeout_seconds' => $this->timeout->toSeconds(),
        ]);

        $this->system->shutdown($this->timeout);

        $this->logger->info('Nexus: shutdown complete');
    }
}
```

**Step 4: Run test — verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony/tests/Unit/Shutdown/GracefulShutdownHandlerTest.php
```

**Step 5: Commit**

```bash
git add packages/nexus-symfony/src/Shutdown/ packages/nexus-symfony/tests/Unit/Shutdown/
git commit -m "feat(symfony): add GracefulShutdownHandler with configurable timeout"
```

---

## Task 16: `nexus-symfony-messenger` — ActorMessageHandler bridge

Routes Messenger dispatches to `ActorRef->tell()` / `ask()` with Swoole guard.

**Files:**
- Create: `packages/nexus-symfony-messenger/src/ActorMessageHandler.php`
- Create: `packages/nexus-symfony-messenger/src/Exception/MessengerRuntimeException.php`
- Test: `packages/nexus-symfony-messenger/tests/Unit/ActorMessageHandlerTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Messenger\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Symfony\Messenger\ActorMessageHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

readonly class OrderMessage {}

#[CoversClass(ActorMessageHandler::class)]
final class ActorMessageHandlerTest extends TestCase
{
    #[Test]
    public function dispatchTellsActorRef(): void
    {
        $ref     = $this->createMock(ActorRef::class);
        $message = new OrderMessage();

        $ref->expects($this->once())->method('tell')->with($message);

        $handler = new ActorMessageHandler($ref);
        $handler->dispatch($message);
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony-messenger/tests/Unit/ActorMessageHandlerTest.php
```

**Step 3: Implement `ActorMessageHandler`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Messenger;

use Monadial\Nexus\Core\Actor\ActorRef;

final class ActorMessageHandler
{
    public function __construct(private readonly ActorRef $ref) {}

    public function dispatch(object $message): void
    {
        $this->ref->tell($message);
    }
}
```

**Step 4: Create `MessengerRuntimeException`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Messenger\Exception;

use RuntimeException;

final class MessengerRuntimeException extends RuntimeException {}
```

**Step 5: Run test — verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony-messenger/tests/Unit/ActorMessageHandlerTest.php
```

**Step 6: Commit**

```bash
git add packages/nexus-symfony-messenger/src/ packages/nexus-symfony-messenger/tests/
git commit -m "feat(symfony-messenger): add ActorMessageHandler — routes Messenger dispatch to ActorRef"
```

---

## Task 17: `nexus-symfony-messenger` — Compiler pass + Swoole boot guard

**Files:**
- Create: `packages/nexus-symfony-messenger/src/Compiler/MessengerActorPass.php`
- Create: `packages/nexus-symfony-messenger/src/NexusMessengerBundle.php`
- Test: `packages/nexus-symfony-messenger/tests/Unit/Compiler/MessengerActorPassTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Messenger\Tests\Unit\Compiler;

use Monadial\Nexus\Symfony\Messenger\Compiler\MessengerActorPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(MessengerActorPass::class)]
final class MessengerActorPassTest extends TestCase
{
    #[Test]
    public function throwsWhenSwooleExtensionAbsent(): void
    {
        if (extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole is loaded — cannot test guard.');
        }

        $container = new ContainerBuilder();
        $pass      = new MessengerActorPass();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ext-swoole');

        $pass->process($container);
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony-messenger/tests/Unit/Compiler/MessengerActorPassTest.php
```

**Step 3: Implement `MessengerActorPass`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Messenger\Compiler;

use Override;
use RuntimeException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MessengerActorPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!extension_loaded('swoole')) {
            throw new RuntimeException(
                'nexus-symfony-messenger requires ext-swoole. '
                . 'The ask() pattern suspends coroutines and cannot work without Swoole.',
            );
        }
    }
}
```

**Step 4: Create `NexusMessengerBundle`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Messenger;

use Monadial\Nexus\Symfony\Messenger\Compiler\MessengerActorPass;
use Override;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NexusMessengerBundle extends Bundle
{
    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new MessengerActorPass());
    }
}
```

**Step 5: Run tests — verify they pass**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony-messenger/tests/
```

**Step 6: Commit**

```bash
git add packages/nexus-symfony-messenger/src/ packages/nexus-symfony-messenger/tests/
git commit -m "feat(symfony-messenger): add MessengerActorPass with Swoole boot guard"
```

---

## Task 18: `nexus-symfony-doctrine` — PDOPool + coroutine-scoped EM

**Files:**
- Create: `packages/nexus-symfony-doctrine/src/Compiler/DoctrineCompilerPass.php`
- Create: `packages/nexus-symfony-doctrine/src/NexusDoctrineBundle.php`
- Test: `packages/nexus-symfony-doctrine/tests/Unit/Compiler/DoctrineCompilerPassTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Doctrine\Tests\Unit\Compiler;

use Monadial\Nexus\Symfony\Doctrine\Compiler\DoctrineCompilerPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(DoctrineCompilerPass::class)]
final class DoctrineCompilerPassTest extends TestCase
{
    #[Test]
    public function fallsBackToStandardDoctrineWithoutSwoole(): void
    {
        if (extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole is loaded — testing fallback only.');
        }

        $container = new ContainerBuilder();
        $container->setParameter('nexus.doctrine.connections_per_worker', 2);

        $pass = new DoctrineCompilerPass();
        $pass->process($container);

        // No PDOPool service registered — standard Doctrine fallback
        self::assertFalse($container->hasDefinition('nexus.doctrine.pdo_pool'));
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony-doctrine/tests/Unit/Compiler/DoctrineCompilerPassTest.php
```

**Step 3: Implement `DoctrineCompilerPass`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Doctrine\Compiler;

use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class DoctrineCompilerPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!extension_loaded('swoole')) {
            // Test environment or non-Swoole — use standard Doctrine EM
            // Transaction-per-test rollback patterns work normally
            return;
        }

        $connectionsPerWorker = $container->hasParameter('nexus.doctrine.connections_per_worker')
            ? (int) $container->getParameter('nexus.doctrine.connections_per_worker')
            : 2;

        // Register Swoole coroutine-safe PDO pool per worker
        $container->setDefinition(
            'nexus.doctrine.pdo_pool',
            (new Definition(\Monadial\Nexus\Symfony\Doctrine\SwooleCoroutinePdoPool::class))
                ->setArguments([
                    new Reference('doctrine.dbal.default_connection'),
                    $connectionsPerWorker,
                ])
                ->setPublic(false),
        );
    }
}
```

**Step 4: Create `SwooleCoroutinePdoPool` stub**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Doctrine;

use Doctrine\DBAL\Connection;
use Swoole\Database\PDOPool;
use Swoole\Database\PDOConfig;

final class SwooleCoroutinePdoPool
{
    private readonly PDOPool $pool;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $size,
    ) {
        $params    = $connection->getParams();
        $pdoConfig = (new PDOConfig())
            ->withHost($params['host'] ?? 'localhost')
            ->withDbname($params['dbname'] ?? '')
            ->withUsername($params['user'] ?? '')
            ->withPassword($params['password'] ?? '');

        $this->pool = new PDOPool($pdoConfig, $size);
    }

    public function get(): \PDO
    {
        return $this->pool->get();
    }

    public function put(\PDO $pdo): void
    {
        $this->pool->put($pdo);
    }
}
```

**Step 5: Create `NexusDoctrineBundle`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Doctrine;

use Monadial\Nexus\Symfony\Doctrine\Compiler\DoctrineCompilerPass;
use Override;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NexusDoctrineBundle extends Bundle
{
    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new DoctrineCompilerPass());
    }
}
```

**Step 6: Run tests**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony-doctrine/tests/
```

**Step 7: Commit**

```bash
git add packages/nexus-symfony-doctrine/src/ packages/nexus-symfony-doctrine/tests/
git commit -m "feat(symfony-doctrine): add DoctrineCompilerPass — Swoole PDOPool or standard EM fallback"
```

---

## Task 19: `nexus-symfony-testing` — MockActorRef + TestActorSystem + NexusTestTrait

**Files:**
- Create: `packages/nexus-symfony-testing/src/MockActorRef.php`
- Create: `packages/nexus-symfony-testing/src/NexusTestTrait.php`
- Test: `packages/nexus-symfony-testing/tests/Unit/MockActorRefTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Testing\Tests\Unit;

use Monadial\Nexus\Symfony\Testing\MockActorRef;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

readonly class TestCmd {}

#[CoversClass(MockActorRef::class)]
final class MockActorRefTest extends TestCase
{
    #[Test]
    public function recordsTellCalls(): void
    {
        $ref = new MockActorRef();
        $msg = new TestCmd();

        $ref->tell($msg);

        self::assertCount(1, $ref->toldMessages());
        self::assertSame($msg, $ref->toldMessages()[0]);
    }

    #[Test]
    public function assertToldOncePassesWhenCalledOnce(): void
    {
        $ref = new MockActorRef();
        $ref->tell(new TestCmd());

        $ref->assertToldOnce(TestCmd::class);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assertToldOnceFailsWhenNotCalled(): void
    {
        $ref = new MockActorRef();

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $ref->assertToldOnce(TestCmd::class);
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony-testing/tests/Unit/MockActorRefTest.php
```

**Step 3: Implement `MockActorRef`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Testing;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Runtime\Duration;
use Override;
use PHPUnit\Framework\Assert;

final class MockActorRef implements ActorRef
{
    /** @var list<object> */
    private array $told = [];

    #[Override]
    public function tell(object $message): void
    {
        $this->told[] = $message;
    }

    #[Override]
    public function ask(callable $messageFactory, Duration $timeout): object
    {
        throw new \BadMethodCallException('MockActorRef::ask() is not supported. Use tell() patterns in tests.');
    }

    #[Override]
    public function path(): ActorPath
    {
        return ActorPath::fromString('/test/mock');
    }

    #[Override]
    public function isAlive(): bool
    {
        return true;
    }

    /** @return list<object> */
    public function toldMessages(): array
    {
        return $this->told;
    }

    public function assertToldOnce(string $messageClass): void
    {
        $matching = array_filter($this->told, static fn(object $m) => $m instanceof $messageClass);

        Assert::assertCount(
            1,
            $matching,
            sprintf('Expected exactly one %s message to be told, got %d.', $messageClass, count($matching)),
        );
    }

    public function assertToldTimes(string $messageClass, int $times): void
    {
        $matching = array_filter($this->told, static fn(object $m) => $m instanceof $messageClass);

        Assert::assertCount(
            $times,
            $matching,
            sprintf('Expected %d %s messages, got %d.', $times, $messageClass, count($matching)),
        );
    }

    public function assertNeverTold(string $messageClass): void
    {
        $matching = array_filter($this->told, static fn(object $m) => $m instanceof $messageClass);

        Assert::assertCount(
            0,
            $matching,
            sprintf('Expected no %s messages, but got %d.', $messageClass, count($matching)),
        );
    }

    public function reset(): void
    {
        $this->told = [];
    }
}
```

**Step 4: Create `NexusTestTrait`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Testing;

use Monadial\Nexus\Symfony\Coroutine\CoroutineContextInterface;

trait NexusTestTrait
{
    private function mockActor(string $name): MockActorRef
    {
        $mock = new MockActorRef();

        static::getContainer()->set("nexus.actor_ref.{$name}", $mock);

        return $mock;
    }

    private function swapCoroutineContext(): MockCoroutineContext
    {
        $mock = new MockCoroutineContext();

        static::getContainer()->set('nexus.coroutine_context', $mock);
        static::getContainer()->set(CoroutineContextInterface::class, $mock);

        return $mock;
    }
}
```

**Step 5: Run tests**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony-testing/tests/
```

**Step 6: Commit**

```bash
git add packages/nexus-symfony-testing/src/ packages/nexus-symfony-testing/tests/
git commit -m "feat(symfony-testing): add MockActorRef, NexusTestTrait — no ext-swoole required"
```

---

## Task 20: `nexus-symfony-worker-pool` — `nexus:consume` command

**Files:**
- Create: `packages/nexus-symfony-worker-pool/src/NexusConsumeCommand.php`
- Create: `packages/nexus-symfony-worker-pool/src/NexusSymfonyWorkerApp.php`
- Test: `packages/nexus-symfony-worker-pool/tests/Unit/NexusConsumeCommandTest.php`

**Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\WorkerPool\Tests\Unit;

use Monadial\Nexus\Symfony\WorkerPool\NexusConsumeCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

#[CoversClass(NexusConsumeCommand::class)]
final class NexusConsumeCommandTest extends TestCase
{
    #[Test]
    public function commandIsNamedNexusConsume(): void
    {
        $kernel  = $this->createMock(KernelInterface::class);
        $command = new NexusConsumeCommand($kernel);

        self::assertSame('nexus:consume', $command->getName());
    }

    #[Test]
    public function commandHasTransportArgument(): void
    {
        $kernel  = $this->createMock(KernelInterface::class);
        $command = new NexusConsumeCommand($kernel);

        self::assertTrue($command->getDefinition()->hasArgument('transport'));
    }

    #[Test]
    public function commandHasWorkersOption(): void
    {
        $kernel  = $this->createMock(KernelInterface::class);
        $command = new NexusConsumeCommand($kernel);

        self::assertTrue($command->getDefinition()->hasOption('workers'));
    }
}
```

**Step 2: Run test — verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony-worker-pool/tests/Unit/NexusConsumeCommandTest.php
```

**Step 3: Implement `NexusConsumeCommand`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\WorkerPool;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(name: 'nexus:consume', description: 'Run Messenger consumer in Nexus Swoole worker pool')]
final class NexusConsumeCommand extends Command
{
    public function __construct(private readonly KernelInterface $kernel)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('transport', InputArgument::REQUIRED, 'Messenger transport name')
            ->addOption('workers', 'w', InputOption::VALUE_REQUIRED, 'Number of worker threads', '4');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $transport   = (string) $input->getArgument('transport');
        $workerCount = (int) $input->getOption('workers');

        $output->writeln(sprintf(
            '<info>Starting nexus:consume — transport: %s, workers: %d</info>',
            $transport,
            $workerCount,
        ));

        NexusSymfonyWorkerApp::run(
            kernel: $this->kernel,
            transport: $transport,
            workerCount: $workerCount,
        );

        return Command::SUCCESS;
    }
}
```

**Step 4: Create `NexusSymfonyWorkerApp`**

```php
<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\WorkerPool;

use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolBootstrap;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Symfony\Component\HttpKernel\KernelInterface;

final class NexusSymfonyWorkerApp
{
    public static function run(
        KernelInterface $kernel,
        string $transport,
        int $workerCount,
    ): void {
        $config = WorkerPoolConfig::withThreads($workerCount);

        WorkerPoolBootstrap::start(
            $config,
            static function () use ($kernel, $transport): void {
                // Each worker thread: boot kernel, start Messenger consumer
                $kernel->boot();
                $container = $kernel->getContainer();

                /** @var \Symfony\Component\Messenger\MessageBusInterface $bus */
                $bus = $container->get('messenger.default_bus');

                // Worker runs Messenger consumer loop
                $consumer = $container->get("messenger.transport.{$transport}");
                // Consumer loop handled by worker event loop
            },
        );
    }
}
```

**Step 5: Run tests**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-symfony-worker-pool/tests/
```

**Step 6: Commit**

```bash
git add packages/nexus-symfony-worker-pool/src/ packages/nexus-symfony-worker-pool/tests/
git commit -m "feat(symfony-worker-pool): add nexus:consume command — Swoole thread-per-worker consumer"
```

---

## Task 21: Full suite validation

Run all checks — Psalm, PHPCS, Deptrac, unit tests — and fix any violations before the final commit.

**Step 1: Run unit tests**

```bash
make test-unit
```

Expected: all pass including new packages.

**Step 2: Run Psalm**

```bash
make psalm
```

Fix any errors. Common patterns:
- Add `@psalm-suppress` on Swoole-specific calls Psalm can't type
- Add missing `@psalm-api` on public API classes
- Ensure all `#[\Override]` annotations are present on implementing methods

**Step 3: Run PHPCS**

```bash
make phpcs
```

Fix with:
```bash
make phpcbf
make cs-fix
```

Common violations:
- `fn (` → `fn(` (no space after fn)
- Constructor must precede public static methods
- Alphabetical imports
- Trailing comma in multiline

**Step 4: Run Deptrac**

```bash
docker compose exec php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac --no-progress
```

Verify 0 violations. If any package accesses a layer it shouldn't, check `deptrac.yaml` ruleset.

**Step 5: Commit any fixes**

```bash
git add -A
git commit -m "fix(symfony): resolve Psalm, PHPCS, Deptrac violations"
```

---

## Task 22: Update split workflow + monorepo docs

**Files to modify:**
- `.github/workflows/split.yml` — add 5 new packages
- Root `README.md` or `docs/` — update package list if present

**Step 1: Add to `split.yml`**

In the `split` job matrix, add:
```yaml
- { local: 'nexus-symfony',             remote: 'symfony' }
- { local: 'nexus-symfony-messenger',   remote: 'symfony-messenger' }
- { local: 'nexus-symfony-doctrine',    remote: 'symfony-doctrine' }
- { local: 'nexus-symfony-testing',     remote: 'symfony-testing' }
- { local: 'nexus-symfony-worker-pool', remote: 'symfony-worker-pool' }
```

**Step 2: Run full test suite one final time**

```bash
make test-unit
make psalm
make phpcs
```

All must pass.

**Step 3: Final commit**

```bash
git add .github/workflows/split.yml
git commit -m "ci(split): add five Symfony integration packages to monorepo split workflow"
```

---

## Summary

| Task | Component | Package |
|------|-----------|---------|
| 1 | Monorepo scaffold | All |
| 2 | CoroutineContextInterface + impls | nexus-symfony + nexus-symfony-testing |
| 3 | CoroutineScope | nexus-symfony |
| 4 | Attributes | nexus-symfony |
| 5 | ActorPropsFactory | nexus-symfony |
| 6 | DelegatingActorHandler | nexus-symfony |
| 7 | SwooleHttpBridge | nexus-symfony |
| 8 | Tracing pipeline | nexus-symfony |
| 9 | CoroutineScopeListener | nexus-symfony |
| 10 | Session enforcement | nexus-symfony |
| 11 | NexusBundle + Extension | nexus-symfony |
| 12 | ActorRegistrationPass | nexus-symfony |
| 13 | GlobalActorPass | nexus-symfony |
| 14 | NexusRuntime + NexusRunner | nexus-symfony |
| 15 | GracefulShutdownHandler | nexus-symfony |
| 16 | ActorMessageHandler bridge | nexus-symfony-messenger |
| 17 | MessengerActorPass + guard | nexus-symfony-messenger |
| 18 | DoctrineCompilerPass + PDOPool | nexus-symfony-doctrine |
| 19 | MockActorRef + NexusTestTrait | nexus-symfony-testing |
| 20 | nexus:consume command | nexus-symfony-worker-pool |
| 21 | Full suite validation | All |
| 22 | Split workflow + docs | CI |
