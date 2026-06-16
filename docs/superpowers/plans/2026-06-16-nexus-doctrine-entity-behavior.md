# Nexus EntityBehavior DSL Implementation Plan (Plan 3 of 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the headline `EntityBehavior<T,C>` DSL on top of `nexus-doctrine-orm` (Plan 2). Treats a Doctrine entity as the state of a non-event-sourced aggregate actor. Mirrors the existing `DurableStateBehavior` shape so users who know that DSL pick this up immediately.

**Architecture:** `EntityBehavior::create($entityClass, $id, $commandHandler)` returns an `EntityBehaviorBuilder`. `->toBehavior()` produces a `Monadial\Nexus\Core\Actor\Behavior` that, via `Behavior::setup(...)`, runs lifecycle wiring on actor `PreStart`: open a **dedicated** EM via `EntityManagerFactory` (not from the pool — actor lifetime ≠ request lifetime, see design §5.4), resolve the entity via `EntityReplayPolicy`, then transition into a `Behavior::withState(...)` that dispatches messages through the user's command handler. The command handler returns an `EntityEffect` describing what to do: `same()` / `persist()` / `remove()` / `stop()` / `reply()` / `stash()`, optionally composed with `->thenRun(fn(T))` or `->thenReply($to, fn(T) => msg)`. The runner translates `EntityEffect` into ORM operations (`$em->flush()` for persist, `$em->remove()` + `$em->flush()` for remove). `EntityRefFactory::of($id)` derives a deterministic actor name (`Order::42`) → spawns once → returns a typed `ActorRef`. Single-writer guarantee falls out of `ActorNameExistsException`. `EntityConflictException` wraps Doctrine's `OptimisticLockException` and triggers restart-with-reload via the supervision strategy. Two new Psalm rules slot into `nexus-psalm` alongside Plan 1's `PooledConnectionInActorPropertyRule`.

**Tech Stack:** PHP 8.5 strict, Doctrine ORM ^3.0, Psalm strict-level 1, PHPCS PER-CS2.0 + Slevomat, PHPUnit 13, GrumPHP. Branch `feat/nexus-doctrine`.

**Depends on:** Plan 1 (`-dbal`) and Plan 2 (`-orm` core) merged. Adds new code to `packages/nexus-doctrine-orm/` and `packages/nexus-psalm/`. No new package created.

---

## Spec → Plan map

| Spec section | Tasks |
|---|---|
| `EntityEffect` shape + composers | T1–T3 |
| `EntityReplayPolicy` (`Fail` / `CreateIfMissing` / `OnDemand`) | T4 |
| `EntityBehaviorBuilder` + `EntityBehavior::create()` | T5–T6 |
| Runner: actor lifecycle, command dispatch, flush | T7–T8 |
| `EntityConflictException` + restart-with-reload | T9 |
| `EntityRefFactory` (single-writer naming) | T10 |
| Psalm rules | T11–T12 |
| Integration tests | T13–T16 |
| Final gates | T17 |

---

## File structure

**New files in `packages/nexus-doctrine-orm/`:**

```
packages/nexus-doctrine-orm/src/
├── Behavior/
│   ├── EntityBehavior.php                          static factory
│   ├── EntityBehaviorBuilder.php                   ->withReplayPolicy etc.
│   ├── EntityBehaviorRunner.php                    internal lifecycle wiring
│   ├── EntityEffect.php
│   ├── Composer/
│   │   ├── ThenRunComposer.php
│   │   └── ThenReplyComposer.php
│   ├── ReplayPolicy/
│   │   ├── EntityReplayPolicy.php                  interface
│   │   ├── FailIfMissing.php
│   │   ├── CreateIfMissing.php
│   │   └── OnDemand.php
│   └── EntityRefFactory.php
└── Exception/
    └── EntityConflictException.php
```

**New files in `packages/nexus-psalm/src/Hook/`:**
- `EntityBehaviorReturnTypeProvider.php`
- `MissingTransactionalDeclarationRule.php`

**Modified:**
- `packages/nexus-psalm/src/Plugin.php` — register both new hooks.

**New integration tests:**
- `tests/Integration/Doctrine/EntityBehavior/HappyPathTest.php`
- `tests/Integration/Doctrine/EntityBehavior/ReplayPoliciesTest.php`
- `tests/Integration/Doctrine/EntityBehavior/OptimisticLockTest.php`
- `tests/Integration/Doctrine/EntityBehavior/EntityRefFactoryTest.php`

---

## Conventions

Same as Plans 1 and 2. Commit prefix: `feat(doctrine-orm): …` for code in `nexus-doctrine-orm`, `feat(psalm): …` for the Psalm hooks.

---

## Task 1: `EntityEffect` — terminal effects (same / persist / remove / stop)

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Behavior/EntityEffect.php` (initial cut: terminal effects only)
- Create: `packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityEffectTest.php`

We model `EntityEffect` as a tagged sealed type: a `kind` enum + payload fields. Composers (`thenRun`, `thenReply`) and `reply()` come in T2/T3.

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffectKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityEffect::class)]
final class EntityEffectTest extends TestCase
{
    #[Test]
    public function sameHasNoOpKind(): void
    {
        self::assertSame(EntityEffectKind::Same, EntityEffect::same()->kind);
    }

    #[Test]
    public function persistHasFlushKind(): void
    {
        self::assertSame(EntityEffectKind::Persist, EntityEffect::persist()->kind);
    }

    #[Test]
    public function removeHasRemoveKind(): void
    {
        self::assertSame(EntityEffectKind::Remove, EntityEffect::remove()->kind);
    }

    #[Test]
    public function stopHasStopKind(): void
    {
        self::assertSame(EntityEffectKind::Stop, EntityEffect::stop()->kind);
    }
}
```

- [ ] **Step 2: Implement initial cut**

`packages/nexus-doctrine-orm/src/Behavior/EntityEffectKind.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

enum EntityEffectKind
{
    case Same;
    case Persist;
    case Remove;
    case Stop;
    case Stash;
}
```

`packages/nexus-doctrine-orm/src/Behavior/EntityEffect.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Closure;
use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * @template T of object
 *
 * @psalm-api
 */
final readonly class EntityEffect
{
    /**
     * @param list<Closure(T): void>            $runHooks
     * @param list<array{ref: ActorRef, build: Closure(T): object}> $replyHooks
     */
    private function __construct(
        public EntityEffectKind $kind,
        public ?ActorRef $immediateReplyRef = null,
        public ?object $immediateReplyMessage = null,
        public array $runHooks = [],
        public array $replyHooks = [],
    ) {}

    public static function same(): self
    {
        return new self(EntityEffectKind::Same);
    }

    public static function persist(): self
    {
        return new self(EntityEffectKind::Persist);
    }

    public static function remove(): self
    {
        return new self(EntityEffectKind::Remove);
    }

    public static function stop(): self
    {
        return new self(EntityEffectKind::Stop);
    }

    public static function stash(): self
    {
        return new self(EntityEffectKind::Stash);
    }
}
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityEffectTest.php
git add packages/nexus-doctrine-orm/src/Behavior/EntityEffect.php packages/nexus-doctrine-orm/src/Behavior/EntityEffectKind.php packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityEffectTest.php
git commit -m "feat(doctrine-orm): add EntityEffect terminal cases"
```

---

## Task 2: `EntityEffect::reply()` + composers (`thenRun`, `thenReply`)

**Files:**
- Modify: `packages/nexus-doctrine-orm/src/Behavior/EntityEffect.php`
- Modify: `packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityEffectTest.php`

- [ ] **Step 1: Append failing tests**

```php
    #[Test]
    public function replyCarriesImmediateRefAndMessage(): void
    {
        $ref = $this->createMock(\Monadial\Nexus\Core\Actor\ActorRef::class);
        $msg = new \stdClass();

        $effect = EntityEffect::reply($ref, $msg);
        self::assertSame($ref, $effect->immediateReplyRef);
        self::assertSame($msg, $effect->immediateReplyMessage);
    }

    #[Test]
    public function thenRunAppendsHook(): void
    {
        $effect = EntityEffect::persist()->thenRun(static fn(object $e) => null);
        self::assertCount(1, $effect->runHooks);
    }

    #[Test]
    public function thenReplyAppendsHook(): void
    {
        $ref = $this->createMock(\Monadial\Nexus\Core\Actor\ActorRef::class);
        $effect = EntityEffect::persist()->thenReply($ref, static fn(object $e): object => new \stdClass());
        self::assertCount(1, $effect->replyHooks);
        self::assertSame($ref, $effect->replyHooks[0]['ref']);
    }

    #[Test]
    public function composersChain(): void
    {
        $ref = $this->createMock(\Monadial\Nexus\Core\Actor\ActorRef::class);
        $effect = EntityEffect::persist()
            ->thenRun(static fn(object $e) => null)
            ->thenReply($ref, static fn(object $e): object => new \stdClass());

        self::assertCount(1, $effect->runHooks);
        self::assertCount(1, $effect->replyHooks);
    }
```

- [ ] **Step 2: Add the methods**

Add to `EntityEffect`:
```php
    public static function reply(ActorRef $to, object $message): self
    {
        return new self(EntityEffectKind::Same, immediateReplyRef: $to, immediateReplyMessage: $message);
    }

    /**
     * @param Closure(T): void $hook
     */
    public function thenRun(Closure $hook): self
    {
        return new self(
            kind: $this->kind,
            immediateReplyRef: $this->immediateReplyRef,
            immediateReplyMessage: $this->immediateReplyMessage,
            runHooks: [...$this->runHooks, $hook],
            replyHooks: $this->replyHooks,
        );
    }

    /**
     * @param Closure(T): object $build
     */
    public function thenReply(ActorRef $to, Closure $build): self
    {
        return new self(
            kind: $this->kind,
            immediateReplyRef: $this->immediateReplyRef,
            immediateReplyMessage: $this->immediateReplyMessage,
            runHooks: $this->runHooks,
            replyHooks: [...$this->replyHooks, ['ref' => $to, 'build' => $build]],
        );
    }
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityEffectTest.php
git add packages/nexus-doctrine-orm/src/Behavior/EntityEffect.php packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityEffectTest.php
git commit -m "feat(doctrine-orm): EntityEffect reply + thenRun + thenReply composers"
```

---

## Task 3: Verify composer composition with terminal effects

(This is a verification task — no production code change, just additional coverage to lock in the contract.)

- [ ] **Step 1: Append cross-cutting tests**

In `packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityEffectTest.php`:
```php
    #[Test]
    public function removeWithThenRunCarriesBothKindAndHook(): void
    {
        $effect = EntityEffect::remove()->thenRun(static fn(object $e) => null);

        self::assertSame(EntityEffectKind::Remove, $effect->kind);
        self::assertCount(1, $effect->runHooks);
    }

    #[Test]
    public function stopDiscardsHooks(): void
    {
        $effect = EntityEffect::stop()->thenRun(static fn(object $e) => null);
        // Hooks are kept on the effect; the runner is responsible for NOT firing
        // them when kind === Stop, since stop means "no flush" → entity may be inconsistent.
        // We verify the data shape here; the runner contract test (Task 8) verifies the skip.
        self::assertSame(EntityEffectKind::Stop, $effect->kind);
        self::assertCount(1, $effect->runHooks);
    }
```

- [ ] **Step 2: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityEffectTest.php
git add packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityEffectTest.php
git commit -m "test(doctrine-orm): EntityEffect composition with terminal effects"
```

---

## Task 4: `EntityReplayPolicy` + three implementations

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Behavior/ReplayPolicy/EntityReplayPolicy.php`
- Create: `packages/nexus-doctrine-orm/src/Behavior/ReplayPolicy/FailIfMissing.php`
- Create: `packages/nexus-doctrine-orm/src/Behavior/ReplayPolicy/CreateIfMissing.php`
- Create: `packages/nexus-doctrine-orm/src/Behavior/ReplayPolicy/OnDemand.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Behavior/ReplayPolicy/PoliciesTest.php`

The policy interface resolves an entity given `(EntityManagerInterface, class-string, id)`. `OnDemand` returns `null` (the runner uses this to skip the initial load).

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior\ReplayPolicy;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\FailIfMissing;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\OnDemand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FailIfMissing::class)]
#[CoversClass(CreateIfMissing::class)]
#[CoversClass(OnDemand::class)]
final class PoliciesTest extends TestCase
{
    #[Test]
    public function failIfMissingThrowsWhenAbsent(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $this->expectException(EntityNotFoundException::class);
        (new FailIfMissing())->resolve($em, \stdClass::class, 'k');
    }

    #[Test]
    public function failIfMissingReturnsEntityWhenPresent(): void
    {
        $obj = new \stdClass();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($obj);

        self::assertSame($obj, (new FailIfMissing())->resolve($em, \stdClass::class, 'k'));
    }

    #[Test]
    public function createIfMissingUsesFactory(): void
    {
        $factoryCalls = 0;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $em->expects(self::once())->method('persist');

        $policy = new CreateIfMissing(static function (mixed $id) use (&$factoryCalls): object {
            $factoryCalls++;
            $o = new \stdClass();
            $o->id = $id;

            return $o;
        });

        $resolved = $policy->resolve($em, \stdClass::class, 'k');
        self::assertSame(1, $factoryCalls);
        self::assertSame('k', $resolved->id);
    }

    #[Test]
    public function onDemandReturnsNull(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        self::assertNull((new OnDemand())->resolve($em, \stdClass::class, 'k'));
    }
}
```

- [ ] **Step 2: Implement interface + three impls**

`packages/nexus-doctrine-orm/src/Behavior/ReplayPolicy/EntityReplayPolicy.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy;

use Doctrine\ORM\EntityManagerInterface;

/**
 * @template T of object
 * @psalm-api
 */
interface EntityReplayPolicy
{
    /**
     * @param class-string<T> $entityClass
     * @return T|null
     */
    public function resolve(EntityManagerInterface $em, string $entityClass, mixed $id): ?object;
}
```

`packages/nexus-doctrine-orm/src/Behavior/ReplayPolicy/FailIfMissing.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Override;

/**
 * @template T of object
 * @template-implements EntityReplayPolicy<T>
 * @psalm-api
 */
final readonly class FailIfMissing implements EntityReplayPolicy
{
    #[Override]
    public function resolve(EntityManagerInterface $em, string $entityClass, mixed $id): object
    {
        $entity = $em->find($entityClass, $id);

        if ($entity === null) {
            throw EntityNotFoundException::fromClassNameAndIdentifier($entityClass, ['id' => (string) $id]);
        }

        return $entity;
    }
}
```

`packages/nexus-doctrine-orm/src/Behavior/ReplayPolicy/CreateIfMissing.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Override;

/**
 * @template T of object
 * @template-implements EntityReplayPolicy<T>
 * @psalm-api
 */
final readonly class CreateIfMissing implements EntityReplayPolicy
{
    /**
     * @param Closure(mixed): T $factory
     */
    public function __construct(private Closure $factory) {}

    #[Override]
    public function resolve(EntityManagerInterface $em, string $entityClass, mixed $id): object
    {
        $existing = $em->find($entityClass, $id);

        if ($existing !== null) {
            return $existing;
        }

        $fresh = ($this->factory)($id);
        $em->persist($fresh);

        return $fresh;
    }
}
```

`packages/nexus-doctrine-orm/src/Behavior/ReplayPolicy/OnDemand.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy;

use Doctrine\ORM\EntityManagerInterface;
use Override;

/**
 * @template T of object
 * @template-implements EntityReplayPolicy<T>
 * @psalm-api
 */
final readonly class OnDemand implements EntityReplayPolicy
{
    #[Override]
    public function resolve(EntityManagerInterface $em, string $entityClass, mixed $id): ?object
    {
        return null;
    }
}
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Behavior/ReplayPolicy/
git add packages/nexus-doctrine-orm/src/Behavior/ReplayPolicy/ packages/nexus-doctrine-orm/tests/Unit/Behavior/ReplayPolicy/
git commit -m "feat(doctrine-orm): add EntityReplayPolicy + three implementations"
```

---

## Task 5: `EntityBehaviorBuilder`

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorBuilder.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityBehaviorBuilderTest.php`

Holds the configuration knobs: EM factory (required), replay policy (default `FailIfMissing`), lock mode, flush mode. `toBehavior()` is implemented in T6 once we have the runner.

- [ ] **Step 1: Write failing test (config-only assertions)**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use Doctrine\ORM\Configuration;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehaviorBuilder;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(EntityBehaviorBuilder::class)]
final class EntityBehaviorBuilderTest extends TestCase
{
    #[Test]
    public function requiresEntityManagerFactoryBeforeBuild(): void
    {
        $builder = new EntityBehaviorBuilder(
            entityClass: stdClass::class,
            id: 'k',
            commandHandler: static fn($ctx, object $msg, object $entity): EntityEffect => EntityEffect::same(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('EntityManagerFactory required');
        $builder->toBehavior();
    }

    #[Test]
    public function fluentSettersReturnNewInstance(): void
    {
        $base = new EntityBehaviorBuilder(
            entityClass: stdClass::class,
            id: 'k',
            commandHandler: static fn($ctx, object $msg, object $entity): EntityEffect => EntityEffect::same(),
        );
        $config = new Configuration();
        $config->setProxyDir('/tmp');
        $config->setProxyNamespace('Proxies');
        $emFactory = new DefaultEntityManagerFactory($config);
        $policy = new CreateIfMissing(static fn($id) => new stdClass());

        $configured = $base->withEntityManagerFactory($emFactory)->withReplayPolicy($policy);

        self::assertNotSame($base, $configured);
        self::assertSame($emFactory, $configured->emFactory);
        self::assertSame($policy, $configured->replayPolicy);
    }
}
```

- [ ] **Step 2: Implement**

`packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorBuilder.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Closure;
use Doctrine\DBAL\LockMode;
use LogicException;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\EntityReplayPolicy;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\FailIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;

/**
 * @template T of object
 * @template C of object
 *
 * @psalm-api
 */
final readonly class EntityBehaviorBuilder
{
    public ?EntityManagerFactory $emFactory;
    public EntityReplayPolicy $replayPolicy;
    public ?LockMode $lockMode;

    /**
     * @param class-string<T> $entityClass
     * @param Closure(\Monadial\Nexus\Core\Actor\ActorContext<C>, C, T): EntityEffect<T> $commandHandler
     */
    public function __construct(
        public string $entityClass,
        public mixed $id,
        public Closure $commandHandler,
        ?EntityManagerFactory $emFactory = null,
        ?EntityReplayPolicy $replayPolicy = null,
        ?LockMode $lockMode = null,
    ) {
        $this->emFactory = $emFactory;
        $this->replayPolicy = $replayPolicy ?? new FailIfMissing();
        $this->lockMode = $lockMode;
    }

    public function withEntityManagerFactory(EntityManagerFactory $factory): self
    {
        return new self(
            entityClass: $this->entityClass,
            id: $this->id,
            commandHandler: $this->commandHandler,
            emFactory: $factory,
            replayPolicy: $this->replayPolicy,
            lockMode: $this->lockMode,
        );
    }

    public function withReplayPolicy(EntityReplayPolicy $policy): self
    {
        return new self(
            entityClass: $this->entityClass,
            id: $this->id,
            commandHandler: $this->commandHandler,
            emFactory: $this->emFactory,
            replayPolicy: $policy,
            lockMode: $this->lockMode,
        );
    }

    public function withLockMode(LockMode $mode): self
    {
        return new self(
            entityClass: $this->entityClass,
            id: $this->id,
            commandHandler: $this->commandHandler,
            emFactory: $this->emFactory,
            replayPolicy: $this->replayPolicy,
            lockMode: $mode,
        );
    }

    public function toBehavior(): Behavior
    {
        if ($this->emFactory === null) {
            throw new LogicException('EntityManagerFactory required — call withEntityManagerFactory() before toBehavior()');
        }

        return EntityBehaviorRunner::build($this);
    }
}
```

Note: `EntityBehaviorRunner::build()` is implemented in T7. The test in this task asserts only the config-error case + fluent setters — not the runner output yet — so this compiles.

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityBehaviorBuilderTest.php
git add packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorBuilder.php packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityBehaviorBuilderTest.php
git commit -m "feat(doctrine-orm): add EntityBehaviorBuilder fluent config"
```

(`toBehavior()` will fail at the `EntityBehaviorRunner::build()` reference until T7 — that's expected. Don't commit a broken `toBehavior()` — instead, comment out the line or stub return for now. Adjust: temporarily make `toBehavior()` throw `BadMethodCallException` with `'wired in Task 7'` so this commit's tests pass.)

Update `toBehavior()` to:
```php
public function toBehavior(): Behavior
{
    if ($this->emFactory === null) {
        throw new LogicException('EntityManagerFactory required — call withEntityManagerFactory() before toBehavior()');
    }

    throw new \BadMethodCallException('EntityBehaviorRunner not wired yet — see Plan 3 Task 7');
}
```

---

## Task 6: `EntityBehavior::create()` static factory

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Behavior/EntityBehavior.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityBehaviorTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehaviorBuilder;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(EntityBehavior::class)]
final class EntityBehaviorTest extends TestCase
{
    #[Test]
    public function createReturnsBuilder(): void
    {
        $b = EntityBehavior::create(
            entityClass: stdClass::class,
            id: 'k',
            commandHandler: static fn($ctx, object $msg, object $entity): EntityEffect => EntityEffect::same(),
        );

        self::assertInstanceOf(EntityBehaviorBuilder::class, $b);
    }
}
```

- [ ] **Step 2: Implement**

`packages/nexus-doctrine-orm/src/Behavior/EntityBehavior.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Closure;

/** @psalm-api */
final class EntityBehavior
{
    /**
     * @template T of object
     * @template C of object
     *
     * @param class-string<T> $entityClass
     * @param Closure(\Monadial\Nexus\Core\Actor\ActorContext<C>, C, T): EntityEffect<T> $commandHandler
     * @return EntityBehaviorBuilder<T, C>
     */
    public static function create(string $entityClass, mixed $id, Closure $commandHandler): EntityBehaviorBuilder
    {
        return new EntityBehaviorBuilder($entityClass, $id, $commandHandler);
    }
}
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityBehaviorTest.php
git add packages/nexus-doctrine-orm/src/Behavior/EntityBehavior.php packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityBehaviorTest.php
git commit -m "feat(doctrine-orm): add EntityBehavior::create static factory"
```

---

## Task 7: `EntityBehaviorRunner` — actor lifecycle wiring

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorRunner.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityBehaviorRunnerTest.php`
- Modify: `packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorBuilder.php` — `toBehavior()` now calls `EntityBehaviorRunner::build($this)`.

The runner wraps the user's command handler in a `Behavior::setup(...)` that performs:
1. **PreStart** — borrow a dedicated `Connection` from a (caller-supplied) source. But wait: the design says **dedicated EM** built via `EntityManagerFactory`. The EM factory needs a `Connection`. Where does it come from?

**Design clarification (consistent with spec §5.4):** The `EntityManagerFactory` used in `EntityBehavior` is a dedicated factory that constructs a fresh `Connection` per actor — **not** drawn from any pool. The simplest API: the EM factory's `create()` requires a `Connection`, so the runner needs a `Closure(): Connection` source. We add `withConnectionSource(Closure $source)` to the builder (default: error if not set, like `withEntityManagerFactory`). The convenience case is `withDirectConnection(array $connParams)` that constructs `DriverManager::getConnection($params)` per actor.

Update T5's builder to add these methods (we add them now so the runner test compiles):

- [ ] **Step 1: Extend `EntityBehaviorBuilder` with connection-source plumbing**

Add to `EntityBehaviorBuilder`:
```php
public ?Closure $connectionSource;       // Closure(): Connection
```

Add constructor parameter (last) and copy semantics in fluent setters.

Add:
```php
public function withConnectionSource(Closure $source): self
{
    return new self(
        entityClass: $this->entityClass,
        id: $this->id,
        commandHandler: $this->commandHandler,
        emFactory: $this->emFactory,
        replayPolicy: $this->replayPolicy,
        lockMode: $this->lockMode,
        connectionSource: $source,
    );
}

/**
 * @param array<string, mixed> $params
 */
public function withDirectConnection(array $params): self
{
    return $this->withConnectionSource(static fn() => \Doctrine\DBAL\DriverManager::getConnection($params));
}
```

- [ ] **Step 2: Write failing test for the runner**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\FailIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(\Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehaviorRunner::class)]
final class EntityBehaviorRunnerTest extends TestCase
{
    #[Test]
    public function toBehaviorReturnsActorBehavior(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(new stdClass());
        $em->method('isOpen')->willReturn(true);

        $factory = $this->createMock(EntityManagerFactory::class);
        $factory->method('create')->willReturn($em);

        $behavior = EntityBehavior::create(
            entityClass: stdClass::class,
            id: 'k',
            commandHandler: static fn(ActorContext $ctx, object $msg, object $entity): EntityEffect => EntityEffect::same(),
        )
            ->withEntityManagerFactory($factory)
            ->withReplayPolicy(new FailIfMissing())
            ->withDirectConnection(['url' => 'sqlite3:///:memory:'])
            ->toBehavior();

        self::assertInstanceOf(Behavior::class, $behavior);
    }
}
```

- [ ] **Step 3: Implement the runner**

`packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorRunner.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Doctrine\ORM\OptimisticLockException;
use LogicException;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Doctrine\Orm\Exception\EntityConflictException;

/**
 * @psalm-internal Monadial\Nexus\Doctrine\Orm
 */
final class EntityBehaviorRunner
{
    public static function build(EntityBehaviorBuilder $builder): Behavior
    {
        if ($builder->connectionSource === null) {
            throw new LogicException('Connection source required — call withConnectionSource() or withDirectConnection()');
        }

        if ($builder->emFactory === null) {
            throw new LogicException('EntityManagerFactory required');
        }

        return Behavior::setup(static function (ActorContext $ctx) use ($builder): Behavior {
            $connection = ($builder->connectionSource)();
            $em = $builder->emFactory->create($connection);
            $entity = $builder->replayPolicy->resolve($em, $builder->entityClass, $builder->id);

            $stateful = Behavior::withState(
                $entity,
                static function (ActorContext $innerCtx, object $msg, ?object $state) use ($builder, $em, $ctx): BehaviorWithState {
                    if ($state === null) {
                        // OnDemand: resolve now.
                        $state = $builder->replayPolicy->resolve($em, $builder->entityClass, $builder->id)
                            ?? throw new \RuntimeException('OnDemand replay returned null on first command');
                    }

                    /** @var EntityEffect $effect */
                    $effect = ($builder->commandHandler)($innerCtx, $msg, $state);

                    if ($effect->immediateReplyRef !== null && $effect->immediateReplyMessage !== null) {
                        $effect->immediateReplyRef->tell($effect->immediateReplyMessage);
                    }

                    try {
                        match ($effect->kind) {
                            EntityEffectKind::Same    => null,
                            EntityEffectKind::Persist => $em->flush(),
                            EntityEffectKind::Remove  => (function () use ($em, $state): void {
                                $em->remove($state);
                                $em->flush();
                            })(),
                            EntityEffectKind::Stop    => null,
                            EntityEffectKind::Stash   => $innerCtx->stash(),
                        };
                    } catch (OptimisticLockException $e) {
                        throw new EntityConflictException($builder->entityClass, $builder->id, $e);
                    }

                    if ($effect->kind !== EntityEffectKind::Stop) {
                        foreach ($effect->runHooks as $hook) {
                            $hook($state);
                        }

                        foreach ($effect->replyHooks as $reply) {
                            $reply['ref']->tell(($reply['build'])($state));
                        }
                    }

                    return match ($effect->kind) {
                        EntityEffectKind::Stop, EntityEffectKind::Remove => BehaviorWithState::stopped(),
                        EntityEffectKind::Stash                          => BehaviorWithState::same(),
                        default                                          => BehaviorWithState::next($state),
                    };
                },
            );

            return $stateful->onSignal(static function (ActorContext $innerCtx, object $signal) use ($em, $connection): Behavior {
                if ($signal instanceof PostStop) {
                    $em->close();
                    $connection->close();
                }

                return Behavior::same();
            });
        });
    }
}
```

- [ ] **Step 4: Update `EntityBehaviorBuilder::toBehavior()` to actually call the runner**

Replace the `BadMethodCallException` line with `return EntityBehaviorRunner::build($this);`.

- [ ] **Step 5: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Behavior/
git add packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorRunner.php packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorBuilder.php packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityBehaviorRunnerTest.php
git commit -m "feat(doctrine-orm): wire EntityBehaviorRunner — actor lifecycle + effect dispatch"
```

---

## Task 8: `EntityConflictException` + restart-with-reload supervision integration

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Exception/EntityConflictException.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Exception/EntityConflictExceptionTest.php`

The conflict exception is already thrown by the runner in T7. This task adds it as a first-class type and tests the wrapping behavior.

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Exception;

use Doctrine\ORM\OptimisticLockException;
use Monadial\Nexus\Core\Exception\NexusException;
use Monadial\Nexus\Doctrine\Orm\Exception\EntityConflictException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(EntityConflictException::class)]
final class EntityConflictExceptionTest extends TestCase
{
    #[Test]
    public function wrapsDoctrineOptimisticLock(): void
    {
        $inner = OptimisticLockException::lockFailed(new stdClass());
        $e = new EntityConflictException(stdClass::class, 'k', $inner);

        self::assertInstanceOf(NexusException::class, $e);
        self::assertSame($inner, $e->getPrevious());
        self::assertStringContainsString('stdClass', $e->getMessage());
        self::assertStringContainsString('k', $e->getMessage());
    }
}
```

- [ ] **Step 2: Implement**

`packages/nexus-doctrine-orm/src/Exception/EntityConflictException.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Exception;

use Doctrine\ORM\OptimisticLockException;
use Monadial\Nexus\Core\Exception\NexusException;

/** @psalm-api */
final class EntityConflictException extends NexusException
{
    public function __construct(
        public readonly string $entityClass,
        public readonly mixed $id,
        OptimisticLockException $previous,
    ) {
        parent::__construct(
            sprintf('Optimistic lock conflict for %s::%s', $entityClass, (string) $id),
            0,
            $previous,
        );
    }
}
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Exception/EntityConflictExceptionTest.php
git add packages/nexus-doctrine-orm/src/Exception/EntityConflictException.php packages/nexus-doctrine-orm/tests/Unit/Exception/EntityConflictExceptionTest.php
git commit -m "feat(doctrine-orm): add EntityConflictException for optimistic-lock failures"
```

---

## Task 9: `EntityRefFactory` — single-writer naming

**Files:**
- Create: `packages/nexus-doctrine-orm/src/Behavior/EntityRefFactory.php`
- Create: `packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityRefFactoryTest.php`

`of($id)` derives a name like `Order::42` and spawns once. Subsequent calls return the cached `ActorRef`. Cluster-wide single-writer is the responsibility of the existing `ConsistentHashRing`.

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use Closure;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(EntityRefFactory::class)]
final class EntityRefFactoryTest extends TestCase
{
    #[Test]
    public function derivesDeterministicActorName(): void
    {
        self::assertSame('stdClass::42', EntityRefFactory::deriveName(stdClass::class, 42));
        self::assertSame('App.Order::abc', EntityRefFactory::deriveName('App\\Order', 'abc'));
    }

    #[Test]
    public function ofCachesByEntityId(): void
    {
        $system = $this->createMock(ActorSystem::class);
        $callCount = 0;
        $system->method('spawn')->willReturnCallback(function () use (&$callCount): ActorRef {
            $callCount++;
            return $this->createMock(ActorRef::class);
        });

        $emFactory = $this->createMock(EntityManagerFactory::class);
        $factory = EntityRefFactory::for($system, stdClass::class)
            ->using($emFactory)
            ->withConnectionSource(static fn() => throw new \RuntimeException('not used in this test'))
            ->handle(static fn($ctx, object $msg, object $entity): EntityEffect => EntityEffect::same())
            ->build();

        $refA = $factory->of('k1');
        $refB = $factory->of('k1');
        $refC = $factory->of('k2');

        self::assertSame($refA, $refB);
        self::assertNotSame($refA, $refC);
        self::assertSame(2, $callCount);
    }
}
```

- [ ] **Step 2: Implement**

`packages/nexus-doctrine-orm/src/Behavior/EntityRefFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Closure;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\EntityReplayPolicy;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;

/**
 * @template T of object
 * @template C of object
 *
 * @psalm-api
 */
final class EntityRefFactory
{
    /** @var array<string, ActorRef> */
    private array $cache = [];

    /**
     * @param class-string<T>                       $entityClass
     * @param Closure(\Monadial\Nexus\Core\Actor\ActorContext<C>, C, T): EntityEffect<T> $commandHandler
     */
    private function __construct(
        private readonly ActorSystem $system,
        private readonly string $entityClass,
        private readonly EntityManagerFactory $emFactory,
        private readonly Closure $connectionSource,
        private readonly Closure $commandHandler,
        private readonly EntityReplayPolicy $replayPolicy,
    ) {}

    public static function for(ActorSystem $system, string $entityClass): EntityRefFactoryBuilder
    {
        return new EntityRefFactoryBuilder($system, $entityClass);
    }

    public function of(mixed $id): ActorRef
    {
        $name = self::deriveName($this->entityClass, $id);

        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $behavior = EntityBehavior::create($this->entityClass, $id, $this->commandHandler)
            ->withEntityManagerFactory($this->emFactory)
            ->withConnectionSource($this->connectionSource)
            ->withReplayPolicy($this->replayPolicy)
            ->toBehavior();

        return $this->cache[$name] = $this->system->spawn(Props::fromBehavior($behavior), $name);
    }

    public static function deriveName(string $entityClass, mixed $id): string
    {
        return str_replace('\\', '.', $entityClass) . '::' . (string) $id;
    }

    /**
     * @internal Constructed by the builder.
     */
    public static function instantiate(
        ActorSystem $system,
        string $entityClass,
        EntityManagerFactory $emFactory,
        Closure $connectionSource,
        Closure $commandHandler,
        EntityReplayPolicy $replayPolicy,
    ): self {
        return new self($system, $entityClass, $emFactory, $connectionSource, $commandHandler, $replayPolicy);
    }
}
```

`packages/nexus-doctrine-orm/src/Behavior/EntityRefFactoryBuilder.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Closure;
use LogicException;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\EntityReplayPolicy;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\FailIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;

/** @psalm-api */
final class EntityRefFactoryBuilder
{
    private ?EntityManagerFactory $emFactory = null;
    private ?Closure $connectionSource = null;
    private ?Closure $commandHandler = null;
    private EntityReplayPolicy $replayPolicy;

    public function __construct(
        private readonly ActorSystem $system,
        private readonly string $entityClass,
    ) {
        $this->replayPolicy = new FailIfMissing();
    }

    public function using(EntityManagerFactory $factory): self
    {
        $this->emFactory = $factory;

        return $this;
    }

    public function withConnectionSource(Closure $source): self
    {
        $this->connectionSource = $source;

        return $this;
    }

    public function withReplayPolicy(EntityReplayPolicy $policy): self
    {
        $this->replayPolicy = $policy;

        return $this;
    }

    public function handle(Closure $commandHandler): self
    {
        $this->commandHandler = $commandHandler;

        return $this;
    }

    public function build(): EntityRefFactory
    {
        if ($this->emFactory === null || $this->connectionSource === null || $this->commandHandler === null) {
            throw new LogicException('EntityRefFactoryBuilder: using()/withConnectionSource()/handle() all required');
        }

        return EntityRefFactory::instantiate(
            $this->system,
            $this->entityClass,
            $this->emFactory,
            $this->connectionSource,
            $this->commandHandler,
            $this->replayPolicy,
        );
    }
}
```

- [ ] **Step 3: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityRefFactoryTest.php
git add packages/nexus-doctrine-orm/src/Behavior/EntityRefFactory.php packages/nexus-doctrine-orm/src/Behavior/EntityRefFactoryBuilder.php packages/nexus-doctrine-orm/tests/Unit/Behavior/EntityRefFactoryTest.php
git commit -m "feat(doctrine-orm): add EntityRefFactory with single-writer caching"
```

---

## Task 10: Psalm — `EntityBehaviorReturnTypeProvider`

**Files:**
- Create: `packages/nexus-psalm/src/Hook/EntityBehaviorReturnTypeProvider.php`
- Modify: `packages/nexus-psalm/src/Plugin.php` — register.
- Create: `packages/nexus-psalm/tests/Unit/Hook/EntityBehaviorReturnTypeProviderTest.php`

Mirrors the existing `Behavior::receive` / `withState` / `setup` type-inference hooks (Plan 1 doesn't add them — they're pre-existing per CLAUDE.md). The new provider infers `EntityBehaviorBuilder<T, C>` from `EntityBehavior::create($entityClass, $id, fn(…, C, T): EntityEffect)` so the closure params type-check.

- [ ] **Step 1: Read an existing return-type-provider hook for shape**

```bash
docker compose exec -T php-fiber ls packages/nexus-psalm/src/Hook/ | grep ReturnType
docker compose exec -T php-fiber cat packages/nexus-psalm/src/Hook/BehaviorReceiveReturnTypeProvider.php
```

(Substitute the actual filename — read it to learn the exact `MethodReturnTypeProviderInterface` shape used here.)

- [ ] **Step 2: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Unit\Hook;

use Monadial\Nexus\Psalm\Tests\Support\PsalmTestCase;
use PHPUnit\Framework\Attributes\Test;

final class EntityBehaviorReturnTypeProviderTest extends PsalmTestCase
{
    #[Test]
    public function infersTAndCFromCreate(): void
    {
        $code = <<<'PHP'
        <?php
        namespace App;
        use Monadial\Nexus\Core\Actor\ActorContext;
        use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
        use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
        final class Order {}
        final class AddLineItem {}
        $b = EntityBehavior::create(
            entityClass: Order::class,
            id: 'k',
            commandHandler: static fn(ActorContext $ctx, AddLineItem $cmd, Order $o): EntityEffect => EntityEffect::same(),
        );
        /** @psalm-trace $b */
        PHP;

        $this->assertTraceMentions($code, 'EntityBehaviorBuilder<App\Order, App\AddLineItem>');
    }
}
```

- [ ] **Step 3: Implement the provider — mirror the existing `Behavior::receive` provider**

(Specific Psalm API differs by version — the runner pattern is `MethodReturnTypeProviderInterface` returning a `Union` typed `EntityBehaviorBuilder<T, C>` where `T` is the resolved class string of arg 0 and `C` is inferred from the closure parameter at position 1.)

`packages/nexus-psalm/src/Hook/EntityBehaviorReturnTypeProvider.php` — pattern reference: read `BehaviorReceiveReturnTypeProvider.php` and adapt: instead of returning `ReceiveBehavior<T>`, return `EntityBehaviorBuilder<T, C>` where `T` is the literal class-string from `$entityClass` and `C` is the second closure-param type.

- [ ] **Step 4: Register in `Plugin.php`**

Add to `__invoke()`:
```php
$registration->registerHooksFromClass(EntityBehaviorReturnTypeProvider::class);
```

- [ ] **Step 5: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-psalm/tests/Unit/Hook/EntityBehaviorReturnTypeProviderTest.php
git add packages/nexus-psalm/src/Hook/EntityBehaviorReturnTypeProvider.php packages/nexus-psalm/src/Plugin.php packages/nexus-psalm/tests/Unit/Hook/EntityBehaviorReturnTypeProviderTest.php
git commit -m "feat(psalm): add EntityBehaviorReturnTypeProvider for T,C inference"
```

---

## Task 11: Psalm — `MissingTransactionalDeclarationRule`

**Files:**
- Create: `packages/nexus-psalm/src/Hook/MissingTransactionalDeclarationRule.php`
- Modify: `packages/nexus-psalm/src/Plugin.php` — register.
- Create: `packages/nexus-psalm/tests/Unit/Hook/MissingTransactionalDeclarationRuleTest.php`

Flags any handler class with `#[Transactional]` that doesn't declare a `Connection` or `EntityManagerInterface` parameter — `#[Transactional]` would silently no-op otherwise.

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Unit\Hook;

use Monadial\Nexus\Psalm\Tests\Support\PsalmTestCase;
use PHPUnit\Framework\Attributes\Test;

final class MissingTransactionalDeclarationRuleTest extends PsalmTestCase
{
    #[Test]
    public function flagsHandlerWithAttributeButNoDependency(): void
    {
        $code = <<<'PHP'
        <?php
        namespace App;
        use Monadial\Nexus\Doctrine\Dbal\Http\Attribute\Transactional;
        use Psr\Http\Message\ResponseInterface;
        use Psr\Http\Message\ServerRequestInterface;
        #[Transactional]
        final class CreateOrder {
            public function __invoke(ServerRequestInterface $req): ResponseInterface { /* … */ }
        }
        PHP;

        $this->assertHasIssue($code, 'NexusMissingTransactionalDeclaration');
    }

    #[Test]
    public function doesNotFlagWhenConnectionDeclared(): void
    {
        $code = <<<'PHP'
        <?php
        namespace App;
        use Doctrine\DBAL\Connection;
        use Monadial\Nexus\Doctrine\Dbal\Http\Attribute\Transactional;
        use Psr\Http\Message\ResponseInterface;
        use Psr\Http\Message\ServerRequestInterface;
        #[Transactional]
        final class CreateOrder {
            public function __invoke(ServerRequestInterface $req, Connection $c): ResponseInterface { /* … */ }
        }
        PHP;

        $this->assertNoIssue($code, 'NexusMissingTransactionalDeclaration');
    }

    #[Test]
    public function doesNotFlagWhenEntityManagerDeclared(): void
    {
        $code = <<<'PHP'
        <?php
        namespace App;
        use Doctrine\ORM\EntityManagerInterface;
        use Monadial\Nexus\Doctrine\Dbal\Http\Attribute\Transactional;
        use Psr\Http\Message\ResponseInterface;
        use Psr\Http\Message\ServerRequestInterface;
        #[Transactional]
        final class CreateOrder {
            public function __invoke(ServerRequestInterface $req, EntityManagerInterface $em): ResponseInterface { /* … */ }
        }
        PHP;

        $this->assertNoIssue($code, 'NexusMissingTransactionalDeclaration');
    }
}
```

- [ ] **Step 2: Implement**

`packages/nexus-psalm/src/Hook/MissingTransactionalDeclarationRule.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Psalm\Issue\PluginIssue;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;

final class MissingTransactionalDeclarationRule implements AfterClassLikeAnalysisInterface
{
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event): ?bool
    {
        $storage = $event->getClassLikeStorage();
        $hasTransactional = false;

        foreach ($storage->attributes as $attr) {
            if ($attr->fq_class_name === 'Monadial\\Nexus\\Doctrine\\Dbal\\Http\\Attribute\\Transactional') {
                $hasTransactional = true;
                break;
            }
        }

        if (!$hasTransactional) {
            return null;
        }

        // Look at the __invoke (or handle) method's parameters.
        $methods = $storage->methods;

        foreach ($methods as $method) {
            foreach ($method->params as $param) {
                $type = (string) ($param->type ?? '');

                if (
                    str_contains($type, 'Doctrine\DBAL\Connection')
                    || str_contains($type, 'Doctrine\ORM\EntityManagerInterface')
                ) {
                    return null;
                }
            }
        }

        IssueBuffer::accepts(new class ("Handler annotated #[Transactional] but does not declare a Connection or EntityManagerInterface parameter — the attribute will be a no-op.", $storage->location) extends PluginIssue {
            public static string $issue_type = 'NexusMissingTransactionalDeclaration';
        });

        return null;
    }
}
```

- [ ] **Step 3: Register in `Plugin.php`**

```php
$registration->registerHooksFromClass(MissingTransactionalDeclarationRule::class);
```

- [ ] **Step 4: Verify + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-psalm/tests/Unit/Hook/MissingTransactionalDeclarationRuleTest.php
git add packages/nexus-psalm/src/Hook/MissingTransactionalDeclarationRule.php packages/nexus-psalm/src/Plugin.php packages/nexus-psalm/tests/Unit/Hook/MissingTransactionalDeclarationRuleTest.php
git commit -m "feat(psalm): add MissingTransactionalDeclarationRule"
```

---

## Task 12: Integration test — `EntityBehavior` happy path (Fiber)

**Files:**
- Create: `tests/Integration/Doctrine/EntityBehavior/Fixture/Counter.php`
- Create: `tests/Integration/Doctrine/EntityBehavior/HappyPathTest.php`

End-to-end: spawn an entity actor, send commands, verify entity state in DB.

- [ ] **Step 1: Fixture**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Doctrine\EntityBehavior\Fixture;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'counters')]
class Counter
{
    #[Id]
    #[Column]
    public string $id;

    #[Column]
    public int $value = 0;

    public function __construct(string $id, int $value = 0)
    {
        $this->id = $id;
        $this->value = $value;
    }

    public function add(int $delta): void
    {
        $this->value += $delta;
    }
}
```

- [ ] **Step 2: Test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Doctrine\EntityBehavior;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Doctrine\EntityBehavior\Fixture\Counter;

final readonly class Add { public function __construct(public int $delta) {} }

final class HappyPathTest extends TestCase
{
    #[Test]
    public function counterAccumulates(): void
    {
        // shared file-based SQLite so the assertion EM can read what the actor wrote
        $dbPath = tempnam(sys_get_temp_dir(), 'nexus-eb-');
        $url = 'sqlite3:///' . $dbPath;
        $bootstrapConn = DriverManager::getConnection(['url' => $url]);
        $bootstrapConfig = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Fixture'], true);
        $bootstrapEm = (new DefaultEntityManagerFactory($bootstrapConfig))->create($bootstrapConn);
        (new SchemaTool($bootstrapEm))->createSchema([$bootstrapEm->getClassMetadata(Counter::class)]);

        $runtime = new FiberRuntime();
        $system = ActorSystem::create('test', $runtime);
        $emFactory = new DefaultEntityManagerFactory($bootstrapConfig);

        $behavior = EntityBehavior::create(
            entityClass: Counter::class,
            id: 'c-1',
            commandHandler: static fn(ActorContext $ctx, object $msg, Counter $c): EntityEffect =>
                $msg instanceof Add
                    ? ($c->add($msg->delta) ?? EntityEffect::persist())
                    : EntityEffect::same(),
        )
            ->withEntityManagerFactory($emFactory)
            ->withReplayPolicy(new CreateIfMissing(static fn(string $id): Counter => new Counter($id)))
            ->withDirectConnection(['url' => $url])
            ->toBehavior();

        $ref = $system->spawn(\Monadial\Nexus\Core\Actor\Props::fromBehavior($behavior), 'counter-1');
        $ref->tell(new Add(3));
        $ref->tell(new Add(7));

        $runtime->scheduleOnce(Duration::millis(500), fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        // Assertion EM (fresh, read-after-write)
        $verifyConn = DriverManager::getConnection(['url' => $url]);
        $verifyEm = (new DefaultEntityManagerFactory($bootstrapConfig))->create($verifyConn);
        $stored = $verifyEm->find(Counter::class, 'c-1');

        self::assertNotNull($stored);
        self::assertSame(10, $stored->value);

        @unlink($dbPath);
    }
}
```

Note: the closure `$c->add($msg->delta) ?? EntityEffect::persist()` is a comma-operator workaround — `add` returns void, so `null ?? X` evaluates `X`. Cleaner alternative: make `add()` return the entity (`return $this`) and chain. Either works.

- [ ] **Step 3: Run + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit tests/Integration/Doctrine/EntityBehavior/HappyPathTest.php
git add tests/Integration/Doctrine/EntityBehavior/
git commit -m "test(doctrine-orm): EntityBehavior happy-path integration"
```

---

## Task 13: Integration test — replay policies

**Files:**
- Create: `tests/Integration/Doctrine/EntityBehavior/ReplayPoliciesTest.php`

- [ ] **Step 1: Write the test**

Cover three cases:
- `FailIfMissing` → entity not pre-inserted → `ActorInitializationException` on PreStart.
- `CreateIfMissing` → entity not pre-inserted → factory invoked → entity persisted.
- `OnDemand` → entity not pre-loaded on start → first message triggers load.

Pattern same as Task 12 (shared SQLite file, schema-tool bootstrap, FiberRuntime, scheduleOnce shutdown).

- [ ] **Step 2: Run + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit tests/Integration/Doctrine/EntityBehavior/ReplayPoliciesTest.php
git add tests/Integration/Doctrine/EntityBehavior/ReplayPoliciesTest.php
git commit -m "test(doctrine-orm): replay policies (Fail / CreateIfMissing / OnDemand)"
```

---

## Task 14: Integration test — optimistic lock conflict + reload

**Files:**
- Create: `tests/Integration/Doctrine/EntityBehavior/Fixture/VersionedItem.php`
- Create: `tests/Integration/Doctrine/EntityBehavior/OptimisticLockTest.php`

The versioned fixture has `#[Version] #[Column] public int $version = 1`. Simulate conflict by directly updating the row via a side `Connection`, then send a command — expect `EntityConflictException`. With supervision configured to restart, the second attempt should succeed.

- [ ] **Step 1: Fixture with @Version column**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Doctrine\EntityBehavior\Fixture;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\Version;

#[Entity]
#[Table(name: 'versioned_items')]
class VersionedItem
{
    #[Id]
    #[Column]
    public string $id;

    #[Column]
    public int $count = 0;

    #[Version]
    #[Column]
    public int $version = 1;

    public function __construct(string $id)
    {
        $this->id = $id;
    }
}
```

- [ ] **Step 2: Test (skeleton)**

Set up an actor for `id = "v-1"`. Pre-load it via PreStart. From a side connection, run `UPDATE versioned_items SET version = version + 1 WHERE id = 'v-1'`. Then send a mutating command. Catch `EntityConflictException` via supervision logs or via expecting actor restart. Assert the second attempt persisted.

(Concrete test code follows the same shape as Task 12; the assertion is "after recovery the new value is reflected and version > 1".)

- [ ] **Step 3: Run + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit tests/Integration/Doctrine/EntityBehavior/OptimisticLockTest.php
git add tests/Integration/Doctrine/EntityBehavior/Fixture/VersionedItem.php tests/Integration/Doctrine/EntityBehavior/OptimisticLockTest.php
git commit -m "test(doctrine-orm): optimistic lock conflict surfaces as EntityConflictException"
```

---

## Task 15: Integration test — `EntityRefFactory::of()` returns same ref

**Files:**
- Create: `tests/Integration/Doctrine/EntityBehavior/EntityRefFactoryTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Doctrine\EntityBehavior;

use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\DBAL\DriverManager;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Doctrine\EntityBehavior\Fixture\Counter;

final class EntityRefFactoryTest extends TestCase
{
    #[Test]
    public function ofReturnsSameRefForSameId(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'nexus-erf-');
        $url = 'sqlite3:///' . $dbPath;
        $bootstrap = DriverManager::getConnection(['url' => $url]);
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Fixture'], true);
        $em = (new DefaultEntityManagerFactory($config))->create($bootstrap);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Counter::class)]);

        $runtime = new FiberRuntime();
        $system = ActorSystem::create('test', $runtime);
        $emFactory = new DefaultEntityManagerFactory($config);

        $factory = EntityRefFactory::for($system, Counter::class)
            ->using($emFactory)
            ->withConnectionSource(static fn() => DriverManager::getConnection(['url' => $url]))
            ->withReplayPolicy(new CreateIfMissing(static fn(string $id): Counter => new Counter($id)))
            ->handle(static fn(ActorContext $ctx, object $msg, Counter $c): EntityEffect => EntityEffect::same())
            ->build();

        $a = $factory->of('shared');
        $b = $factory->of('shared');
        $c = $factory->of('other');

        self::assertSame($a, $b);
        self::assertNotSame($a, $c);

        $runtime->scheduleOnce(Duration::millis(100), fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();
        @unlink($dbPath);
    }
}
```

- [ ] **Step 2: Run + commit**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit tests/Integration/Doctrine/EntityBehavior/EntityRefFactoryTest.php
git add tests/Integration/Doctrine/EntityBehavior/EntityRefFactoryTest.php
git commit -m "test(doctrine-orm): EntityRefFactory single-writer per (class, id)"
```

---

## Task 16: Update Makefile

**Files:**
- Modify: `Makefile`

- [ ] **Step 1: Extend `test-doctrine` target**

```makefile
test-doctrine:
	docker compose exec -T php-fiber vendor/bin/phpunit tests/Integration/Doctrine/Fiber/ tests/Integration/Doctrine/EntityBehavior/
	docker compose exec -T php-swoole vendor/bin/phpunit tests/Integration/Doctrine/Swoole/ tests/Integration/Doctrine/WorkerPool/
```

- [ ] **Step 2: Run + commit**

```bash
make test-doctrine
git add Makefile
git commit -m "chore(doctrine-orm): include EntityBehavior in test-doctrine target"
```

---

## Task 17: Final repo-wide gate

- [ ] **Step 1: Run unit suite**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages
```

- [ ] **Step 2: Run linters**

```bash
docker compose exec -T php-fiber vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose exec -T php-fiber vendor/bin/phpcs
docker compose exec -T php vendor/bin/psalm --no-cache
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyze
```

- [ ] **Step 3: Run integration**

```bash
make test-fiber
make test-swoole
make test-cluster
make test-doctrine
```

- [ ] **Step 4: Branch state**

```bash
git status
git log --oneline feat/nexus-http..HEAD
```

Expected: ~17 new commits on top of Plan 2's tip. Working tree clean (except the two pre-existing unstaged files).

- [ ] **Step 5: Push (with user approval)**

Ask before pushing. When approved:

```bash
git push origin feat/nexus-doctrine
```

---

## Self-review checklist

- [ ] `EntityEffect` covers same/persist/remove/stop/stash/reply + thenRun/thenReply (spec §5.2).
- [ ] `EntityReplayPolicy` covers Fail / CreateIfMissing / OnDemand (spec §5.3).
- [ ] `EntityBehavior::create()` + builder + runner deliver the closure-based DSL (spec §5.1, §5.4).
- [ ] `EntityRefFactory` enforces single-writer per `(entityClass, id)` via deterministic naming (spec §5.5).
- [ ] `EntityConflictException` wraps `OptimisticLockException` (spec §5.6).
- [ ] Two Psalm rules slot in alongside the existing seven (spec "Psalm plugin additions").
- [ ] No `TBD` / `TODO` placeholders: `grep -E 'TBD|TODO|FIXME' docs/superpowers/plans/2026-06-16-nexus-doctrine-entity-behavior.md`.
- [ ] Type / method names consistent: `EntityBehavior::create()`, `EntityBehaviorBuilder::with*()` / `toBehavior()`, `EntityRefFactory::for()` / `::of()` / `::deriveName()`, `EntityEffect::same()` / `::persist()` / `::remove()` / `::stop()` / `::stash()` / `::reply()` / `->thenRun()` / `->thenReply()`.
- [ ] Commit prefix `feat(doctrine-orm): …` or `feat(psalm): …` everywhere.
- [ ] Dedicated non-pooled EM per actor (not `EntityManagerPool`) — verified in T7 runner and T12 integration test.

---

**Plan 3 is the final plan in this series.** After T17 passes, `feat/nexus-doctrine` is ready for PR. Suggested PR title: `feat(doctrine): coroutine-aware Doctrine DBAL + ORM + EntityBehavior DSL for Nexus`.
