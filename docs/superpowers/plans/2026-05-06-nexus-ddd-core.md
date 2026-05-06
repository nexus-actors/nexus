# nexus-ddd-core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the foundational `nexus-ddd-core` package containing the tactical DDD building blocks (Identity, Value, Entity, AggregateRoot, Specification, Policy, BackoffStrategy) per the umbrella spec at `docs/superpowers/specs/2026-05-06-nexus-ddd-umbrella-design.md` §6.

**Architecture:** Pure-PHP library with zero dependency on `nexus-core` or any actor-system code. Five tactical primitives + identity + backoff strategies. Every public type covered by Psalm-strict types and PHPUnit tests. Strict-functional flavor: `Option`/`Either` from `fp4php/functional` instead of nullable types.

**Tech Stack:** PHP 8.5+, PHPUnit 13, Psalm Level 1, `fp4php/functional` (Option/Either/NonEmptyList), `symfony/uid` (Ulid/Uuid), `psr/clock`.

**Conventions to honor:**
- Tests run via Docker: `docker compose exec php vendor/bin/phpunit ...`
- Strict types in every file: `declare(strict_types=1);`
- Final classes by default, readonly value objects
- Exception base extends `RuntimeException` (matches `nexus-core` pattern)
- Test attributes: `#[CoversClass]`, `#[Test]`
- Tests in `packages/nexus-ddd-core/tests/Unit/...` mirroring `src/...` structure
- Frequent commits — one per task minimum, ideally one per logical step
- `Co-Authored-By: Claude` is FORBIDDEN in commit messages (per project CLAUDE.md)

**Out of scope (handled in later phases / packages):**
- `MessageContext`, `Envelope`, `MessageMetadata` → `nexus-ddd-messaging` (P0 next)
- `CommandBus`, `QueryBus`, `EventBus` → `nexus-ddd-bus` (P0 next)
- `AggregateRepository`, `PersistenceStrategy` → `nexus-ddd-aggregate` (P1)
- Process Managers → `nexus-ddd-process-manager` (P2)
- Psalm plugin rules → `nexus-ddd-psalm` (separate package, future)

---

## File Structure

```
packages/nexus-ddd-core/
├── composer.json
├── src/
│   ├── Exception/
│   │   ├── NexusDddException.php           (abstract base)
│   │   ├── ApplyMethodNotFoundException.php
│   │   ├── ApplyMethodAmbiguousException.php
│   │   ├── ReplayFailedException.php
│   │   ├── OptimisticLockException.php
│   │   ├── InvalidIdentifierException.php
│   │   └── NoEventsRecordedException.php
│   ├── Identity/
│   │   ├── Identifier.php                  (interface)
│   │   ├── CompositeIdentifier.php         (interface)
│   │   ├── AbstractCompositeIdentifier.php (abstract base)
│   │   ├── Identifiable.php                (interface)
│   │   ├── IdGenerator.php                 (interface)
│   │   ├── UlidGenerator.php
│   │   └── UuidGenerator.php
│   ├── Value/
│   │   ├── WrappedValue.php                (abstract base)
│   │   ├── ObjectValue.php                 (abstract base)
│   │   ├── StringValue.php                 (concrete WrappedValue)
│   │   ├── IntValue.php
│   │   ├── FloatValue.php
│   │   ├── BoolValue.php
│   │   ├── ArrayValue.php
│   │   ├── UlidValue.php                   (implements Identifier)
│   │   └── UuidValue.php                   (implements Identifier)
│   ├── Entity/
│   │   ├── Entity.php                      (interface)
│   │   └── EventSourceable.php             (interface)
│   ├── Aggregate/
│   │   ├── Attribute/
│   │   │   └── SnapshotConstructor.php
│   │   ├── AggregateRoot.php               (abstract base)
│   │   ├── EventSourcedAggregateRoot.php
│   │   ├── StatefulAggregateRoot.php
│   │   └── Internal/
│   │       └── ApplyDispatcher.php          (reflection-cached resolver)
│   ├── Specification/
│   │   ├── Specification.php               (interface)
│   │   ├── AbstractSpecification.php       (combinators)
│   │   ├── AndSpecification.php
│   │   ├── OrSpecification.php
│   │   ├── NotSpecification.php
│   │   ├── RichSpecification.php           (interface)
│   │   ├── AbstractRichSpecification.php
│   │   └── Failure.php
│   ├── Policy/
│   │   └── AbstractPolicy.php
│   └── Backoff/
│       ├── BackoffStrategy.php             (interface)
│       ├── NoRetry.php
│       ├── FixedDelayBackoff.php
│       ├── LinearBackoff.php
│       ├── ExponentialBackoff.php
│       ├── JitteredExponentialBackoff.php
│       ├── CustomBackoff.php
│       ├── RetryPolicy.php
│       └── RetryPolicyBuilder.php
└── tests/Unit/
    └── (mirrors src/ structure with *Test.php files)
```

---

## Task 1: Package Skeleton + Composer Wiring

**Files:**
- Create: `packages/nexus-ddd-core/composer.json`
- Modify: `composer.json` (root) — add autoload mapping and `fp4php/functional` dep
- Modify: `phpunit.xml` — add `packages/nexus-ddd-core/tests/Unit` to `unit` testsuite
- Create: `packages/nexus-ddd-core/.gitkeep` (so the directory exists in git)

- [ ] **Step 1: Create the package directory structure**

```bash
mkdir -p packages/nexus-ddd-core/src
mkdir -p packages/nexus-ddd-core/tests/Unit
```

- [ ] **Step 2: Write the package composer.json**

Create `packages/nexus-ddd-core/composer.json`:

```json
{
    "name": "nexus-actors/ddd-core",
    "description": "Nexus DDD Framework — tactical building blocks (Identity, Value, Entity, AggregateRoot, Specification, Policy, BackoffStrategy).",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "fp4php/functional": "^6.0",
        "symfony/uid": "^8.0",
        "psr/clock": "^1.0",
        "monadial/php-duration": "^1.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Ddd\\Core\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Ddd\\Core\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 3: Add the package to the root composer.json autoload**

Open root `composer.json`. Add to the `require` block:

```json
"fp4php/functional": "^6.0",
```

Add to the `autoload.psr-4` block:

```json
"Monadial\\Nexus\\Ddd\\Core\\": "packages/nexus-ddd-core/src/",
```

Add to the `autoload-dev.psr-4` block:

```json
"Monadial\\Nexus\\Ddd\\Core\\Tests\\": "packages/nexus-ddd-core/tests/",
```

- [ ] **Step 4: Add the test suite to phpunit.xml**

Open `phpunit.xml`. In the `<testsuite name="unit">` block, add:

```xml
<directory>packages/nexus-ddd-core/tests/Unit</directory>
```

- [ ] **Step 5: Composer install + autoload regeneration**

Run: `make install`
Expected: `composer install` runs successfully; `vendor/composer/autoload_psr4.php` includes the new `Monadial\Nexus\Ddd\Core\` namespace.

Verify: `docker compose exec php php -r 'require "vendor/autoload.php"; var_export(class_exists("Monadial\\Nexus\\Ddd\\Core\\Foo"));'`
Expected output: `false` (class doesn't exist yet, but autoloader recognizes the namespace).

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-ddd-core/composer.json composer.json composer.lock phpunit.xml
git commit -m "feat(ddd-core): add package skeleton, composer wiring, phpunit suite"
```

---

## Task 2: Exception Hierarchy

**Files:**
- Create: `packages/nexus-ddd-core/src/Exception/NexusDddException.php`
- Create: `packages/nexus-ddd-core/src/Exception/ApplyMethodNotFoundException.php`
- Create: `packages/nexus-ddd-core/src/Exception/ApplyMethodAmbiguousException.php`
- Create: `packages/nexus-ddd-core/src/Exception/ReplayFailedException.php`
- Create: `packages/nexus-ddd-core/src/Exception/OptimisticLockException.php`
- Create: `packages/nexus-ddd-core/src/Exception/InvalidIdentifierException.php`
- Create: `packages/nexus-ddd-core/src/Exception/NoEventsRecordedException.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Exception/ExceptionHierarchyTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/nexus-ddd-core/tests/Unit/Exception/ExceptionHierarchyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodAmbiguousException;
use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodNotFoundException;
use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;
use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;
use Monadial\Nexus\Ddd\Core\Exception\NoEventsRecordedException;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Core\Exception\ReplayFailedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(NexusDddException::class)]
#[CoversClass(ApplyMethodNotFoundException::class)]
#[CoversClass(ApplyMethodAmbiguousException::class)]
#[CoversClass(ReplayFailedException::class)]
#[CoversClass(OptimisticLockException::class)]
#[CoversClass(InvalidIdentifierException::class)]
#[CoversClass(NoEventsRecordedException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    #[Test]
    public function nexusDddExceptionIsAbstractRuntimeException(): void
    {
        $reflection = new \ReflectionClass(NexusDddException::class);
        self::assertTrue($reflection->isAbstract());
        self::assertTrue($reflection->isSubclassOf(RuntimeException::class));
    }

    #[Test]
    public function allConcreteExceptionsExtendNexusDddException(): void
    {
        $concretes = [
            ApplyMethodNotFoundException::class,
            ApplyMethodAmbiguousException::class,
            ReplayFailedException::class,
            OptimisticLockException::class,
            InvalidIdentifierException::class,
            NoEventsRecordedException::class,
        ];
        foreach ($concretes as $cls) {
            self::assertTrue(
                is_subclass_of($cls, NexusDddException::class),
                "$cls must extend NexusDddException",
            );
        }
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Exception/ExceptionHierarchyTest.php -v`
Expected: FAIL — `Class "Monadial\Nexus\Ddd\Core\Exception\NexusDddException" not found`.

- [ ] **Step 3: Create the exception base**

Create `packages/nexus-ddd-core/src/Exception/NexusDddException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

use RuntimeException;

/**
 * @psalm-api
 *
 * Base for all Nexus DDD exceptions. Mirrors the `Monadial\Nexus\Core\Exception\NexusException`
 * convention from nexus-core but lives in a separate, decoupled namespace.
 */
abstract class NexusDddException extends RuntimeException {}
```

- [ ] **Step 4: Create the six concrete exceptions**

Create `packages/nexus-ddd-core/src/Exception/ApplyMethodNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

/** @psalm-api */
final class ApplyMethodNotFoundException extends NexusDddException
{
    public static function for(string $aggregateClass, string $eventClass): self
    {
        return new self(
            sprintf(
                'No applyXxx() method found on %s for event %s. Expected method: apply%s.',
                $aggregateClass,
                $eventClass,
                self::shortName($eventClass),
            ),
        );
    }

    private static function shortName(string $fqn): string
    {
        $parts = explode('\\', $fqn);
        return end($parts);
    }
}
```

Create `packages/nexus-ddd-core/src/Exception/ApplyMethodAmbiguousException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

/** @psalm-api */
final class ApplyMethodAmbiguousException extends NexusDddException
{
    /** @param array<string> $eventClasses */
    public static function for(string $aggregateClass, string $shortName, array $eventClasses): self
    {
        return new self(
            sprintf(
                'Ambiguous applyXxx convention on %s: short name "%s" maps to multiple event classes [%s]. Rename one of the events.',
                $aggregateClass,
                $shortName,
                implode(', ', $eventClasses),
            ),
        );
    }
}
```

Create `packages/nexus-ddd-core/src/Exception/ReplayFailedException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

use Throwable;

/** @psalm-api */
final class ReplayFailedException extends NexusDddException
{
    public function __construct(
        public readonly int $eventsApplied,
        public readonly object $failingEvent,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf(
                'Replay failed after %d events while applying %s: %s',
                $eventsApplied,
                $failingEvent::class,
                $previous->getMessage(),
            ),
            previous: $previous,
        );
    }
}
```

Create `packages/nexus-ddd-core/src/Exception/OptimisticLockException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

/** @psalm-api */
final class OptimisticLockException extends NexusDddException
{
    public function __construct(
        public readonly string $entityClass,
        public readonly string $entityId,
        public readonly int $expectedVersion,
        public readonly int $actualVersion,
    ) {
        parent::__construct(
            sprintf(
                'Optimistic lock conflict on %s(%s): expected version %d, found %d.',
                $entityClass,
                $entityId,
                $expectedVersion,
                $actualVersion,
            ),
        );
    }
}
```

Create `packages/nexus-ddd-core/src/Exception/InvalidIdentifierException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

/** @psalm-api */
final class InvalidIdentifierException extends NexusDddException
{
    public static function malformed(string $identifierClass, string $value, string $reason): self
    {
        return new self(
            sprintf('Invalid %s "%s": %s', $identifierClass, $value, $reason),
        );
    }
}
```

Create `packages/nexus-ddd-core/src/Exception/NoEventsRecordedException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

/** @psalm-api */
final class NoEventsRecordedException extends NexusDddException
{
    public static function for(string $aggregateClass): self
    {
        return new self(
            sprintf('No events recorded by %s — pullRecordedEvents() returned empty.', $aggregateClass),
        );
    }
}
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Exception/ExceptionHierarchyTest.php -v`
Expected: PASS — both tests green.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-ddd-core/src/Exception packages/nexus-ddd-core/tests/Unit/Exception
git commit -m "feat(ddd-core): add exception hierarchy (NexusDddException + 6 concrete)"
```

---

## Task 3: Use `monadial/php-duration` — SKIP NEW IMPLEMENTATION

**Decision:** the canonical Duration type for `nexus-ddd-core` is `Monadial\Duration\FiniteDuration` from the [`monadial/php-duration`](https://github.com/monadial/php-duration) package (added in Task 1's composer.json). The package provides the abstract `Duration`, concrete `FiniteDuration`, `Deadline` (for retry-budget integration later), and `TimeUnit` value objects (Days/Hours/Minutes/Seconds/Milliseconds/Microseconds/Nanoseconds).

**API summary used by this plan:**
- Construct: `FiniteDuration::fromTimeUnit(int $length, TimeUnit $unit)` — typically `TimeUnit::Milliseconds()`, `TimeUnit::Seconds()`, etc.
- Convert: `toMillis(): int`, `toSeconds(): int`, `toNanos(): int`, etc.
- Arithmetic: `add(self): self`, `subtract(self): self`, `multiply(int): self`, `division(int): self`

**Implication:** `nexus-runtime` has its own `Monadial\Nexus\Runtime\Duration` — that's used by the actor system and stays unchanged. `nexus-ddd-core` uses the standalone `monadial/php-duration` package. Future work (out of scope for this plan): align `nexus-runtime` to also use `monadial/php-duration` to eliminate the two-Duration situation.

**Files:** none created. The plan's File Structure listing has been updated — the `Time/` directory is removed.

- [ ] **Step 1: Verify FiniteDuration is reachable from nexus-ddd-core's autoload**

After Task 1's `composer install` runs, run a sanity check:

```bash
docker compose exec php php -r 'require "vendor/autoload.php"; var_dump(class_exists(\Monadial\Duration\FiniteDuration::class)); var_dump(class_exists(\Monadial\Duration\TimeUnit\TimeUnit::class));'
```

Expected output:
```
bool(true)
bool(true)
```

- [ ] **Step 2: Note the import path and idiom for downstream tasks**

Throughout the rest of the plan, `Duration` refers to `Monadial\Duration\FiniteDuration`. Tasks 21–28 import it via:

```php
use Monadial\Duration\Duration;            // abstract — for parameter types in interfaces
use Monadial\Duration\FiniteDuration;      // concrete — for construction
use Monadial\Duration\TimeUnit\TimeUnit;
```

Construction idiom:
- `FiniteDuration::fromTimeUnit(50, TimeUnit::Milliseconds())` — 50ms
- `FiniteDuration::fromTimeUnit(2, TimeUnit::Seconds())` — 2s

No further work in this task.

---

## Task 4: Identifier + Identifiable Interfaces

**Files:**
- Create: `packages/nexus-ddd-core/src/Identity/Identifier.php`
- Create: `packages/nexus-ddd-core/src/Identity/Identifiable.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Identity/IdentifierContractTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/nexus-ddd-core/tests/Unit/Identity/IdentifierContractTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Identity;

use Monadial\Nexus\Ddd\Core\Identity\Identifiable;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class IdentifierContractTest extends TestCase
{
    #[Test]
    public function identifierInterfaceDeclaresExpectedMethods(): void
    {
        $reflection = new ReflectionClass(Identifier::class);
        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('value'));
        self::assertTrue($reflection->hasMethod('equals'));
        self::assertTrue($reflection->hasMethod('fromString'));

        $value = $reflection->getMethod('value');
        self::assertSame('string', (string) $value->getReturnType());

        $fromString = $reflection->getMethod('fromString');
        self::assertTrue($fromString->isStatic());
    }

    #[Test]
    public function identifiableInterfaceRequiresId(): void
    {
        $reflection = new ReflectionClass(Identifiable::class);
        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('id'));
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Identity/IdentifierContractTest.php -v`
Expected: FAIL — `Class "Monadial\Nexus\Ddd\Core\Identity\Identifier" not found`.

- [ ] **Step 3: Create the interfaces**

Create `packages/nexus-ddd-core/src/Identity/Identifier.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;

/**
 * @psalm-api
 *
 * Universal identity contract. Any value object that uniquely identifies something
 * (aggregate, message, scheduled task, …) implements this.
 */
interface Identifier
{
    /** Canonical string serialization for storage. */
    public function value(): string;

    /** Equality requires both runtime type AND value match. */
    public function equals(Identifier $other): bool;

    /**
     * Reconstruct an instance from its canonical string form.
     * Used by event store / outbox / snapshot rehydration.
     *
     * @throws InvalidIdentifierException on parse failure
     */
    public static function fromString(string $value): static;
}
```

Create `packages/nexus-ddd-core/src/Identity/Identifiable.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

/** @psalm-api */
interface Identifiable
{
    public function id(): Identifier;
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Identity/IdentifierContractTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-ddd-core/src/Identity packages/nexus-ddd-core/tests/Unit/Identity
git commit -m "feat(ddd-core): add Identifier and Identifiable interfaces"
```

---

## Task 5: IdGenerator + UlidGenerator

**Files:**
- Create: `packages/nexus-ddd-core/src/Identity/IdGenerator.php`
- Create: `packages/nexus-ddd-core/src/Identity/UlidGenerator.php`
- Create: `packages/nexus-ddd-core/src/Value/UlidValue.php` (deferred to Task 12 fully — but a minimal stub is needed here for `next(): Identifier` to return something. We'll create a minimal `UlidValue` here and elaborate in Task 12.)

> **Note:** This task creates the minimum `UlidValue` to satisfy `IdGenerator::next(): Identifier`. Task 12 will fully implement `UlidValue` (extends `WrappedValue<string>`, implements `Identifier`). This minimal version is replaced in Task 12.

- [ ] **Step 1: Write the failing test**

Create `packages/nexus-ddd-core/tests/Unit/Identity/UlidGeneratorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Identity;

use Monadial\Nexus\Ddd\Core\Identity\IdGenerator;
use Monadial\Nexus\Ddd\Core\Identity\UlidGenerator;
use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UlidGenerator::class)]
final class UlidGeneratorTest extends TestCase
{
    #[Test]
    public function nextReturnsAUlidIdentifier(): void
    {
        $gen = new UlidGenerator();
        self::assertInstanceOf(IdGenerator::class, $gen);

        $id = $gen->next();
        self::assertInstanceOf(UlidValue::class, $id);
        self::assertSame(26, strlen($id->value()));   // ULID canonical length
    }

    #[Test]
    public function consecutiveCallsReturnDifferentIds(): void
    {
        $gen = new UlidGenerator();
        $a = $gen->next();
        $b = $gen->next();
        self::assertNotSame($a->value(), $b->value());
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Identity/UlidGeneratorTest.php -v`
Expected: FAIL — class not found.

- [ ] **Step 3: Create IdGenerator interface**

Create `packages/nexus-ddd-core/src/Identity/IdGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

/** @psalm-api */
interface IdGenerator
{
    public function next(): Identifier;
}
```

- [ ] **Step 4: Create minimal UlidValue (stub — fully fleshed out in Task 12)**

Create `packages/nexus-ddd-core/src/Value/UlidValue.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Symfony\Component\Uid\Ulid;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Minimal ULID-backed Identifier. Will be enriched in Task 12 to extend WrappedValue.
 */
class UlidValue implements Identifier
{
    final public function __construct(private readonly string $value)
    {
        if (! Ulid::isValid($value)) {
            throw InvalidIdentifierException::malformed(static::class, $value, 'not a valid ULID');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(Identifier $other): bool
    {
        return $other instanceof static && $other->value === $this->value;
    }

    public static function fromString(string $value): static
    {
        return new static($value);
    }
}
```

- [ ] **Step 5: Create UlidGenerator**

Create `packages/nexus-ddd-core/src/Identity/UlidGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use Symfony\Component\Uid\Ulid;

/** @psalm-api */
final class UlidGenerator implements IdGenerator
{
    public function next(): Identifier
    {
        return new UlidValue((new Ulid())->toBase32());
    }
}
```

- [ ] **Step 6: Run the test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Identity/UlidGeneratorTest.php -v`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add packages/nexus-ddd-core/src/Identity packages/nexus-ddd-core/src/Value/UlidValue.php packages/nexus-ddd-core/tests/Unit/Identity
git commit -m "feat(ddd-core): add IdGenerator interface and UlidGenerator (with minimal UlidValue)"
```

---

## Task 6: UuidGenerator

**Files:**
- Create: `packages/nexus-ddd-core/src/Identity/UuidGenerator.php`
- Create: `packages/nexus-ddd-core/src/Value/UuidValue.php` (minimal; elaborated in Task 13)
- Create: `packages/nexus-ddd-core/tests/Unit/Identity/UuidGeneratorTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/nexus-ddd-core/tests/Unit/Identity/UuidGeneratorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Identity;

use Monadial\Nexus\Ddd\Core\Identity\UuidGenerator;
use Monadial\Nexus\Ddd\Core\Value\UuidValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UuidGenerator::class)]
final class UuidGeneratorTest extends TestCase
{
    #[Test]
    public function nextReturnsUuidValue(): void
    {
        $id = (new UuidGenerator())->next();
        self::assertInstanceOf(UuidValue::class, $id);
        self::assertSame(36, strlen($id->value()));
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Identity/UuidGeneratorTest.php -v`
Expected: FAIL — class not found.

- [ ] **Step 3: Create minimal UuidValue**

Create `packages/nexus-ddd-core/src/Value/UuidValue.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Symfony\Component\Uid\Uuid;

/**
 * @psalm-api
 * @psalm-immutable
 */
class UuidValue implements Identifier
{
    final public function __construct(private readonly string $value)
    {
        if (! Uuid::isValid($value)) {
            throw InvalidIdentifierException::malformed(static::class, $value, 'not a valid UUID');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(Identifier $other): bool
    {
        return $other instanceof static && $other->value === $this->value;
    }

    public static function fromString(string $value): static
    {
        return new static($value);
    }
}
```

- [ ] **Step 4: Create UuidGenerator**

Create `packages/nexus-ddd-core/src/Identity/UuidGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

use Monadial\Nexus\Ddd\Core\Value\UuidValue;
use Symfony\Component\Uid\Uuid;

/** @psalm-api */
final class UuidGenerator implements IdGenerator
{
    public function next(): Identifier
    {
        return new UuidValue((string) Uuid::v7());
    }
}
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Identity/UuidGeneratorTest.php -v`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-ddd-core/src/Identity/UuidGenerator.php packages/nexus-ddd-core/src/Value/UuidValue.php packages/nexus-ddd-core/tests/Unit/Identity/UuidGeneratorTest.php
git commit -m "feat(ddd-core): add UuidGenerator and UuidValue"
```

---

## Task 7: CompositeIdentifier + AbstractCompositeIdentifier

**Files:**
- Create: `packages/nexus-ddd-core/src/Identity/CompositeIdentifier.php`
- Create: `packages/nexus-ddd-core/src/Identity/AbstractCompositeIdentifier.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Identity/CompositeIdentifierTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/nexus-ddd-core/tests/Unit/Identity/CompositeIdentifierTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Identity;

use Monadial\Nexus\Ddd\Core\Identity\AbstractCompositeIdentifier;
use Monadial\Nexus\Ddd\Core\Identity\CompositeIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractCompositeIdentifier::class)]
final class CompositeIdentifierTest extends TestCase
{
    #[Test]
    public function canonicalValueJoinsComponentsWithColon(): void
    {
        $id = new TenantOrderId('acme', 'order-1');
        self::assertSame('acme:order-1', $id->value());
    }

    #[Test]
    public function fromStringRoundtripsValueComponents(): void
    {
        $original = new TenantOrderId('acme', 'order-1');
        $rehydrated = TenantOrderId::fromString($original->value());
        self::assertTrue($rehydrated->equals($original));
        self::assertSame(['tenant' => 'acme', 'order' => 'order-1'], $rehydrated->components());
    }

    #[Test]
    public function urlEncodingHandlesColonInComponentValues(): void
    {
        $id = new TenantOrderId('foo:bar', 'baz');
        self::assertSame('foo%3Abar:baz', $id->value());
    }

    #[Test]
    public function differentTypesAreNotEqualEvenWithSameValue(): void
    {
        $id = new TenantOrderId('a', 'b');
        $other = $this->createMock(CompositeIdentifier::class);
        self::assertFalse($id->equals($other));
    }
}

/** @psalm-suppress MissingConstructor */
final class TenantOrderId extends AbstractCompositeIdentifier
{
    public function __construct(string $tenant, string $order)
    {
        parent::__construct(['tenant' => $tenant, 'order' => $order]);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Identity/CompositeIdentifierTest.php -v`
Expected: FAIL — class not found.

- [ ] **Step 3: Create CompositeIdentifier interface**

Create `packages/nexus-ddd-core/src/Identity/CompositeIdentifier.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

/**
 * @psalm-api
 *
 * Identifier composed of multiple named components (e.g., (tenantId, orderId)).
 * Storage uses canonical string serialization; query layers can also access components.
 */
interface CompositeIdentifier extends Identifier
{
    /** @return array<string, scalar> components by name (in declaration order) */
    public function components(): array;
}
```

- [ ] **Step 4: Create AbstractCompositeIdentifier**

Create `packages/nexus-ddd-core/src/Identity/AbstractCompositeIdentifier.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Default canonical format: components joined by `:`, with values URL-encoded.
 * Subclasses pass their components to the constructor; fromString reconstructs.
 *
 * Override `canonicalize`/`parseComponents` for custom formats; the round-trip
 * MUST be deterministic.
 */
abstract class AbstractCompositeIdentifier implements CompositeIdentifier
{
    /** @param array<string, scalar> $components */
    protected function __construct(private readonly array $components) {}

    /** @return array<string, scalar> */
    final public function components(): array
    {
        return $this->components;
    }

    public function value(): string
    {
        return implode(
            ':',
            array_map(
                static fn(mixed $v): string => rawurlencode((string) $v),
                array_values($this->components),
            ),
        );
    }

    public function equals(Identifier $other): bool
    {
        if (! $other instanceof static) {
            return false;
        }
        return $other->components === $this->components;
    }

    /**
     * Default reconstruction: subclasses MUST override if their constructor signature
     * cannot accept positional values from the canonical string parsed in declaration order.
     */
    public static function fromString(string $value): static
    {
        $parts = array_map(
            static fn(string $p): string => rawurldecode($p),
            explode(':', $value),
        );
        try {
            // Subclass constructors typically accept positional args matching component declaration order
            // @psalm-suppress UnsafeInstantiation
            return new static(...$parts);
        } catch (\Throwable $e) {
            throw InvalidIdentifierException::malformed(static::class, $value, $e->getMessage());
        }
    }
}
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Identity/CompositeIdentifierTest.php -v`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-ddd-core/src/Identity/CompositeIdentifier.php packages/nexus-ddd-core/src/Identity/AbstractCompositeIdentifier.php packages/nexus-ddd-core/tests/Unit/Identity/CompositeIdentifierTest.php
git commit -m "feat(ddd-core): add CompositeIdentifier interface and abstract base"
```

---

## Task 8: WrappedValue Abstract Class

**Files:**
- Create: `packages/nexus-ddd-core/src/Value/WrappedValue.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Value/WrappedValueTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/nexus-ddd-core/tests/Unit/Value/WrappedValueTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Core\Value\WrappedValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WrappedValue::class)]
final class WrappedValueTest extends TestCase
{
    #[Test]
    public function valueReturnsConstructedInner(): void
    {
        $v = new IntStub(42);
        self::assertSame(42, $v->value());
    }

    #[Test]
    public function mapTransformsAndReturnsNewInstance(): void
    {
        $v = new IntStub(2);
        $mapped = $v->map(static fn(int $i): int => $i * 3);
        self::assertSame(6, $mapped->value());
        self::assertNotSame($v, $mapped);
        self::assertSame(2, $v->value());           // original unchanged
    }

    #[Test]
    public function flatMapReturnsResultOfFn(): void
    {
        $v = new IntStub(2);
        $result = $v->flatMap(static fn(int $i): IntStub => new IntStub($i + 100));
        self::assertSame(102, $result->value());
    }

    #[Test]
    public function equalsRequiresSameClassAndValue(): void
    {
        $a = new IntStub(1);
        $b = new IntStub(1);
        $c = new IntStub(2);
        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}

/** @extends WrappedValue<int> */
final class IntStub extends WrappedValue
{
    public function __construct(int $value)
    {
        parent::__construct($value);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Value/WrappedValueTest.php -v`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement WrappedValue**

Create `packages/nexus-ddd-core/src/Value/WrappedValue.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @template T
 *
 * Functor-style abstract for primitive-wrapping value objects.
 * Subclasses get equals(), map(), flatMap() for free.
 */
abstract class WrappedValue
{
    /** @param T $value */
    protected function __construct(private readonly mixed $value) {}

    /** @return T */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * @template U
     * @param callable(T): U $fn
     * @return static
     */
    public function map(callable $fn): static
    {
        return new static($fn($this->value));
    }

    /**
     * @template U of WrappedValue
     * @param callable(T): U $fn
     * @return U
     */
    public function flatMap(callable $fn): WrappedValue
    {
        return $fn($this->value);
    }

    public function equals(WrappedValue $other): bool
    {
        return $other instanceof static && $other->value === $this->value;
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Value/WrappedValueTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-ddd-core/src/Value/WrappedValue.php packages/nexus-ddd-core/tests/Unit/Value/WrappedValueTest.php
git commit -m "feat(ddd-core): add WrappedValue<T> abstract base (functor)"
```

---

## Task 9: Concrete Wrapped Value Bases (StringValue, IntValue, FloatValue, BoolValue, ArrayValue)

**Files:**
- Create: `packages/nexus-ddd-core/src/Value/StringValue.php`
- Create: `packages/nexus-ddd-core/src/Value/IntValue.php`
- Create: `packages/nexus-ddd-core/src/Value/FloatValue.php`
- Create: `packages/nexus-ddd-core/src/Value/BoolValue.php`
- Create: `packages/nexus-ddd-core/src/Value/ArrayValue.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Value/ConcreteWrappedValuesTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/nexus-ddd-core/tests/Unit/Value/ConcreteWrappedValuesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Core\Value\ArrayValue;
use Monadial\Nexus\Ddd\Core\Value\BoolValue;
use Monadial\Nexus\Ddd\Core\Value\FloatValue;
use Monadial\Nexus\Ddd\Core\Value\IntValue;
use Monadial\Nexus\Ddd\Core\Value\StringValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StringValue::class)]
#[CoversClass(IntValue::class)]
#[CoversClass(FloatValue::class)]
#[CoversClass(BoolValue::class)]
#[CoversClass(ArrayValue::class)]
final class ConcreteWrappedValuesTest extends TestCase
{
    #[Test]
    public function stringValueWrapsString(): void
    {
        $v = new class('hello') extends StringValue {};
        self::assertSame('hello', $v->value());
    }

    #[Test]
    public function intValueWrapsInt(): void
    {
        $v = new class(42) extends IntValue {};
        self::assertSame(42, $v->value());
    }

    #[Test]
    public function floatValueWrapsFloat(): void
    {
        $v = new class(3.14) extends FloatValue {};
        self::assertSame(3.14, $v->value());
    }

    #[Test]
    public function boolValueWrapsBool(): void
    {
        $v = new class(true) extends BoolValue {};
        self::assertSame(true, $v->value());
    }

    #[Test]
    public function arrayValueWrapsArray(): void
    {
        $v = new class([1, 2, 3]) extends ArrayValue {};
        self::assertSame([1, 2, 3], $v->value());
    }

    #[Test]
    public function stringValueMapPreservesType(): void
    {
        $v = new class('abc') extends StringValue {};
        $mapped = $v->map(strtoupper(...));
        self::assertSame('ABC', $mapped->value());
        self::assertInstanceOf($v::class, $mapped);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Value/ConcreteWrappedValuesTest.php -v`
Expected: FAIL — class not found.

- [ ] **Step 3: Create StringValue**

Create `packages/nexus-ddd-core/src/Value/StringValue.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @extends WrappedValue<string>
 */
abstract class StringValue extends WrappedValue
{
    public function __construct(string $value)
    {
        parent::__construct($value);
    }
}
```

- [ ] **Step 4: Create IntValue**

Create `packages/nexus-ddd-core/src/Value/IntValue.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @extends WrappedValue<int>
 */
abstract class IntValue extends WrappedValue
{
    public function __construct(int $value)
    {
        parent::__construct($value);
    }
}
```

- [ ] **Step 5: Create FloatValue**

Create `packages/nexus-ddd-core/src/Value/FloatValue.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @extends WrappedValue<float>
 */
abstract class FloatValue extends WrappedValue
{
    public function __construct(float $value)
    {
        parent::__construct($value);
    }
}
```

- [ ] **Step 6: Create BoolValue**

Create `packages/nexus-ddd-core/src/Value/BoolValue.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @extends WrappedValue<bool>
 */
abstract class BoolValue extends WrappedValue
{
    public function __construct(bool $value)
    {
        parent::__construct($value);
    }
}
```

- [ ] **Step 7: Create ArrayValue**

Create `packages/nexus-ddd-core/src/Value/ArrayValue.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @extends WrappedValue<array>
 */
abstract class ArrayValue extends WrappedValue
{
    public function __construct(array $value)
    {
        parent::__construct($value);
    }
}
```

- [ ] **Step 8: Run the test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Value/ConcreteWrappedValuesTest.php -v`
Expected: PASS — 6 tests green.

- [ ] **Step 9: Commit**

```bash
git add packages/nexus-ddd-core/src/Value/StringValue.php packages/nexus-ddd-core/src/Value/IntValue.php packages/nexus-ddd-core/src/Value/FloatValue.php packages/nexus-ddd-core/src/Value/BoolValue.php packages/nexus-ddd-core/src/Value/ArrayValue.php packages/nexus-ddd-core/tests/Unit/Value/ConcreteWrappedValuesTest.php
git commit -m "feat(ddd-core): add concrete WrappedValue bases (String/Int/Float/Bool/Array)"
```

---

## Task 10: UlidValue and UuidValue (full WrappedValue + Identifier integration)

**Files:**
- Modify: `packages/nexus-ddd-core/src/Value/UlidValue.php` (replace minimal stub from Task 5)
- Modify: `packages/nexus-ddd-core/src/Value/UuidValue.php` (replace minimal stub from Task 6)
- Create: `packages/nexus-ddd-core/tests/Unit/Value/UlidValueTest.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Value/UuidValueTest.php`

- [ ] **Step 1: Write failing tests**

Create `packages/nexus-ddd-core/tests/Unit/Value/UlidValueTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use Monadial\Nexus\Ddd\Core\Value\WrappedValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(UlidValue::class)]
final class UlidValueTest extends TestCase
{
    #[Test]
    public function ulidValueIsBothWrappedValueAndIdentifier(): void
    {
        $ulid = (new Ulid())->toBase32();
        $v = new UlidValue($ulid);
        self::assertInstanceOf(WrappedValue::class, $v);
        self::assertInstanceOf(Identifier::class, $v);
        self::assertSame($ulid, $v->value());
    }

    #[Test]
    public function fromStringRehydratesUlidValue(): void
    {
        $ulid = (new Ulid())->toBase32();
        $rehydrated = UlidValue::fromString($ulid);
        self::assertSame($ulid, $rehydrated->value());
    }

    #[Test]
    public function malformedValueThrows(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        new UlidValue('not-a-ulid');
    }

    #[Test]
    public function equalsByTypeAndValue(): void
    {
        $ulid = (new Ulid())->toBase32();
        $a = new UlidValue($ulid);
        $b = new UlidValue($ulid);
        self::assertTrue($a->equals($b));
    }
}
```

Create `packages/nexus-ddd-core/tests/Unit/Value/UuidValueTest.php` (same shape, with Uuid):

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Value\UuidValue;
use Monadial\Nexus\Ddd\Core\Value\WrappedValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UuidValue::class)]
final class UuidValueTest extends TestCase
{
    #[Test]
    public function uuidValueIsBothWrappedValueAndIdentifier(): void
    {
        $uuid = (string) Uuid::v7();
        $v = new UuidValue($uuid);
        self::assertInstanceOf(WrappedValue::class, $v);
        self::assertInstanceOf(Identifier::class, $v);
        self::assertSame($uuid, $v->value());
    }

    #[Test]
    public function fromStringRehydrates(): void
    {
        $uuid = (string) Uuid::v7();
        self::assertSame($uuid, UuidValue::fromString($uuid)->value());
    }

    #[Test]
    public function malformedValueThrows(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        new UuidValue('not-a-uuid');
    }
}
```

- [ ] **Step 2: Run tests, verify they fail**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Value/UlidValueTest.php packages/nexus-ddd-core/tests/Unit/Value/UuidValueTest.php -v`
Expected: FAIL — `UlidValue` does not extend `WrappedValue` (the stub from Task 5 is bare).

- [ ] **Step 3: Replace UlidValue with WrappedValue-extending version**

Open `packages/nexus-ddd-core/src/Value/UlidValue.php` and replace contents:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Symfony\Component\Uid\Ulid;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @extends WrappedValue<string>
 */
class UlidValue extends WrappedValue implements Identifier
{
    final public function __construct(string $value)
    {
        if (! Ulid::isValid($value)) {
            throw InvalidIdentifierException::malformed(static::class, $value, 'not a valid ULID');
        }
        parent::__construct($value);
    }

    public function equals(Identifier|WrappedValue $other): bool
    {
        return $other instanceof static && $other->value() === $this->value();
    }

    public static function fromString(string $value): static
    {
        return new static($value);
    }
}
```

- [ ] **Step 4: Replace UuidValue with WrappedValue-extending version**

Open `packages/nexus-ddd-core/src/Value/UuidValue.php` and replace contents:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Symfony\Component\Uid\Uuid;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @extends WrappedValue<string>
 */
class UuidValue extends WrappedValue implements Identifier
{
    final public function __construct(string $value)
    {
        if (! Uuid::isValid($value)) {
            throw InvalidIdentifierException::malformed(static::class, $value, 'not a valid UUID');
        }
        parent::__construct($value);
    }

    public function equals(Identifier|WrappedValue $other): bool
    {
        return $other instanceof static && $other->value() === $this->value();
    }

    public static function fromString(string $value): static
    {
        return new static($value);
    }
}
```

- [ ] **Step 5: Run tests, verify all pass (including older tests for the generators)**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Value packages/nexus-ddd-core/tests/Unit/Identity -v`
Expected: PASS — UlidValue/UuidValue/Generator tests all green.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-ddd-core/src/Value/UlidValue.php packages/nexus-ddd-core/src/Value/UuidValue.php packages/nexus-ddd-core/tests/Unit/Value/UlidValueTest.php packages/nexus-ddd-core/tests/Unit/Value/UuidValueTest.php
git commit -m "feat(ddd-core): UlidValue/UuidValue extend WrappedValue and implement Identifier"
```

---

## Task 11: ObjectValue Abstract Class

**Files:**
- Create: `packages/nexus-ddd-core/src/Value/ObjectValue.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Value/ObjectValueTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Value/ObjectValueTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Core\Value\ObjectValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObjectValue::class)]
final class ObjectValueTest extends TestCase
{
    #[Test]
    public function structuralEqualityByDeclaredProperties(): void
    {
        $a = new FullName('Ada', 'Lovelace');
        $b = new FullName('Ada', 'Lovelace');
        $c = new FullName('Charles', 'Babbage');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    #[Test]
    public function differentTypesAreNotEqual(): void
    {
        $a = new FullName('Ada', 'Lovelace');
        $b = new OtherCompositeValue('Ada', 'Lovelace');
        self::assertFalse($a->equals($b));
    }
}

final class FullName extends ObjectValue
{
    public function __construct(
        public readonly string $first,
        public readonly string $last,
    ) {}
}

final class OtherCompositeValue extends ObjectValue
{
    public function __construct(
        public readonly string $first,
        public readonly string $last,
    ) {}
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Value/ObjectValueTest.php -v`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement ObjectValue**

Create `packages/nexus-ddd-core/src/Value/ObjectValue.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

use ReflectionObject;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Composite value object base. Equality is structural — all declared instance
 * properties of the same runtime class are compared. Properties SHOULD be readonly.
 */
abstract class ObjectValue
{
    public function equals(ObjectValue $other): bool
    {
        if (static::class !== $other::class) {
            return false;
        }
        $thisReflection = new ReflectionObject($this);
        $otherReflection = new ReflectionObject($other);
        foreach ($thisReflection->getProperties() as $prop) {
            $name = $prop->getName();
            $thisVal = $prop->getValue($this);
            $otherProp = $otherReflection->getProperty($name);
            $otherVal = $otherProp->getValue($other);
            if ($thisVal !== $otherVal) {
                return false;
            }
        }
        return true;
    }
}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Value/ObjectValueTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-ddd-core/src/Value/ObjectValue.php packages/nexus-ddd-core/tests/Unit/Value/ObjectValueTest.php
git commit -m "feat(ddd-core): add ObjectValue with structural equality"
```

---

## Task 12: Entity and EventSourceable Interfaces

**Files:**
- Create: `packages/nexus-ddd-core/src/Entity/Entity.php`
- Create: `packages/nexus-ddd-core/src/Entity/EventSourceable.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Entity/EntityContractTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Entity/EntityContractTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Entity;

use Monadial\Nexus\Ddd\Core\Entity\Entity;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\Core\Identity\Identifiable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EntityContractTest extends TestCase
{
    #[Test]
    public function entityExtendsIdentifiable(): void
    {
        $reflection = new ReflectionClass(Entity::class);
        self::assertTrue($reflection->isInterface());
        self::assertContains(Identifiable::class, $reflection->getInterfaceNames());
        self::assertTrue($reflection->hasMethod('equals'));
    }

    #[Test]
    public function eventSourceableExtendsIdentifiable(): void
    {
        $reflection = new ReflectionClass(EventSourceable::class);
        self::assertTrue($reflection->isInterface());
        self::assertContains(Identifiable::class, $reflection->getInterfaceNames());
        foreach (['pullRecordedEvents', 'replay', 'version', 'stateVersion'] as $m) {
            self::assertTrue($reflection->hasMethod($m), "EventSourceable must declare $m()");
        }
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Entity/EntityContractTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Create Entity interface**

Create `packages/nexus-ddd-core/src/Entity/Entity.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Entity;

use Monadial\Nexus\Ddd\Core\Identity\Identifiable;

/**
 * @psalm-api
 *
 * Domain entity contract: identity-based equality. Both runtime type AND id must match.
 */
interface Entity extends Identifiable
{
    public function equals(Entity $other): bool;
}
```

- [ ] **Step 4: Create EventSourceable interface**

Create `packages/nexus-ddd-core/src/Entity/EventSourceable.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Entity;

use Monadial\Nexus\Ddd\Core\Identity\Identifiable;

/**
 * @psalm-api
 *
 * Anything the framework persists via EventSourcingStrategy implements this.
 * AggregateRoot and AbstractProcessManager both implement EventSourceable.
 */
interface EventSourceable extends Identifiable
{
    /** @return array<int, object> */
    public function pullRecordedEvents(): array;

    /** @param iterable<int, object> $events */
    public function replay(iterable $events): void;

    public function version(): int;

    public function stateVersion(): int;
}
```

- [ ] **Step 5: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Entity/EntityContractTest.php -v`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-ddd-core/src/Entity packages/nexus-ddd-core/tests/Unit/Entity
git commit -m "feat(ddd-core): add Entity and EventSourceable interfaces"
```

---

## Task 13: SnapshotConstructor Attribute

**Files:**
- Create: `packages/nexus-ddd-core/src/Aggregate/Attribute/SnapshotConstructor.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Aggregate/Attribute/SnapshotConstructorTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Aggregate/Attribute/SnapshotConstructorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\Core\Aggregate\Attribute\SnapshotConstructor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(SnapshotConstructor::class)]
final class SnapshotConstructorTest extends TestCase
{
    #[Test]
    public function attributeTargetsStaticMethods(): void
    {
        $reflection = new ReflectionClass(SnapshotConstructor::class);
        $attrs = $reflection->getAttributes(Attribute::class);
        self::assertNotEmpty($attrs);
        $instance = $attrs[0]->newInstance();
        self::assertSame(Attribute::TARGET_METHOD, $instance->flags);
    }

    #[Test]
    public function attributeIsDiscoverableViaReflection(): void
    {
        $cls = new class {
            #[SnapshotConstructor]
            public static function rehydrate(int $a): self { return new self(); }
        };
        $method = new ReflectionMethod($cls, 'rehydrate');
        self::assertNotEmpty($method->getAttributes(SnapshotConstructor::class));
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Aggregate/Attribute/SnapshotConstructorTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Implement attribute**

Create `packages/nexus-ddd-core/src/Aggregate/Attribute/SnapshotConstructor.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Marks a static method on an aggregate (or sub-entity, or PM) as the constructor
 * to use during snapshot rehydration. Valinor calls it with snapshot-state fields.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class SnapshotConstructor {}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Aggregate/Attribute/SnapshotConstructorTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-ddd-core/src/Aggregate/Attribute packages/nexus-ddd-core/tests/Unit/Aggregate/Attribute
git commit -m "feat(ddd-core): add #[SnapshotConstructor] attribute"
```

---

## Task 14: ApplyDispatcher (reflection-cached resolver)

**Files:**
- Create: `packages/nexus-ddd-core/src/Aggregate/Internal/ApplyDispatcher.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Aggregate/Internal/ApplyDispatcherTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Aggregate/Internal/ApplyDispatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate\Internal;

use Monadial\Nexus\Ddd\Core\Aggregate\Internal\ApplyDispatcher;
use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodAmbiguousException;
use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApplyDispatcher::class)]
final class ApplyDispatcherTest extends TestCase
{
    #[Test]
    public function dispatchInvokesApplyMethodMatchingShortName(): void
    {
        $aggregate = new TargetAggregate();
        $dispatcher = new ApplyDispatcher();

        $dispatcher->dispatch($aggregate, new SomeEvent('hello'));

        self::assertSame('hello', $aggregate->captured);
    }

    #[Test]
    public function missingApplyMethodThrows(): void
    {
        $aggregate = new TargetAggregate();
        $dispatcher = new ApplyDispatcher();

        $this->expectException(ApplyMethodNotFoundException::class);
        $dispatcher->dispatch($aggregate, new UnhandledEvent());
    }

    #[Test]
    public function dispatchIsCachedAcrossInvocations(): void
    {
        $dispatcher = new ApplyDispatcher();
        $aggregate = new TargetAggregate();
        $dispatcher->dispatch($aggregate, new SomeEvent('a'));
        $dispatcher->dispatch($aggregate, new SomeEvent('b'));    // 2nd call uses cache
        self::assertSame('b', $aggregate->captured);
    }
}

final class TargetAggregate
{
    public string $captured = '';
    private function applySomeEvent(SomeEvent $e): void { $this->captured = $e->payload; }
}

final class SomeEvent
{
    public function __construct(public readonly string $payload) {}
}

final class UnhandledEvent {}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Aggregate/Internal/ApplyDispatcherTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Implement ApplyDispatcher**

Create `packages/nexus-ddd-core/src/Aggregate/Internal/ApplyDispatcher.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate\Internal;

use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodAmbiguousException;
use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodNotFoundException;
use ReflectionClass;
use ReflectionMethod;

/**
 * @psalm-internal Monadial\Nexus\Ddd\Core
 *
 * Resolves and invokes the `applyXxx` method on an entity for a given event.
 * Convention: method name = `apply` + event class short name (case-sensitive).
 * Per-class resolution is cached (one ReflectionMethod per (entityClass, eventClass)).
 *
 * Cross-namespace short-name collisions throw ApplyMethodAmbiguousException at
 * resolution time.
 */
final class ApplyDispatcher
{
    /** @var array<class-string, array<class-string, ReflectionMethod>> */
    private array $cache = [];

    /** @var array<class-string, array<string, list<class-string>>> */
    private array $shortNameIndex = [];

    public function dispatch(object $entity, object $event): void
    {
        $method = $this->resolve($entity::class, $event::class);
        $method->setAccessible(true);
        $method->invoke($entity, $event);
    }

    /**
     * @param class-string $entityClass
     * @param class-string $eventClass
     */
    public function resolve(string $entityClass, string $eventClass): ReflectionMethod
    {
        if (isset($this->cache[$entityClass][$eventClass])) {
            return $this->cache[$entityClass][$eventClass];
        }

        $shortName = $this->shortName($eventClass);
        $methodName = 'apply' . $shortName;

        $reflection = new ReflectionClass($entityClass);
        if (! $reflection->hasMethod($methodName)) {
            throw ApplyMethodNotFoundException::for($entityClass, $eventClass);
        }

        $method = $reflection->getMethod($methodName);
        $this->cache[$entityClass][$eventClass] = $method;
        $this->shortNameIndex[$entityClass][$shortName][] = $eventClass;

        if (count($this->shortNameIndex[$entityClass][$shortName]) > 1) {
            throw ApplyMethodAmbiguousException::for(
                $entityClass,
                $shortName,
                $this->shortNameIndex[$entityClass][$shortName],
            );
        }

        return $method;
    }

    /** @param class-string $fqn */
    private function shortName(string $fqn): string
    {
        $parts = explode('\\', $fqn);
        return end($parts);
    }
}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Aggregate/Internal/ApplyDispatcherTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-ddd-core/src/Aggregate/Internal packages/nexus-ddd-core/tests/Unit/Aggregate/Internal
git commit -m "feat(ddd-core): add ApplyDispatcher (reflection-cached applyXxx resolver)"
```

---

## Task 15: AggregateRoot Abstract Class

**Files:**
- Create: `packages/nexus-ddd-core/src/Aggregate/AggregateRoot.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Aggregate/AggregateRootTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Aggregate/AggregateRootTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\Entity;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(AggregateRoot::class)]
final class AggregateRootTest extends TestCase
{
    #[Test]
    public function recordThatInvokesApplyAndAppendsEvent(): void
    {
        $a = TestAggregate::create(self::ulid());
        $a->doSomething('hello');

        self::assertSame('hello', $a->state);
        $events = $a->pullRecordedEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(SomethingHappened::class, $events[0]);
    }

    #[Test]
    public function pullRecordedEventsClearsTheBuffer(): void
    {
        $a = TestAggregate::create(self::ulid());
        $a->doSomething('a');
        $a->pullRecordedEvents();
        self::assertCount(0, $a->pullRecordedEvents());
    }

    #[Test]
    public function aggregateIsBothEntityAndEventSourceable(): void
    {
        $a = TestAggregate::create(self::ulid());
        self::assertInstanceOf(Entity::class, $a);
        self::assertInstanceOf(EventSourceable::class, $a);
    }

    #[Test]
    public function entityEqualityRequiresSameTypeAndId(): void
    {
        $id = self::ulid();
        $a = TestAggregate::create($id);
        $b = TestAggregate::create($id);
        $c = TestAggregate::create(self::ulid());
        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    #[Test]
    public function defaultStateVersionIsOne(): void
    {
        $a = TestAggregate::create(self::ulid());
        self::assertSame(1, $a->stateVersion());
    }

    private static function ulid(): UlidValue
    {
        return new UlidValue((new Ulid())->toBase32());
    }
}

final class TestAggregate extends AggregateRoot
{
    public string $state = '';

    public static function create(Identifier $id): self
    {
        return new self($id);
    }

    public function id(): Identifier
    {
        return $this->id;
    }

    public function doSomething(string $value): void
    {
        $this->recordThat(new SomethingHappened($value));
    }

    private function applySomethingHappened(SomethingHappened $e): void
    {
        $this->state = $e->payload;
    }
}

final class SomethingHappened
{
    public function __construct(public readonly string $payload) {}
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Aggregate/AggregateRootTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Implement AggregateRoot**

Create `packages/nexus-ddd-core/src/Aggregate/AggregateRoot.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\Internal\ApplyDispatcher;
use Monadial\Nexus\Ddd\Core\Entity\Entity;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;

/**
 * @psalm-api
 *
 * Base class for all aggregates. Subclasses must implement id() and provide applyXxx()
 * methods for every event recorded via recordThat().
 *
 * recordThat() invokes the corresponding applyXxx() synchronously to mutate state,
 * then appends the event to the recorded buffer. pullRecordedEvents() returns and
 * clears the buffer (called by the repository at persist time).
 */
abstract class AggregateRoot implements Entity, EventSourceable
{
    private static ?ApplyDispatcher $dispatcher = null;

    /** @var array<int, object> */
    private array $recordedEvents = [];

    private int $version = 0;

    protected function __construct(protected readonly Identifier $id) {}

    abstract public function id(): Identifier;

    final public function version(): int
    {
        return $this->version;
    }

    public function stateVersion(): int
    {
        return 1;
    }

    final protected function recordThat(object $event): void
    {
        self::dispatcher()->dispatch($this, $event);
        $this->recordedEvents[] = $event;
        $this->version++;
    }

    /** @return array<int, object> */
    final public function pullRecordedEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];
        return $events;
    }

    /** @param iterable<int, object> $events */
    final public function replay(iterable $events): void
    {
        $dispatcher = self::dispatcher();
        foreach ($events as $event) {
            $dispatcher->dispatch($this, $event);
            $this->version++;
        }
    }

    final public function equals(Entity $other): bool
    {
        return $other instanceof static && $other->id->equals($this->id);
    }

    private static function dispatcher(): ApplyDispatcher
    {
        return self::$dispatcher ??= new ApplyDispatcher();
    }
}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Aggregate/AggregateRootTest.php -v`
Expected: PASS — 5 tests green.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-ddd-core/src/Aggregate/AggregateRoot.php packages/nexus-ddd-core/tests/Unit/Aggregate/AggregateRootTest.php
git commit -m "feat(ddd-core): add AggregateRoot abstract base class"
```

---

## Task 16: EventSourcedAggregateRoot

**Files:**
- Create: `packages/nexus-ddd-core/src/Aggregate/EventSourcedAggregateRoot.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Aggregate/EventSourcedAggregateRootTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Aggregate/EventSourcedAggregateRootTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(EventSourcedAggregateRoot::class)]
final class EventSourcedAggregateRootTest extends TestCase
{
    #[Test]
    public function replayReconstructsStateFromEvents(): void
    {
        $id = new UlidValue((new Ulid())->toBase32());
        $a = EsAggregate::create($id);
        $a->incrementBy(5);
        $a->incrementBy(7);
        $events = $a->pullRecordedEvents();

        $rehydrated = EsAggregate::create($id);
        $rehydrated->replay($events);

        self::assertSame(12, $rehydrated->total);
        self::assertSame(2, $rehydrated->version());
    }

    #[Test]
    public function replayDoesNotRecord(): void
    {
        $id = new UlidValue((new Ulid())->toBase32());
        $a = EsAggregate::create($id);
        $a->replay([new Incremented(3), new Incremented(2)]);

        self::assertSame(0, count($a->pullRecordedEvents()));
        self::assertSame(5, $a->total);
    }
}

final class EsAggregate extends EventSourcedAggregateRoot
{
    public int $total = 0;

    public static function create(Identifier $id): self
    {
        return new self($id);
    }

    public function id(): Identifier
    {
        return $this->id;
    }

    public function incrementBy(int $by): void
    {
        $this->recordThat(new Incremented($by));
    }

    private function applyIncremented(Incremented $e): void
    {
        $this->total += $e->by;
    }
}

final class Incremented
{
    public function __construct(public readonly int $by) {}
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Aggregate/EventSourcedAggregateRootTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Implement EventSourcedAggregateRoot**

Create `packages/nexus-ddd-core/src/Aggregate/EventSourcedAggregateRoot.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

/**
 * @psalm-api
 *
 * Base for event-sourced aggregates. State is reconstructed by replaying events
 * via the inherited replay() method. Subclasses define applyXxx() methods that
 * MUST be pure (no I/O, no recordThat(), no clock, no logging).
 */
abstract class EventSourcedAggregateRoot extends AggregateRoot
{
    // Inherits everything from AggregateRoot. Marker subclass to communicate intent
    // and to let the framework's PersistenceStrategy match on type.
}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Aggregate/EventSourcedAggregateRootTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-ddd-core/src/Aggregate/EventSourcedAggregateRoot.php packages/nexus-ddd-core/tests/Unit/Aggregate/EventSourcedAggregateRootTest.php
git commit -m "feat(ddd-core): add EventSourcedAggregateRoot base class"
```

---

## Task 17: StatefulAggregateRoot

**Files:**
- Create: `packages/nexus-ddd-core/src/Aggregate/StatefulAggregateRoot.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Aggregate/StatefulAggregateRootTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Aggregate/StatefulAggregateRootTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\StatefulAggregateRoot;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(StatefulAggregateRoot::class)]
final class StatefulAggregateRootTest extends TestCase
{
    #[Test]
    public function recordThatStillAppliesAndEmits(): void
    {
        $id = new UlidValue((new Ulid())->toBase32());
        $a = StatefulSample::create($id);
        $a->setName('Ada');

        self::assertSame('Ada', $a->name);
        self::assertCount(1, $a->pullRecordedEvents());
    }
}

final class StatefulSample extends StatefulAggregateRoot
{
    public string $name = '';

    public static function create(Identifier $id): self
    {
        return new self($id);
    }

    public function id(): Identifier
    {
        return $this->id;
    }

    public function setName(string $name): void
    {
        $this->recordThat(new NameSet($name));
    }

    private function applyNameSet(NameSet $e): void
    {
        $this->name = $e->name;
    }
}

final class NameSet
{
    public function __construct(public readonly string $name) {}
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Aggregate/StatefulAggregateRootTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Implement StatefulAggregateRoot**

Create `packages/nexus-ddd-core/src/Aggregate/StatefulAggregateRoot.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

/**
 * @psalm-api
 *
 * Base for state-stored aggregates. Mutable state is allowed; recordThat() still
 * invokes applyXxx() so the same convention applies. Persistence strategies
 * (Doctrine ORM / DBAL) save the aggregate's state directly rather than the event
 * stream, but events flow through to the EventBus regardless.
 */
abstract class StatefulAggregateRoot extends AggregateRoot
{
    // Inherits everything; marker for type discrimination.
}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Aggregate/StatefulAggregateRootTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-ddd-core/src/Aggregate/StatefulAggregateRoot.php packages/nexus-ddd-core/tests/Unit/Aggregate/StatefulAggregateRootTest.php
git commit -m "feat(ddd-core): add StatefulAggregateRoot base class"
```

---

## Task 18: Specification Interface and Combinators

**Files:**
- Create: `packages/nexus-ddd-core/src/Specification/Specification.php`
- Create: `packages/nexus-ddd-core/src/Specification/AbstractSpecification.php`
- Create: `packages/nexus-ddd-core/src/Specification/AndSpecification.php`
- Create: `packages/nexus-ddd-core/src/Specification/OrSpecification.php`
- Create: `packages/nexus-ddd-core/src/Specification/NotSpecification.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Specification/SpecificationCombinatorsTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Specification/SpecificationCombinatorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Specification;

use Monadial\Nexus\Ddd\Core\Specification\AbstractSpecification;
use Monadial\Nexus\Ddd\Core\Specification\Specification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpecificationCombinatorsTest extends TestCase
{
    #[Test]
    public function andRequiresBoth(): void
    {
        $isPositive = new IsPositive();
        $isEven = new IsEven();
        $spec = $isPositive->and($isEven);

        self::assertTrue($spec->isSatisfiedBy(4));
        self::assertFalse($spec->isSatisfiedBy(3));   // positive but odd
        self::assertFalse($spec->isSatisfiedBy(-2));  // even but negative
    }

    #[Test]
    public function orRequiresEither(): void
    {
        $isPositive = new IsPositive();
        $isEven = new IsEven();
        $spec = $isPositive->or($isEven);

        self::assertTrue($spec->isSatisfiedBy(4));    // both
        self::assertTrue($spec->isSatisfiedBy(3));    // only positive
        self::assertTrue($spec->isSatisfiedBy(-2));   // only even
        self::assertFalse($spec->isSatisfiedBy(-3));  // neither
    }

    #[Test]
    public function notInverts(): void
    {
        $isPositive = new IsPositive();
        $spec = $isPositive->not();
        self::assertFalse($spec->isSatisfiedBy(1));
        self::assertTrue($spec->isSatisfiedBy(-1));
    }
}

/** @extends AbstractSpecification<int> */
final class IsPositive extends AbstractSpecification
{
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return is_int($candidate) && $candidate > 0;
    }
}

/** @extends AbstractSpecification<int> */
final class IsEven extends AbstractSpecification
{
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return is_int($candidate) && $candidate % 2 === 0;
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Specification/SpecificationCombinatorsTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Create Specification interface**

Create `packages/nexus-ddd-core/src/Specification/Specification.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Specification;

/**
 * @psalm-api
 *
 * @template T
 *
 * Predicate over a candidate of type T, with combinators for composition.
 */
interface Specification
{
    /** @param T $candidate */
    public function isSatisfiedBy(mixed $candidate): bool;

    /** @param Specification<T> $other @return Specification<T> */
    public function and(Specification $other): Specification;

    /** @param Specification<T> $other @return Specification<T> */
    public function or(Specification $other): Specification;

    /** @return Specification<T> */
    public function not(): Specification;
}
```

- [ ] **Step 4: Create AbstractSpecification with default combinator impls**

Create `packages/nexus-ddd-core/src/Specification/AbstractSpecification.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Specification;

/**
 * @psalm-api
 *
 * @template T
 * @implements Specification<T>
 */
abstract class AbstractSpecification implements Specification
{
    public function and(Specification $other): Specification
    {
        return new AndSpecification($this, $other);
    }

    public function or(Specification $other): Specification
    {
        return new OrSpecification($this, $other);
    }

    public function not(): Specification
    {
        return new NotSpecification($this);
    }
}
```

- [ ] **Step 5: Create AndSpecification**

Create `packages/nexus-ddd-core/src/Specification/AndSpecification.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Specification;

/**
 * @psalm-api
 *
 * @template T
 * @extends AbstractSpecification<T>
 */
final class AndSpecification extends AbstractSpecification
{
    /**
     * @param Specification<T> $left
     * @param Specification<T> $right
     */
    public function __construct(
        private readonly Specification $left,
        private readonly Specification $right,
    ) {}

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $this->left->isSatisfiedBy($candidate) && $this->right->isSatisfiedBy($candidate);
    }
}
```

- [ ] **Step 6: Create OrSpecification**

Create `packages/nexus-ddd-core/src/Specification/OrSpecification.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Specification;

/**
 * @psalm-api
 *
 * @template T
 * @extends AbstractSpecification<T>
 */
final class OrSpecification extends AbstractSpecification
{
    /**
     * @param Specification<T> $left
     * @param Specification<T> $right
     */
    public function __construct(
        private readonly Specification $left,
        private readonly Specification $right,
    ) {}

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $this->left->isSatisfiedBy($candidate) || $this->right->isSatisfiedBy($candidate);
    }
}
```

- [ ] **Step 7: Create NotSpecification**

Create `packages/nexus-ddd-core/src/Specification/NotSpecification.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Specification;

/**
 * @psalm-api
 *
 * @template T
 * @extends AbstractSpecification<T>
 */
final class NotSpecification extends AbstractSpecification
{
    /** @param Specification<T> $inner */
    public function __construct(private readonly Specification $inner) {}

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return ! $this->inner->isSatisfiedBy($candidate);
    }
}
```

- [ ] **Step 8: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Specification/SpecificationCombinatorsTest.php -v`
Expected: PASS — 3 tests green.

- [ ] **Step 9: Commit**

```bash
git add packages/nexus-ddd-core/src/Specification packages/nexus-ddd-core/tests/Unit/Specification/SpecificationCombinatorsTest.php
git commit -m "feat(ddd-core): add Specification interface, AbstractSpecification, and combinators"
```

---

## Task 19: Failure value object + RichSpecification

**Files:**
- Create: `packages/nexus-ddd-core/src/Specification/Failure.php`
- Create: `packages/nexus-ddd-core/src/Specification/RichSpecification.php`
- Create: `packages/nexus-ddd-core/src/Specification/AbstractRichSpecification.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Specification/RichSpecificationTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Specification/RichSpecificationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Specification;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Core\Specification\AbstractRichSpecification;
use Monadial\Nexus\Ddd\Core\Specification\Failure;
use Monadial\Nexus\Ddd\Core\Specification\Specification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RichSpecificationTest extends TestCase
{
    #[Test]
    public function evaluateReturnsRightOnSuccess(): void
    {
        $spec = new IsPositiveRich();
        $result = $spec->evaluate(5);
        self::assertInstanceOf(Either::class, $result);
        self::assertTrue($result->isRight());
        self::assertSame(5, $result->get());
    }

    #[Test]
    public function evaluateReturnsLeftOnFailureWithReasons(): void
    {
        $spec = new IsPositiveRich();
        $result = $spec->evaluate(-1);
        self::assertTrue($result->isLeft());

        /** @var array<int, Failure> $failures */
        $failures = $result->get();
        self::assertNotEmpty($failures);
        self::assertSame('value', $failures[0]->field);
        self::assertSame('not_positive', $failures[0]->code);
    }

    #[Test]
    public function asSpecificationProjectsToBool(): void
    {
        $rich = new IsPositiveRich();
        $bool = $rich->asSpecification();
        self::assertInstanceOf(Specification::class, $bool);
        self::assertTrue($bool->isSatisfiedBy(5));
        self::assertFalse($bool->isSatisfiedBy(-1));
    }
}

/** @extends AbstractRichSpecification<int> */
final class IsPositiveRich extends AbstractRichSpecification
{
    public function evaluate(mixed $candidate): Either
    {
        if (is_int($candidate) && $candidate > 0) {
            return Either::right($candidate);
        }
        return Either::left([new Failure('value', 'not_positive', 'must be positive int')]);
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Specification/RichSpecificationTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Create Failure**

Create `packages/nexus-ddd-core/src/Specification/Failure.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Specification;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Represents a single failure reason for a RichSpecification evaluation.
 * `field` is a path (e.g., "address.zip"); `code` is a stable identifier ("required");
 * `message` is a human-readable description.
 */
final readonly class Failure
{
    public function __construct(
        public string $field,
        public string $code,
        public string $message,
    ) {}
}
```

- [ ] **Step 4: Create RichSpecification interface**

Create `packages/nexus-ddd-core/src/Specification/RichSpecification.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Specification;

use Fp\Functional\Either\Either;

/**
 * @psalm-api
 *
 * @template T
 *
 * Specification that returns reasons-for-failure rather than just bool. Used for
 * business rules where the caller needs to surface the WHY (validation errors,
 * UI form errors, API responses).
 */
interface RichSpecification
{
    /**
     * @param T $candidate
     * @return Either<array<int, Failure>, T> Left = failure reasons; Right = candidate
     */
    public function evaluate(mixed $candidate): Either;

    /** @return Specification<T> bool projection */
    public function asSpecification(): Specification;
}
```

- [ ] **Step 5: Create AbstractRichSpecification**

Create `packages/nexus-ddd-core/src/Specification/AbstractRichSpecification.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Specification;

use Fp\Functional\Either\Either;

/**
 * @psalm-api
 *
 * @template T
 * @implements RichSpecification<T>
 */
abstract class AbstractRichSpecification implements RichSpecification
{
    /**
     * @param T $candidate
     * @return Either<array<int, Failure>, T>
     */
    abstract public function evaluate(mixed $candidate): Either;

    public function asSpecification(): Specification
    {
        return new RichToBoolSpecification($this);
    }
}

/**
 * @internal
 * @template T
 * @extends AbstractSpecification<T>
 */
final class RichToBoolSpecification extends AbstractSpecification
{
    /** @param AbstractRichSpecification<T> $inner */
    public function __construct(private readonly AbstractRichSpecification $inner) {}

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $this->inner->evaluate($candidate)->isRight();
    }
}
```

- [ ] **Step 6: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Specification/RichSpecificationTest.php -v`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add packages/nexus-ddd-core/src/Specification/Failure.php packages/nexus-ddd-core/src/Specification/RichSpecification.php packages/nexus-ddd-core/src/Specification/AbstractRichSpecification.php packages/nexus-ddd-core/tests/Unit/Specification/RichSpecificationTest.php
git commit -m "feat(ddd-core): add RichSpecification with Either<Failures,T> + Failure VO"
```

---

## Task 20: AbstractPolicy

**Files:**
- Create: `packages/nexus-ddd-core/src/Policy/AbstractPolicy.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Policy/AbstractPolicyTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Policy/AbstractPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Policy;

use Monadial\Nexus\Ddd\Core\Policy\AbstractPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractPolicy::class)]
final class AbstractPolicyTest extends TestCase
{
    #[Test]
    public function policyComputesOutputFromInput(): void
    {
        $policy = new DoublingPolicy();
        self::assertSame(10, $policy->apply(5));
    }
}

/** @extends AbstractPolicy<int, int> */
final class DoublingPolicy extends AbstractPolicy
{
    /** @param int $input @return int */
    public function apply(mixed $input): mixed
    {
        return $input * 2;
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Policy/AbstractPolicyTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Implement AbstractPolicy**

Create `packages/nexus-ddd-core/src/Policy/AbstractPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Policy;

/**
 * @psalm-api
 *
 * @template TIn
 * @template TOut
 *
 * Domain rule that COMPUTES (pricing, discount, eligibility). Distinct from
 * Specification (predicate). Concrete subclasses MUST declare TIn / TOut for
 * downstream type inference.
 */
abstract class AbstractPolicy
{
    /**
     * @param TIn $input
     * @return TOut
     */
    abstract public function apply(mixed $input): mixed;
}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Policy/AbstractPolicyTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-ddd-core/src/Policy packages/nexus-ddd-core/tests/Unit/Policy
git commit -m "feat(ddd-core): add AbstractPolicy<TIn,TOut> abstract class"
```

---

## Task 21: BackoffStrategy Interface + NoRetry

**Files:**
- Create: `packages/nexus-ddd-core/src/Backoff/BackoffStrategy.php`
- Create: `packages/nexus-ddd-core/src/Backoff/NoRetry.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Backoff/NoRetryTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Backoff/NoRetryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Backoff;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Core\Backoff\BackoffStrategy;
use Monadial\Nexus\Ddd\Core\Backoff\NoRetry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(NoRetry::class)]
final class NoRetryTest extends TestCase
{
    #[Test]
    public function alwaysReturnsNone(): void
    {
        $strategy = NoRetry::instance();
        self::assertInstanceOf(BackoffStrategy::class, $strategy);

        $result = $strategy->delayFor(1, new RuntimeException('boom'));
        self::assertInstanceOf(Option::class, $result);
        self::assertTrue($result->isEmpty());
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Backoff/NoRetryTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Create BackoffStrategy interface**

Create `packages/nexus-ddd-core/src/Backoff/BackoffStrategy.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Fp\Functional\Option\Option;
use Monadial\Duration\Duration;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Throwable;

/**
 * @psalm-api
 *
 * Foundational primitive for retry timing.
 *
 * Used by OCC retry middleware, outbox relay, async transport retries, process
 * manager retries, and any application-level retry need.
 */
interface BackoffStrategy
{
    /**
     * @param int $attempt 1-based — first failure is attempt #1
     * @return Option<Duration> none = give up; some = wait this long before next try
     */
    public function delayFor(int $attempt, Throwable $cause): Option;
}
```

- [ ] **Step 4: Create NoRetry**

Create `packages/nexus-ddd-core/src/Backoff/NoRetry.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Fp\Functional\Option\Option;
use Throwable;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * No-retry strategy: every failure surfaces immediately, no backoff.
 */
final class NoRetry implements BackoffStrategy
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function delayFor(int $attempt, Throwable $cause): Option
    {
        return Option::none();
    }
}
```

- [ ] **Step 5: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Backoff/NoRetryTest.php -v`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-ddd-core/src/Backoff/BackoffStrategy.php packages/nexus-ddd-core/src/Backoff/NoRetry.php packages/nexus-ddd-core/tests/Unit/Backoff/NoRetryTest.php
git commit -m "feat(ddd-core): add BackoffStrategy interface and NoRetry strategy"
```

---

## Task 22: FixedDelayBackoff

**Files:**
- Create: `packages/nexus-ddd-core/src/Backoff/FixedDelayBackoff.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Backoff/FixedDelayBackoffTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Backoff/FixedDelayBackoffTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Backoff;

use Monadial\Nexus\Ddd\Core\Backoff\FixedDelayBackoff;
use Monadial\Duration\Duration;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FixedDelayBackoff::class)]
final class FixedDelayBackoffTest extends TestCase
{
    #[Test]
    public function delaysFixedAmountUntilMaxAttempts(): void
    {
        $strategy = FixedDelayBackoff::of(FiniteDuration::fromTimeUnit(1, TimeUnit::Seconds()), maxAttempts: 3);

        self::assertSame(1_000, $strategy->delayFor(1, new RuntimeException())->get()->toMillis());
        self::assertSame(1_000, $strategy->delayFor(2, new RuntimeException())->get()->toMillis());
        // Attempt 3 was the LAST allowed retry; after attempt 3 fails, give up.
        self::assertTrue($strategy->delayFor(4, new RuntimeException())->isEmpty());
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Backoff/FixedDelayBackoffTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Implement FixedDelayBackoff**

Create `packages/nexus-ddd-core/src/Backoff/FixedDelayBackoff.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Fp\Functional\Option\Option;
use Monadial\Duration\Duration;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Throwable;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class FixedDelayBackoff implements BackoffStrategy
{
    private function __construct(
        public Duration $delay,
        public int $maxAttempts,
    ) {}

    public static function of(Duration $delay, int $maxAttempts): self
    {
        return new self($delay, $maxAttempts);
    }

    public function delayFor(int $attempt, Throwable $cause): Option
    {
        if ($attempt > $this->maxAttempts) {
            return Option::none();
        }
        return Option::some($this->delay);
    }
}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Backoff/FixedDelayBackoffTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-ddd-core/src/Backoff/FixedDelayBackoff.php packages/nexus-ddd-core/tests/Unit/Backoff/FixedDelayBackoffTest.php
git commit -m "feat(ddd-core): add FixedDelayBackoff strategy"
```

---

## Task 23: LinearBackoff

**Files:**
- Create: `packages/nexus-ddd-core/src/Backoff/LinearBackoff.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Backoff/LinearBackoffTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Backoff/LinearBackoffTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Backoff;

use Monadial\Nexus\Ddd\Core\Backoff\LinearBackoff;
use Monadial\Duration\Duration;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(LinearBackoff::class)]
final class LinearBackoffTest extends TestCase
{
    #[Test]
    public function delayScalesLinearlyByAttempt(): void
    {
        $strategy = LinearBackoff::of(FiniteDuration::fromTimeUnit(100, TimeUnit::Milliseconds()), maxAttempts: 3);

        self::assertSame(100, $strategy->delayFor(1, new RuntimeException())->get()->toMillis());
        self::assertSame(200, $strategy->delayFor(2, new RuntimeException())->get()->toMillis());
        self::assertSame(300, $strategy->delayFor(3, new RuntimeException())->get()->toMillis());
        self::assertTrue($strategy->delayFor(4, new RuntimeException())->isEmpty());
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Backoff/LinearBackoffTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Implement LinearBackoff**

Create `packages/nexus-ddd-core/src/Backoff/LinearBackoff.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Fp\Functional\Option\Option;
use Monadial\Duration\Duration;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Throwable;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Delay = base × attempt (so attempt 1 waits `base`, attempt 2 waits `2 × base`, etc.).
 */
final readonly class LinearBackoff implements BackoffStrategy
{
    private function __construct(
        public Duration $base,
        public int $maxAttempts,
    ) {}

    public static function of(Duration $base, int $maxAttempts): self
    {
        return new self($base, $maxAttempts);
    }

    public function delayFor(int $attempt, Throwable $cause): Option
    {
        if ($attempt > $this->maxAttempts) {
            return Option::none();
        }
        return Option::some(FiniteDuration::fromTimeUnit($this->base->toMillis() * $attempt, TimeUnit::Milliseconds()));
    }
}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Backoff/LinearBackoffTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-ddd-core/src/Backoff/LinearBackoff.php packages/nexus-ddd-core/tests/Unit/Backoff/LinearBackoffTest.php
git commit -m "feat(ddd-core): add LinearBackoff strategy"
```

---

## Task 24: ExponentialBackoff

**Files:**
- Create: `packages/nexus-ddd-core/src/Backoff/ExponentialBackoff.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Backoff/ExponentialBackoffTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Backoff/ExponentialBackoffTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Backoff;

use Monadial\Nexus\Ddd\Core\Backoff\ExponentialBackoff;
use Monadial\Duration\Duration;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ExponentialBackoff::class)]
final class ExponentialBackoffTest extends TestCase
{
    #[Test]
    public function delayDoublesPerAttemptUntilCap(): void
    {
        $strategy = ExponentialBackoff::of(
            FiniteDuration::fromTimeUnit(100, TimeUnit::Milliseconds()),
            cap: FiniteDuration::fromTimeUnit(500, TimeUnit::Milliseconds()),
            maxAttempts: 5,
        );

        self::assertSame(100, $strategy->delayFor(1, new RuntimeException())->get()->toMillis());
        self::assertSame(200, $strategy->delayFor(2, new RuntimeException())->get()->toMillis());
        self::assertSame(400, $strategy->delayFor(3, new RuntimeException())->get()->toMillis());
        // 800 would exceed cap — clamp to 500
        self::assertSame(500, $strategy->delayFor(4, new RuntimeException())->get()->toMillis());
        self::assertSame(500, $strategy->delayFor(5, new RuntimeException())->get()->toMillis());
        self::assertTrue($strategy->delayFor(6, new RuntimeException())->isEmpty());
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Backoff/ExponentialBackoffTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Implement ExponentialBackoff**

Create `packages/nexus-ddd-core/src/Backoff/ExponentialBackoff.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Fp\Functional\Option\Option;
use Monadial\Duration\Duration;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Throwable;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Delay = min(cap, base × multiplier^(attempt-1)). Default multiplier = 2.0.
 */
final readonly class ExponentialBackoff implements BackoffStrategy
{
    private function __construct(
        public Duration $base,
        public Duration $cap,
        public int $maxAttempts,
        public float $multiplier,
    ) {}

    public static function of(
        Duration $base,
        Duration $cap,
        int $maxAttempts,
        float $multiplier = 2.0,
    ): self {
        return new self($base, $cap, $maxAttempts, $multiplier);
    }

    public function delayFor(int $attempt, Throwable $cause): Option
    {
        if ($attempt > $this->maxAttempts) {
            return Option::none();
        }
        $millis = (int) round($this->base->toMillis() * ($this->multiplier ** ($attempt - 1)));
        $clamped = min($millis, $this->cap->toMillis());
        return Option::some(FiniteDuration::fromTimeUnit($clamped, TimeUnit::Milliseconds()));
    }
}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Backoff/ExponentialBackoffTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-ddd-core/src/Backoff/ExponentialBackoff.php packages/nexus-ddd-core/tests/Unit/Backoff/ExponentialBackoffTest.php
git commit -m "feat(ddd-core): add ExponentialBackoff strategy"
```

---

## Task 25: JitteredExponentialBackoff

**Files:**
- Create: `packages/nexus-ddd-core/src/Backoff/JitteredExponentialBackoff.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Backoff/JitteredExponentialBackoffTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Backoff/JitteredExponentialBackoffTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Backoff;

use Monadial\Nexus\Ddd\Core\Backoff\JitteredExponentialBackoff;
use Monadial\Duration\Duration;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(JitteredExponentialBackoff::class)]
final class JitteredExponentialBackoffTest extends TestCase
{
    #[Test]
    public function jitteredDelayIsWithinExpectedRange(): void
    {
        $strategy = JitteredExponentialBackoff::of(
            FiniteDuration::fromTimeUnit(100, TimeUnit::Milliseconds()),
            cap: FiniteDuration::fromTimeUnit(10_000, TimeUnit::Milliseconds()),
            maxAttempts: 5,
        );

        // Attempt 1: base=100, jitter in [0, 100], so delay in [100, 200]
        // Repeat to cover jitter randomness:
        for ($i = 0; $i < 50; $i++) {
            $delay = $strategy->delayFor(1, new RuntimeException())->get();
            self::assertGreaterThanOrEqual(100, $delay->toMillis());
            self::assertLessThanOrEqual(200, $delay->toMillis());
        }
    }

    #[Test]
    public function noneAfterMaxAttempts(): void
    {
        $strategy = JitteredExponentialBackoff::of(FiniteDuration::fromTimeUnit(10, TimeUnit::Milliseconds()), FiniteDuration::fromTimeUnit(100, TimeUnit::Milliseconds()), 2);
        self::assertTrue($strategy->delayFor(3, new RuntimeException())->isEmpty());
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Backoff/JitteredExponentialBackoffTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Implement JitteredExponentialBackoff**

Create `packages/nexus-ddd-core/src/Backoff/JitteredExponentialBackoff.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Fp\Functional\Option\Option;
use Monadial\Duration\Duration;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Throwable;

/**
 * @psalm-api
 *
 * Exponential backoff with uniform jitter in [0, base) added per attempt.
 * Recommended default for high-fan-in retry paths to avoid thundering-herd.
 *
 * Note: this is NOT @psalm-immutable because delayFor() uses random jitter.
 */
final readonly class JitteredExponentialBackoff implements BackoffStrategy
{
    private function __construct(
        public Duration $base,
        public Duration $cap,
        public int $maxAttempts,
    ) {}

    public static function of(Duration $base, Duration $cap, int $maxAttempts): self
    {
        return new self($base, $cap, $maxAttempts);
    }

    public function delayFor(int $attempt, Throwable $cause): Option
    {
        if ($attempt > $this->maxAttempts) {
            return Option::none();
        }
        $exponential = (int) round($this->base->toMillis() * (2 ** ($attempt - 1)));
        $clamped = min($exponential, $this->cap->toMillis());
        $jitter = random_int(0, $this->base->toMillis());
        return Option::some(FiniteDuration::fromTimeUnit($clamped + $jitter, TimeUnit::Milliseconds()));
    }
}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Backoff/JitteredExponentialBackoffTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-ddd-core/src/Backoff/JitteredExponentialBackoff.php packages/nexus-ddd-core/tests/Unit/Backoff/JitteredExponentialBackoffTest.php
git commit -m "feat(ddd-core): add JitteredExponentialBackoff strategy"
```

---

## Task 26: CustomBackoff

**Files:**
- Create: `packages/nexus-ddd-core/src/Backoff/CustomBackoff.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Backoff/CustomBackoffTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Backoff/CustomBackoffTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Backoff;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Core\Backoff\CustomBackoff;
use Monadial\Duration\Duration;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CustomBackoff::class)]
final class CustomBackoffTest extends TestCase
{
    #[Test]
    public function delegatesToProvidedCallable(): void
    {
        $strategy = CustomBackoff::of(
            static fn(int $attempt, \Throwable $cause): Option => $attempt < 3
                ? Option::some(FiniteDuration::fromTimeUnit(50 * $attempt, TimeUnit::Milliseconds()))
                : Option::none(),
        );

        self::assertSame(50, $strategy->delayFor(1, new RuntimeException())->get()->toMillis());
        self::assertSame(100, $strategy->delayFor(2, new RuntimeException())->get()->toMillis());
        self::assertTrue($strategy->delayFor(3, new RuntimeException())->isEmpty());
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Backoff/CustomBackoffTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Implement CustomBackoff**

Create `packages/nexus-ddd-core/src/Backoff/CustomBackoff.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Fp\Functional\Option\Option;
use Throwable;

/**
 * @psalm-api
 *
 * User-supplied backoff strategy via callable. Full control.
 */
final class CustomBackoff implements BackoffStrategy
{
    /** @var callable(int, Throwable): Option */
    private $fn;

    /** @param callable(int, Throwable): Option $fn */
    private function __construct(callable $fn)
    {
        $this->fn = $fn;
    }

    /** @param callable(int, Throwable): Option $fn */
    public static function of(callable $fn): self
    {
        return new self($fn);
    }

    public function delayFor(int $attempt, Throwable $cause): Option
    {
        return ($this->fn)($attempt, $cause);
    }
}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Backoff/CustomBackoffTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-ddd-core/src/Backoff/CustomBackoff.php packages/nexus-ddd-core/tests/Unit/Backoff/CustomBackoffTest.php
git commit -m "feat(ddd-core): add CustomBackoff strategy (user-supplied callable)"
```

---

## Task 27: RetryPolicy + RetryPolicyBuilder

**Files:**
- Create: `packages/nexus-ddd-core/src/Backoff/RetryPolicy.php`
- Create: `packages/nexus-ddd-core/src/Backoff/RetryPolicyBuilder.php`
- Create: `packages/nexus-ddd-core/tests/Unit/Backoff/RetryPolicyTest.php`

- [ ] **Step 1: Write failing test**

Create `packages/nexus-ddd-core/tests/Unit/Backoff/RetryPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Backoff;

use Monadial\Nexus\Ddd\Core\Backoff\FixedDelayBackoff;
use Monadial\Nexus\Ddd\Core\Backoff\RetryPolicy;
use Monadial\Nexus\Ddd\Core\Backoff\RetryPolicyBuilder;
use Monadial\Duration\Duration;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RetryPolicy::class)]
#[CoversClass(RetryPolicyBuilder::class)]
final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function policyDispatchesPerExceptionType(): void
    {
        $policy = RetryPolicyBuilder::create()
            ->onException(SomeTransient::class, FixedDelayBackoff::of(FiniteDuration::fromTimeUnit(50, TimeUnit::Milliseconds()), 3))
            ->giveUpOn(SomeFatal::class)
            ->build();

        $delay = $policy->delayFor(1, new SomeTransient());
        self::assertFalse($delay->isEmpty());
        self::assertSame(50, $delay->get()->toMillis());

        $giveUp = $policy->delayFor(1, new SomeFatal());
        self::assertTrue($giveUp->isEmpty());
    }

    #[Test]
    public function unmappedExceptionsGiveUpByDefault(): void
    {
        $policy = RetryPolicyBuilder::create()->build();
        self::assertTrue($policy->delayFor(1, new RuntimeException())->isEmpty());
    }
}

final class SomeTransient extends RuntimeException {}
final class SomeFatal extends RuntimeException {}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Backoff/RetryPolicyTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Create RetryPolicy**

Create `packages/nexus-ddd-core/src/Backoff/RetryPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Fp\Functional\Option\Option;
use Throwable;

/**
 * @psalm-api
 *
 * Per-exception mapping of throwable type → BackoffStrategy.
 * Built via RetryPolicyBuilder. Implements BackoffStrategy itself so it can be
 * passed anywhere a strategy is expected.
 */
final readonly class RetryPolicy implements BackoffStrategy
{
    /**
     * @param array<class-string<Throwable>, BackoffStrategy> $handlers
     * @param array<class-string<Throwable>, true> $giveUpSet
     */
    public function __construct(
        public array $handlers,
        public array $giveUpSet,
    ) {}

    public function delayFor(int $attempt, Throwable $cause): Option
    {
        foreach ($this->giveUpSet as $cls => $_) {
            if ($cause instanceof $cls) {
                return Option::none();
            }
        }
        foreach ($this->handlers as $cls => $strategy) {
            if ($cause instanceof $cls) {
                return $strategy->delayFor($attempt, $cause);
            }
        }
        return Option::none();
    }
}
```

- [ ] **Step 4: Create RetryPolicyBuilder**

Create `packages/nexus-ddd-core/src/Backoff/RetryPolicyBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Throwable;

/** @psalm-api */
final class RetryPolicyBuilder
{
    /** @var array<class-string<Throwable>, BackoffStrategy> */
    private array $handlers = [];

    /** @var array<class-string<Throwable>, true> */
    private array $giveUpSet = [];

    public static function create(): self
    {
        return new self();
    }

    /** @param class-string<Throwable> $exceptionClass */
    public function onException(string $exceptionClass, BackoffStrategy $strategy): self
    {
        $this->handlers[$exceptionClass] = $strategy;
        return $this;
    }

    /** @param class-string<Throwable> $exceptionClass */
    public function giveUpOn(string $exceptionClass): self
    {
        $this->giveUpSet[$exceptionClass] = true;
        return $this;
    }

    public function build(): RetryPolicy
    {
        return new RetryPolicy($this->handlers, $this->giveUpSet);
    }
}
```

- [ ] **Step 5: Run test, verify it passes**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/Backoff/RetryPolicyTest.php -v`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-ddd-core/src/Backoff/RetryPolicy.php packages/nexus-ddd-core/src/Backoff/RetryPolicyBuilder.php packages/nexus-ddd-core/tests/Unit/Backoff/RetryPolicyTest.php
git commit -m "feat(ddd-core): add RetryPolicy and RetryPolicyBuilder"
```

---

## Task 28: Final Smoke Test (full package integration)

**Files:**
- Create: `packages/nexus-ddd-core/tests/Unit/SmokeTest.php`

- [ ] **Step 1: Write the smoke test that exercises the full package**

Create `packages/nexus-ddd-core/tests/Unit/SmokeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Backoff\ExponentialBackoff;
use Monadial\Nexus\Ddd\Core\Backoff\RetryPolicyBuilder;
use Monadial\Nexus\Ddd\Core\Identity\IdGenerator;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Identity\UlidGenerator;
use Monadial\Nexus\Ddd\Core\Policy\AbstractPolicy;
use Monadial\Nexus\Ddd\Core\Specification\AbstractRichSpecification;
use Monadial\Nexus\Ddd\Core\Specification\Failure;
use Monadial\Duration\Duration;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Monadial\Nexus\Ddd\Core\Value\StringValue;
use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SmokeTest extends TestCase
{
    #[Test]
    public function fullPackageIntegrationSmoke(): void
    {
        // Identity
        $gen = new UlidGenerator();
        self::assertInstanceOf(IdGenerator::class, $gen);
        $id = $gen->next();
        self::assertInstanceOf(UlidValue::class, $id);

        // Value object — wrapped, mappable
        $email = new SmokeEmail('alice@example.com');
        $upper = $email->map(strtoupper(...));
        self::assertSame('ALICE@EXAMPLE.COM', $upper->value());

        // Aggregate — record-and-apply
        $order = SmokeOrder::create($id);
        $order->place();
        self::assertSame('placed', $order->status);
        self::assertCount(1, $order->pullRecordedEvents());

        // Specification — bool + rich
        $rich = new SmokeNonEmpty();
        $result = $rich->evaluate('hello');
        self::assertTrue($result->isRight());

        // Policy — compute
        $policy = new SmokeDoublePolicy();
        self::assertSame(8, $policy->apply(4));

        // Backoff — RetryPolicy
        $retry = RetryPolicyBuilder::create()
            ->onException(RuntimeException::class, ExponentialBackoff::of(
                FiniteDuration::fromTimeUnit(10, TimeUnit::Milliseconds()),
                FiniteDuration::fromTimeUnit(100, TimeUnit::Milliseconds()),
                3,
            ))
            ->build();
        $delay = $retry->delayFor(1, new RuntimeException('boom'));
        self::assertFalse($delay->isEmpty());
    }
}

final class SmokeEmail extends StringValue {}

final class SmokeOrder extends EventSourcedAggregateRoot
{
    public string $status = 'new';

    public static function create(Identifier $id): self
    {
        return new self($id);
    }

    public function id(): Identifier
    {
        return $this->id;
    }

    public function place(): void
    {
        $this->recordThat(new SmokePlaced());
    }

    private function applySmokePlaced(SmokePlaced $e): void
    {
        $this->status = 'placed';
    }
}

final class SmokePlaced {}

/** @extends AbstractRichSpecification<string> */
final class SmokeNonEmpty extends AbstractRichSpecification
{
    public function evaluate(mixed $candidate): Either
    {
        if (is_string($candidate) && $candidate !== '') {
            return Either::right($candidate);
        }
        return Either::left([new Failure('value', 'empty', 'must be non-empty string')]);
    }
}

/** @extends AbstractPolicy<int, int> */
final class SmokeDoublePolicy extends AbstractPolicy
{
    public function apply(mixed $input): mixed
    {
        return (int) $input * 2;
    }
}
```

- [ ] **Step 2: Run smoke test**

Run: `docker compose exec php vendor/bin/phpunit packages/nexus-ddd-core/tests/Unit/SmokeTest.php -v`
Expected: PASS.

- [ ] **Step 3: Run the FULL nexus-ddd-core test suite to verify nothing regressed**

Run: `docker compose exec php vendor/bin/phpunit --testsuite=unit --filter='Monadial\\\\Nexus\\\\Ddd\\\\Core' -v`
Expected: ALL tests pass (28 tasks × ~3 tests each = ~80+ tests).

- [ ] **Step 4: Run Psalm to verify type correctness**

Run: `docker compose exec php vendor/bin/psalm --no-cache packages/nexus-ddd-core/src`
Expected: 0 errors. (If errors exist, fix them — most likely missing `@psalm-suppress` annotations or generic type hints.)

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-ddd-core/tests/Unit/SmokeTest.php
git commit -m "test(ddd-core): add full-package integration smoke test"
```

---

## Self-Review

After all tasks complete, verify:

**Spec coverage** (umbrella spec §6):
- §6.1 Identity (Identifier, CompositeIdentifier, Identifiable, IdGenerator, UlidGenerator, UuidGenerator) → Tasks 4, 5, 6, 7
- §6.2 Value Objects (WrappedValue, ObjectValue + 7 concretes) → Tasks 8, 9, 10, 11
- §6.3 Entity, EventSourceable hierarchy → Task 12
- §6.4 AggregateRoot (with dispatchApply, replay, snapshot constructor) → Tasks 13, 14, 15
- §6.4.0 Replay failure recovery → covered by exception type from Task 2 + ApplyDispatcher from Task 14 (full middleware in `nexus-ddd-bus`)
- §6.4.1 dispatchApply convention → Task 14
- §6.4.2 Sub-entities → enabled by `Entity` interface from Task 12; concrete sub-entity tests belong in app code
- §6.5 Specification + RichSpecification → Tasks 18, 19
- §6.6 Policy → Task 20
- §6.7 Domain Service → no code (per spec); documented in §6.7
- §6.8 BackoffStrategy family → Tasks 21–27

**Out of scope (correctly deferred):**
- `MessageContext`, `Envelope`, `MessageMetadata` → `nexus-ddd-messaging` (next package)
- `CommandBus`, `QueryBus`, `EventBus` → `nexus-ddd-bus`
- `AggregateRepository`, `PersistenceStrategy`, `OccEventStore` → `nexus-ddd-aggregate` (P1)
- Psalm rules → `nexus-ddd-psalm` (separate package)
- Test fixtures (AggregateTestFixture, etc.) → `nexus-ddd-testkit-core` / `nexus-ddd-testkit-aggregate`

**Type consistency check:** every method signature matches what's declared in earlier tasks. `applyXxx` convention used identically across all aggregate examples. `Identifier::fromString(): static` used everywhere. `BackoffStrategy::delayFor()` returns `Option<Duration>` consistently.

If any task is missing or any type mismatch found, fix inline before invoking execution.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-06-nexus-ddd-core.md`. Two execution options:

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
