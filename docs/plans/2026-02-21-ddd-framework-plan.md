# nexus-ddd Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a `nexus-ddd` package providing Ecotone-style DDD tactical patterns (Aggregates, Commands, Queries, Events, Process Managers, Projections, Value Objects, Interceptors, Outbox) as thin façade over Nexus actor primitives.

**Architecture:** Domain objects are plain PHP classes with attributes — zero infrastructure awareness. The framework wires persistence, transport, and lifecycle externally via `NexusDdd::configure()`. Each DDD concept maps to a Nexus actor primitive internally (AggregateRoot → EventSourcedBehavior, CommandBus → router actor, etc.).

**Tech Stack:** PHP 8.5+, Nexus Core, Nexus Persistence, PHPUnit 13, Psalm Level 1

**Design doc:** `docs/plans/2026-02-21-ddd-framework-design.md`

---

## Task 1: Package Scaffolding

**Files:**
- Create: `packages/nexus-ddd/composer.json`
- Modify: `composer.json` (root — add autoload entries)
- Modify: `phpunit.xml` (add test suite + source directory)
- Modify: `psalm.xml` (add project directory)
- Modify: `deptrac.yaml` (add DDD layer + ruleset)

**Step 1: Create package composer.json**

```json
{
    "name": "nexus-actors/ddd",
    "description": "Nexus DDD — tactical domain-driven design patterns for actors.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "nexus-actors/core": "^1.0",
        "nexus-actors/persistence": "^1.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\DDD\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\DDD\\Tests\\": "tests/"
        }
    }
}
```

**Step 2: Add autoload entries to root composer.json**

In `composer.json` `autoload.psr-4`, add (alphabetical order):
```
"Monadial\\Nexus\\DDD\\": "packages/nexus-ddd/src/",
```

In `composer.json` `autoload-dev.psr-4`, add:
```
"Monadial\\Nexus\\DDD\\Tests\\": "packages/nexus-ddd/tests/",
```

**Step 3: Add to phpunit.xml**

In `<testsuite name="unit">`, add:
```xml
<directory>packages/nexus-ddd/tests/Unit</directory>
```

Add a dedicated suite:
```xml
<testsuite name="unit-ddd">
    <directory>packages/nexus-ddd/tests/Unit</directory>
</testsuite>
```

In `<source><include>`, add:
```xml
<directory>packages/nexus-ddd/src</directory>
```

**Step 4: Add to psalm.xml**

In `<projectFiles>`, add before `<ignoreFiles>`:
```xml
<directory name="packages/nexus-ddd/src" />
```

**Step 5: Add to deptrac.yaml**

In `layers`, add:
```yaml
- name: DDD
  collectors:
    - type: directory
      value: packages/nexus-ddd/src/.*
```

In `ruleset`, add:
```yaml
DDD:
  - Core
  - Persistence
```

**Step 6: Create directory structure**

```
packages/nexus-ddd/
├── src/
│   ├── Aggregate/
│   │   └── Attribute/
│   ├── Command/
│   │   └── Attribute/
│   ├── Query/
│   │   └── Attribute/
│   ├── Event/
│   │   └── Attribute/
│   ├── ProcessManager/
│   │   └── Attribute/
│   ├── Projection/
│   │   └── Attribute/
│   ├── ValueObject/
│   ├── Interceptor/
│   │   └── Attribute/
│   ├── Outbox/
│   ├── Configuration/
│   └── Exception/
└── tests/
    └── Unit/
        ├── Aggregate/
        ├── Command/
        ├── Query/
        ├── Event/
        ├── ProcessManager/
        ├── Projection/
        ├── ValueObject/
        ├── Interceptor/
        ├── Outbox/
        └── Configuration/
```

**Step 7: Run composer dump-autoload**

```bash
docker compose exec php composer dump-autoload
```

**Step 8: Commit**

```bash
git add -f packages/nexus-ddd/ composer.json phpunit.xml psalm.xml deptrac.yaml
git commit -m "build(ddd): scaffold nexus-ddd package with monorepo config"
```

---

## Task 2: Value Objects — SingleValueObject and ValueObject

These have zero dependencies on Nexus, so they go first. All other DDD types use them.

**Files:**
- Create: `packages/nexus-ddd/src/ValueObject/ValueObject.php`
- Create: `packages/nexus-ddd/src/ValueObject/SingleValueObject.php`
- Test: `packages/nexus-ddd/tests/Unit/ValueObject/ValueObjectTest.php`
- Test: `packages/nexus-ddd/tests/Unit/ValueObject/SingleValueObjectTest.php`

**Step 1: Write failing tests for ValueObject**

`packages/nexus-ddd/tests/Unit/ValueObject/ValueObjectTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Tests\Unit\ValueObject;

use Monadial\Nexus\DDD\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValueObject::class)]
final class ValueObjectTest extends TestCase
{
    #[Test]
    public function equalsReturnsTrueForIdenticalValues(): void
    {
        $a = new TestMoney(100, 'EUR');
        $b = new TestMoney(100, 'EUR');

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentValues(): void
    {
        $a = new TestMoney(100, 'EUR');
        $b = new TestMoney(200, 'EUR');

        self::assertFalse($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentTypes(): void
    {
        $a = new TestMoney(100, 'EUR');
        $b = new TestAddress('Main St');

        self::assertFalse($a->equals($b));
    }

    #[Test]
    public function equalsWorksWithNestedValueObjects(): void
    {
        $a = new TestPrice(new TestMoney(100, 'EUR'), 'net');
        $b = new TestPrice(new TestMoney(100, 'EUR'), 'net');
        $c = new TestPrice(new TestMoney(200, 'EUR'), 'net');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    #[Test]
    public function toStringReturnsClassName(): void
    {
        $vo = new TestMoney(100, 'EUR');

        self::assertIsString($vo->toString());
    }
}

/** @psalm-suppress UnusedClass */
final readonly class TestMoney extends ValueObject
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {}
}

/** @psalm-suppress UnusedClass */
final readonly class TestAddress extends ValueObject
{
    public function __construct(
        public string $street,
    ) {}
}

/** @psalm-suppress UnusedClass */
final readonly class TestPrice extends ValueObject
{
    public function __construct(
        public TestMoney $money,
        public string $type,
    ) {}
}
```

**Step 2: Run test to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-ddd/tests/Unit/ValueObject/ValueObjectTest.php -v
```

Expected: FAIL — class not found.

**Step 3: Implement ValueObject**

`packages/nexus-ddd/src/ValueObject/ValueObject.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\ValueObject;

use ReflectionClass;

/** @psalm-api */
abstract readonly class ValueObject
{
    public function equals(self $other): bool
    {
        if (static::class !== $other::class) {
            return false;
        }

        $reflection = new ReflectionClass(static::class);

        foreach ($reflection->getProperties() as $property) {
            $a = $property->getValue($this);
            $b = $property->getValue($other);

            if ($a instanceof self && $b instanceof self) {
                if (!$a->equals($b)) {
                    return false;
                }

                continue;
            }

            if ($a !== $b) {
                return false;
            }
        }

        return true;
    }

    public function toString(): string
    {
        return static::class;
    }
}
```

**Step 4: Run test to verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-ddd/tests/Unit/ValueObject/ValueObjectTest.php -v
```

Expected: PASS

**Step 5: Write failing tests for SingleValueObject**

`packages/nexus-ddd/tests/Unit/ValueObject/SingleValueObjectTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Tests\Unit\ValueObject;

use InvalidArgumentException;
use Monadial\Nexus\DDD\ValueObject\SingleValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SingleValueObject::class)]
final class SingleValueObjectTest extends TestCase
{
    #[Test]
    public function holdsScalarValue(): void
    {
        $email = new TestEmail('user@example.com');

        self::assertSame('user@example.com', $email->value);
    }

    #[Test]
    public function equalsComparesStructurally(): void
    {
        $a = new TestEmail('user@example.com');
        $b = new TestEmail('user@example.com');
        $c = new TestEmail('other@example.com');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    #[Test]
    public function validationRuns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TestEmail('not-an-email');
    }

    #[Test]
    public function toStringReturnsValue(): void
    {
        $email = new TestEmail('user@example.com');

        self::assertSame('user@example.com', $email->toString());
    }

    #[Test]
    public function worksWithIntValue(): void
    {
        $age = new TestAge(25);

        self::assertSame(25, $age->value);
    }
}

/** @psalm-suppress UnusedClass */
final readonly class TestEmail extends SingleValueObject
{
    protected function validate(string|int|float|bool $value): void
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email: {$value}");
        }
    }
}

/** @psalm-suppress UnusedClass */
final readonly class TestAge extends SingleValueObject {}
```

**Step 6: Implement SingleValueObject**

`packages/nexus-ddd/src/ValueObject/SingleValueObject.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\ValueObject;

use Override;

/** @psalm-api */
abstract readonly class SingleValueObject extends ValueObject
{
    public function __construct(
        public string|int|float|bool $value,
    ) {
        $this->validate($value);
    }

    /** Override to add validation. Throw on invalid. */
    protected function validate(string|int|float|bool $value): void {}

    #[Override]
    public function toString(): string
    {
        return (string) $this->value;
    }
}
```

**Step 7: Run both tests**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-ddd/tests/Unit/ValueObject/ -v
```

Expected: PASS

**Step 8: Commit**

```bash
git add -f packages/nexus-ddd/src/ValueObject/ packages/nexus-ddd/tests/Unit/ValueObject/
git commit -m "feat(ddd): add ValueObject and SingleValueObject with structural equality"
```

---

## Task 3: Core Identity Types — AggregateId and ProcessId

**Files:**
- Create: `packages/nexus-ddd/src/Aggregate/AggregateId.php`
- Create: `packages/nexus-ddd/src/ProcessManager/ProcessId.php`
- Test: `packages/nexus-ddd/tests/Unit/Aggregate/AggregateIdTest.php`
- Test: `packages/nexus-ddd/tests/Unit/ProcessManager/ProcessIdTest.php`

**Step 1: Write failing tests for AggregateId**

`packages/nexus-ddd/tests/Unit/Aggregate/AggregateIdTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Tests\Unit\Aggregate;

use InvalidArgumentException;
use Monadial\Nexus\DDD\Aggregate\AggregateId;
use Monadial\Nexus\Persistence\PersistenceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AggregateId::class)]
final class AggregateIdTest extends TestCase
{
    #[Test]
    public function createsFromTypeAndId(): void
    {
        $id = AggregateId::of('ShoppingCart', 'cart-123');

        self::assertSame('ShoppingCart', $id->type);
        self::assertSame('cart-123', $id->id);
    }

    #[Test]
    public function equalsComparesStructurally(): void
    {
        $a = AggregateId::of('ShoppingCart', 'cart-123');
        $b = AggregateId::of('ShoppingCart', 'cart-123');
        $c = AggregateId::of('ShoppingCart', 'cart-456');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    #[Test]
    public function convertsToPersistenceId(): void
    {
        $aggregateId = AggregateId::of('ShoppingCart', 'cart-123');
        $persistenceId = $aggregateId->toPersistenceId();

        self::assertInstanceOf(PersistenceId::class, $persistenceId);
        self::assertSame('ShoppingCart', $persistenceId->entityType);
        self::assertSame('cart-123', $persistenceId->entityId);
    }

    #[Test]
    public function toStringReturnsTypeAndId(): void
    {
        $id = AggregateId::of('ShoppingCart', 'cart-123');

        self::assertSame('ShoppingCart|cart-123', $id->toString());
        self::assertSame('ShoppingCart|cart-123', (string) $id);
    }

    #[Test]
    public function rejectsEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AggregateId::of('', 'cart-123');
    }

    #[Test]
    public function rejectsEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AggregateId::of('ShoppingCart', '');
    }

    #[Test]
    public function createsFromPersistenceId(): void
    {
        $persistenceId = PersistenceId::of('ShoppingCart', 'cart-123');
        $aggregateId = AggregateId::fromPersistenceId($persistenceId);

        self::assertSame('ShoppingCart', $aggregateId->type);
        self::assertSame('cart-123', $aggregateId->id);
    }
}
```

**Step 2: Run test to verify failure**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-ddd/tests/Unit/Aggregate/AggregateIdTest.php -v
```

**Step 3: Implement AggregateId**

`packages/nexus-ddd/src/Aggregate/AggregateId.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Aggregate;

use InvalidArgumentException;
use Monadial\Nexus\Persistence\PersistenceId;
use Override;
use Stringable;

/** @psalm-api */
final readonly class AggregateId implements Stringable
{
    private function __construct(
        public string $type,
        public string $id,
    ) {}

    public static function of(string $type, string $id): self
    {
        if ($type === '') {
            throw new InvalidArgumentException('Aggregate type must not be empty');
        }

        if ($id === '') {
            throw new InvalidArgumentException('Aggregate ID must not be empty');
        }

        return new self($type, $id);
    }

    public static function fromPersistenceId(PersistenceId $persistenceId): self
    {
        return new self($persistenceId->entityType, $persistenceId->entityId);
    }

    public function toPersistenceId(): PersistenceId
    {
        return PersistenceId::of($this->type, $this->id);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    public function toString(): string
    {
        return "{$this->type}|{$this->id}";
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
```

**Step 4: Run test to verify pass**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-ddd/tests/Unit/Aggregate/AggregateIdTest.php -v
```

**Step 5: Write failing tests for ProcessId**

`packages/nexus-ddd/tests/Unit/ProcessManager/ProcessIdTest.php` — same pattern as AggregateId but with `ProcessId::of(string $type, string $id)`.

**Step 6: Implement ProcessId**

`packages/nexus-ddd/src/ProcessManager/ProcessId.php` — same structure as AggregateId, with `toPersistenceId()`.

**Step 7: Run both tests**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-ddd/tests/Unit/Aggregate/ packages/nexus-ddd/tests/Unit/ProcessManager/ -v
```

**Step 8: Commit**

```bash
git add -f packages/nexus-ddd/src/Aggregate/AggregateId.php packages/nexus-ddd/src/ProcessManager/ProcessId.php packages/nexus-ddd/tests/Unit/Aggregate/ packages/nexus-ddd/tests/Unit/ProcessManager/
git commit -m "feat(ddd): add AggregateId and ProcessId identity value objects"
```

---

## Task 4: Marker Interfaces — DomainEvent, Command, Query

**Files:**
- Create: `packages/nexus-ddd/src/Event/DomainEvent.php`
- Create: `packages/nexus-ddd/src/Command/Command.php`
- Create: `packages/nexus-ddd/src/Query/Query.php`
- Test: `packages/nexus-ddd/tests/Unit/Command/CommandTest.php`

**Step 1: Create DomainEvent marker interface**

`packages/nexus-ddd/src/Event/DomainEvent.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Event;

/** @psalm-api */
interface DomainEvent {}
```

**Step 2: Create Command interface with targetAggregateId()**

`packages/nexus-ddd/src/Command/Command.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Command;

use Monadial\Nexus\DDD\Aggregate\AggregateId;

/** @psalm-api */
interface Command
{
    public function targetAggregateId(): AggregateId;
}
```

**Step 3: Create Query interface**

`packages/nexus-ddd/src/Query/Query.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Query;

/**
 * @template R
 * @psalm-api
 */
interface Query {}
```

**Step 4: Write test verifying Command contract**

`packages/nexus-ddd/tests/Unit/Command/CommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Tests\Unit\Command;

use Monadial\Nexus\DDD\Aggregate\AggregateId;
use Monadial\Nexus\DDD\Command\Command;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CommandTest extends TestCase
{
    #[Test]
    public function commandDeclaresTargetAggregateId(): void
    {
        $command = new TestAddItem(
            AggregateId::of('ShoppingCart', 'cart-1'),
            'book',
        );

        self::assertSame('ShoppingCart', $command->targetAggregateId()->type);
        self::assertSame('cart-1', $command->targetAggregateId()->id);
    }
}

/** @psalm-suppress UnusedClass */
readonly class TestAddItem implements Command
{
    public function __construct(
        public AggregateId $cartId,
        public string $item,
    ) {}

    public function targetAggregateId(): AggregateId
    {
        return $this->cartId;
    }
}
```

**Step 5: Run tests**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-ddd/tests/Unit/Command/CommandTest.php -v
```

**Step 6: Commit**

```bash
git add -f packages/nexus-ddd/src/Event/DomainEvent.php packages/nexus-ddd/src/Command/Command.php packages/nexus-ddd/src/Query/Query.php packages/nexus-ddd/tests/Unit/Command/
git commit -m "feat(ddd): add DomainEvent, Command, and Query interfaces"
```

---

## Task 5: Attributes — Aggregate, Identifier, CommandHandler, ApplyEvent

**Files:**
- Create: `packages/nexus-ddd/src/Aggregate/Attribute/Aggregate.php`
- Create: `packages/nexus-ddd/src/Aggregate/Attribute/Identifier.php`
- Create: `packages/nexus-ddd/src/Aggregate/Attribute/CommandHandler.php`
- Create: `packages/nexus-ddd/src/Aggregate/Attribute/ApplyEvent.php`
- Test: `packages/nexus-ddd/tests/Unit/Aggregate/Attribute/AttributeTest.php`

**Step 1: Write failing test**

`packages/nexus-ddd/tests/Unit/Aggregate/Attribute/AttributeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Tests\Unit\Aggregate\Attribute;

use Monadial\Nexus\DDD\Aggregate\Attribute\Aggregate;
use Monadial\Nexus\DDD\Aggregate\Attribute\ApplyEvent;
use Monadial\Nexus\DDD\Aggregate\Attribute\CommandHandler;
use Monadial\Nexus\DDD\Aggregate\Attribute\Identifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

#[CoversClass(Aggregate::class)]
#[CoversClass(Identifier::class)]
#[CoversClass(CommandHandler::class)]
#[CoversClass(ApplyEvent::class)]
final class AttributeTest extends TestCase
{
    #[Test]
    public function aggregateAttributeTargetsClass(): void
    {
        $reflection = new ReflectionClass(TestAnnotatedAggregate::class);
        $attributes = $reflection->getAttributes(Aggregate::class);

        self::assertCount(1, $attributes);
    }

    #[Test]
    public function identifierAttributeTargetsProperty(): void
    {
        $reflection = new ReflectionProperty(TestAnnotatedAggregate::class, 'id');
        $attributes = $reflection->getAttributes(Identifier::class);

        self::assertCount(1, $attributes);
    }

    #[Test]
    public function commandHandlerAttributeTargetsMethod(): void
    {
        $reflection = new ReflectionMethod(TestAnnotatedAggregate::class, 'doSomething');
        $attributes = $reflection->getAttributes(CommandHandler::class);

        self::assertCount(1, $attributes);
    }

    #[Test]
    public function applyEventAttributeTargetsMethod(): void
    {
        $reflection = new ReflectionMethod(TestAnnotatedAggregate::class, 'onSomething');
        $attributes = $reflection->getAttributes(ApplyEvent::class);

        self::assertCount(1, $attributes);
    }
}

/** @psalm-suppress UnusedClass,UnusedProperty */
#[Aggregate]
final class TestAnnotatedAggregate
{
    #[Identifier]
    private string $id = '';

    #[CommandHandler]
    public function doSomething(object $command): void {}

    #[ApplyEvent]
    public function onSomething(object $event): void {}
}
```

**Step 2: Implement all four attributes**

Each is a simple `#[Attribute]` class targeting the appropriate element (CLASS, PROPERTY, METHOD).

`packages/nexus-ddd/src/Aggregate/Attribute/Aggregate.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Aggregate\Attribute;

use Attribute;

/** @psalm-api */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Aggregate {}
```

`Identifier.php` → `Attribute::TARGET_PROPERTY`
`CommandHandler.php` → `Attribute::TARGET_METHOD`
`ApplyEvent.php` → `Attribute::TARGET_METHOD`

**Step 3: Run test**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-ddd/tests/Unit/Aggregate/Attribute/AttributeTest.php -v
```

**Step 4: Commit**

```bash
git add -f packages/nexus-ddd/src/Aggregate/Attribute/ packages/nexus-ddd/tests/Unit/Aggregate/Attribute/
git commit -m "feat(ddd): add Aggregate, Identifier, CommandHandler, ApplyEvent attributes"
```

---

## Task 6: AggregateRoot Base Class

The core of the aggregate pattern — event recording, event application, and event release.

**Files:**
- Create: `packages/nexus-ddd/src/Aggregate/AggregateRoot.php`
- Test: `packages/nexus-ddd/tests/Unit/Aggregate/AggregateRootTest.php`

**Step 1: Write failing tests**

`packages/nexus-ddd/tests/Unit/Aggregate/AggregateRootTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Tests\Unit\Aggregate;

use Monadial\Nexus\DDD\Aggregate\AggregateRoot;
use Monadial\Nexus\DDD\Aggregate\Attribute\Aggregate;
use Monadial\Nexus\DDD\Aggregate\Attribute\ApplyEvent;
use Monadial\Nexus\DDD\Aggregate\Attribute\Identifier;
use Monadial\Nexus\DDD\Event\DomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AggregateRoot::class)]
final class AggregateRootTest extends TestCase
{
    #[Test]
    public function recordsAndReleasesEvents(): void
    {
        $cart = TestShoppingCart::create('cart-1');
        $events = $cart->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(TestCartCreated::class, $events[0]);
    }

    #[Test]
    public function appliesEventsImmediately(): void
    {
        $cart = TestShoppingCart::create('cart-1');

        self::assertSame('cart-1', $cart->getCartId());
    }

    #[Test]
    public function releaseEventsClearsBuffer(): void
    {
        $cart = TestShoppingCart::create('cart-1');
        $cart->releaseEvents();

        self::assertCount(0, $cart->releaseEvents());
    }

    #[Test]
    public function multipleEventsRecordedAndAppliedInOrder(): void
    {
        $cart = TestShoppingCart::create('cart-1');
        $cart->addItem('book');
        $cart->addItem('pen');

        $events = $cart->releaseEvents();

        self::assertCount(3, $events);
        self::assertInstanceOf(TestCartCreated::class, $events[0]);
        self::assertInstanceOf(TestItemAdded::class, $events[1]);
        self::assertInstanceOf(TestItemAdded::class, $events[2]);
        self::assertSame(['book', 'pen'], $cart->getItems());
    }

    #[Test]
    public function hasUnreleasedEventsReturnsTrue(): void
    {
        $cart = TestShoppingCart::create('cart-1');

        self::assertTrue($cart->hasUnreleasedEvents());

        $cart->releaseEvents();

        self::assertFalse($cart->hasUnreleasedEvents());
    }
}

// --- Test fixtures ---

/** @psalm-suppress UnusedClass */
readonly class TestCartCreated implements DomainEvent
{
    public function __construct(public string $cartId) {}
}

/** @psalm-suppress UnusedClass */
readonly class TestItemAdded implements DomainEvent
{
    public function __construct(public string $item) {}
}

/** @psalm-suppress UnusedClass */
#[Aggregate]
final class TestShoppingCart extends AggregateRoot
{
    #[Identifier]
    private string $cartId = '';

    /** @var list<string> */
    private array $items = [];

    public static function create(string $cartId): self
    {
        $cart = new self();
        $cart->recordEvent(new TestCartCreated($cartId));

        return $cart;
    }

    public function addItem(string $item): void
    {
        $this->recordEvent(new TestItemAdded($item));
    }

    #[ApplyEvent]
    public function onCartCreated(TestCartCreated $event): void
    {
        $this->cartId = $event->cartId;
    }

    #[ApplyEvent]
    public function onItemAdded(TestItemAdded $event): void
    {
        $this->items[] = $event->item;
    }

    public function getCartId(): string
    {
        return $this->cartId;
    }

    /** @return list<string> */
    public function getItems(): array
    {
        return $this->items;
    }
}
```

**Step 2: Run test to verify failure**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-ddd/tests/Unit/Aggregate/AggregateRootTest.php -v
```

**Step 3: Implement AggregateRoot**

`packages/nexus-ddd/src/Aggregate/AggregateRoot.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Aggregate;

use Monadial\Nexus\DDD\Aggregate\Attribute\ApplyEvent;
use Monadial\Nexus\DDD\Event\DomainEvent;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/** @psalm-api */
abstract class AggregateRoot
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    /** @var array<class-string, string>|null method name cache per event type */
    private ?array $applyMethodMap = null;

    protected function recordEvent(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
        $this->applyEvent($event);
    }

    /** @return list<DomainEvent> */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    public function hasUnreleasedEvents(): bool
    {
        return $this->recordedEvents !== [];
    }

    private function applyEvent(DomainEvent $event): void
    {
        $map = $this->getApplyMethodMap();
        $eventClass = $event::class;

        if (!isset($map[$eventClass])) {
            throw new RuntimeException(
                "No #[ApplyEvent] method found for {$eventClass} in " . static::class,
            );
        }

        $this->{$map[$eventClass]}($event);
    }

    /** @return array<class-string, string> */
    private function getApplyMethodMap(): array
    {
        if ($this->applyMethodMap !== null) {
            return $this->applyMethodMap;
        }

        $this->applyMethodMap = [];
        $reflection = new ReflectionClass(static::class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(ApplyEvent::class);

            if ($attributes === []) {
                continue;
            }

            $parameters = $method->getParameters();

            if ($parameters === []) {
                continue;
            }

            $type = $parameters[0]->getType();

            if ($type === null) {
                continue;
            }

            /** @var class-string $eventClass */
            $eventClass = $type->getName();
            $this->applyMethodMap[$eventClass] = $method->getName();
        }

        return $this->applyMethodMap;
    }
}
```

**Step 4: Run test to verify pass**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-ddd/tests/Unit/Aggregate/AggregateRootTest.php -v
```

**Step 5: Commit**

```bash
git add -f packages/nexus-ddd/src/Aggregate/AggregateRoot.php packages/nexus-ddd/tests/Unit/Aggregate/AggregateRootTest.php
git commit -m "feat(ddd): add AggregateRoot base class with event recording and apply dispatch"
```

---

## Task 7: AggregateRepository Interface

**Files:**
- Create: `packages/nexus-ddd/src/Aggregate/AggregateRepository.php`
- Create: `packages/nexus-ddd/src/Aggregate/Attribute/Repository.php`
- Create: `packages/nexus-ddd/src/Exception/AggregateNotFoundException.php`
- Create: `packages/nexus-ddd/src/Exception/DuplicateAggregateException.php`

**Step 1: Create the interface and exceptions**

`packages/nexus-ddd/src/Aggregate/AggregateRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Aggregate;

use Monadial\Nexus\DDD\Exception\AggregateNotFoundException;

/**
 * @template T of AggregateRoot
 * @psalm-api
 */
interface AggregateRepository
{
    /**
     * @return T
     * @throws AggregateNotFoundException
     */
    public function load(AggregateId $id): AggregateRoot;

    /** @param T $aggregate */
    public function save(AggregateRoot $aggregate): void;
}
```

`packages/nexus-ddd/src/Exception/AggregateNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Exception;

use Monadial\Nexus\DDD\Aggregate\AggregateId;
use RuntimeException;

/** @psalm-api */
final class AggregateNotFoundException extends RuntimeException
{
    public static function forId(AggregateId $id): self
    {
        return new self("Aggregate not found: {$id->toString()}");
    }
}
```

`packages/nexus-ddd/src/Exception/DuplicateAggregateException.php` — same pattern.

`packages/nexus-ddd/src/Aggregate/Attribute/Repository.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Aggregate\Attribute;

use Attribute;

/** @psalm-api */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Repository {}
```

**Step 2: Commit**

```bash
git add -f packages/nexus-ddd/src/Aggregate/AggregateRepository.php packages/nexus-ddd/src/Aggregate/Attribute/Repository.php packages/nexus-ddd/src/Exception/
git commit -m "feat(ddd): add AggregateRepository interface and DDD exceptions"
```

---

## Task 8: MessageHeaders — Metadata Propagation

**Files:**
- Create: `packages/nexus-ddd/src/Event/MessageHeaders.php`
- Test: `packages/nexus-ddd/tests/Unit/Event/MessageHeadersTest.php`

**Step 1: Write failing test**

Tests: create, get, has, with (immutable), all(), missing key throws.

**Step 2: Implement MessageHeaders**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Event;

/** @psalm-api */
final readonly class MessageHeaders
{
    /** @param array<string, mixed> $headers */
    private function __construct(
        private array $headers,
    ) {}

    /** @param array<string, mixed> $headers */
    public static function create(array $headers = []): self
    {
        return new self($headers);
    }

    public function get(string $key): mixed
    {
        return $this->headers[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->headers);
    }

    public function with(string $key, mixed $value): self
    {
        return new self([...$this->headers, $key => $value]);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->headers;
    }
}
```

**Step 3: Run tests, commit**

```bash
git commit -m "feat(ddd): add MessageHeaders for metadata propagation"
```

---

## Task 9: Bus Interfaces — CommandBus, QueryBus, EventBus

**Files:**
- Create: `packages/nexus-ddd/src/Command/CommandBus.php`
- Create: `packages/nexus-ddd/src/Query/QueryBus.php`
- Create: `packages/nexus-ddd/src/Event/EventBus.php`

**Step 1: Create CommandBus interface**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Command;

/** @psalm-api */
interface CommandBus
{
    public function dispatch(Command $command): void;
}
```

**Step 2: Create QueryBus interface**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Query;

/** @psalm-api */
interface QueryBus
{
    /**
     * @template R
     * @param Query<R> $query
     * @return R
     */
    public function ask(Query $query): mixed;
}
```

**Step 3: Create EventBus interface**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Event;

use Monadial\Nexus\Core\Duration;

/** @psalm-api */
interface EventBus
{
    public function publish(DomainEvent $event, ?MessageHeaders $headers = null): void;

    public function schedulePublish(Duration $delay, DomainEvent $event): void;
}
```

**Step 4: Commit**

```bash
git commit -m "feat(ddd): add CommandBus, QueryBus, and EventBus interfaces"
```

---

## Task 10: Handler Attributes — EventHandler, QueryHandler, Asynchronous

**Files:**
- Create: `packages/nexus-ddd/src/Event/Attribute/EventHandler.php`
- Create: `packages/nexus-ddd/src/Query/Attribute/QueryHandler.php`
- Create: `packages/nexus-ddd/src/Command/Attribute/Asynchronous.php`
- Test: `packages/nexus-ddd/tests/Unit/Event/Attribute/EventHandlerTest.php`

**Step 1: Implement attributes**

Each is a simple `#[Attribute(Attribute::TARGET_METHOD)]` class.

`EventHandler.php` optionally accepts a routing key string.
`QueryHandler.php` is method-targeted.
`Asynchronous.php` targets methods — marks any handler for async processing.

**Step 2: Write tests verifying reflection**

**Step 3: Commit**

```bash
git commit -m "feat(ddd): add EventHandler, QueryHandler, and Asynchronous attributes"
```

---

## Task 11: ProcessEffect

**Files:**
- Create: `packages/nexus-ddd/src/ProcessManager/ProcessEffect.php`
- Test: `packages/nexus-ddd/tests/Unit/ProcessManager/ProcessEffectTest.php`

**Step 1: Write failing tests**

Test: `dispatch()` creates effect with commands, `withDeadline()` adds a deadline, `cancelDeadline()` adds cancellation, `scheduleEvent()` adds scheduled event, `completed()` marks completion. All methods return new immutable instances.

**Step 2: Implement ProcessEffect**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\ProcessManager;

use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\DDD\Command\Command;
use Monadial\Nexus\DDD\Event\DomainEvent;

/** @psalm-api */
final readonly class ProcessEffect
{
    /**
     * @param list<Command> $commands
     * @param list<array{name: string, timeout: Duration, event: DomainEvent}> $deadlines
     * @param list<string> $cancelledDeadlines
     * @param list<array{delay: Duration, event: DomainEvent}> $scheduledEvents
     */
    private function __construct(
        public array $commands = [],
        public array $deadlines = [],
        public array $cancelledDeadlines = [],
        public array $scheduledEvents = [],
        public bool $isCompleted = false,
    ) {}

    public static function dispatch(Command ...$commands): self
    {
        return new self(commands: array_values($commands));
    }

    public static function none(): self
    {
        return new self();
    }

    public static function cancelDeadline(string $name): self
    {
        return new self(cancelledDeadlines: [$name]);
    }

    public function dispatch(Command ...$commands): self
    {
        return new self(
            cancelledDeadlines: $this->cancelledDeadlines,
            commands: [...$this->commands, ...array_values($commands)],
            deadlines: $this->deadlines,
            isCompleted: $this->isCompleted,
            scheduledEvents: $this->scheduledEvents,
        );
    }

    public function withDeadline(string $name, Duration $timeout, DomainEvent $event): self
    {
        return new self(
            cancelledDeadlines: $this->cancelledDeadlines,
            commands: $this->commands,
            deadlines: [...$this->deadlines, ['event' => $event, 'name' => $name, 'timeout' => $timeout]],
            isCompleted: $this->isCompleted,
            scheduledEvents: $this->scheduledEvents,
        );
    }

    public function cancelDeadline(string $name): self
    {
        return new self(
            cancelledDeadlines: [...$this->cancelledDeadlines, $name],
            commands: $this->commands,
            deadlines: $this->deadlines,
            isCompleted: $this->isCompleted,
            scheduledEvents: $this->scheduledEvents,
        );
    }

    public function scheduleEvent(Duration $delay, DomainEvent $event): self
    {
        return new self(
            cancelledDeadlines: $this->cancelledDeadlines,
            commands: $this->commands,
            deadlines: $this->deadlines,
            isCompleted: $this->isCompleted,
            scheduledEvents: [...$this->scheduledEvents, ['delay' => $delay, 'event' => $event]],
        );
    }

    public function completed(): self
    {
        return new self(
            cancelledDeadlines: $this->cancelledDeadlines,
            commands: $this->commands,
            deadlines: $this->deadlines,
            isCompleted: true,
            scheduledEvents: $this->scheduledEvents,
        );
    }
}
```

Note: `ProcessEffect` has both a static `dispatch()` and instance `dispatch()`. The static one is the entry point; the instance one chains additional commands. PHP allows this because static methods don't conflict with instance methods. If Psalm complains, rename the instance method to `andDispatch()`.

**Step 3: Run tests, commit**

```bash
git commit -m "feat(ddd): add ProcessEffect with deadlines, scheduling, and completion"
```

---

## Task 12: ProcessManager Base Class

**Files:**
- Create: `packages/nexus-ddd/src/ProcessManager/ProcessManager.php`
- Create: `packages/nexus-ddd/src/ProcessManager/Attribute/OnEvent.php`
- Test: `packages/nexus-ddd/tests/Unit/ProcessManager/ProcessManagerTest.php`

**Step 1: Write failing tests**

Test that: `processId()` is abstract, `#[OnEvent]` methods are discovered via reflection, event dispatch resolves correct method by event type.

**Step 2: Implement OnEvent attribute**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\ProcessManager\Attribute;

use Attribute;

/** @psalm-api */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class OnEvent
{
    /** @param class-string $eventClass */
    public function __construct(public string $eventClass) {}
}
```

**Step 3: Implement ProcessManager base class**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\ProcessManager;

/** @psalm-api */
abstract class ProcessManager
{
    abstract public function processId(): ProcessId;
}
```

The ProcessManager is intentionally thin — it's a plain object with `#[OnEvent]` methods. The framework dispatches events to matching methods via reflection (same pattern as AggregateRoot's `#[ApplyEvent]` dispatch).

**Step 4: Run tests, commit**

```bash
git commit -m "feat(ddd): add ProcessManager base class with OnEvent attribute"
```

---

## Task 13: Projector Base Class

**Files:**
- Create: `packages/nexus-ddd/src/Projection/Projector.php`
- Create: `packages/nexus-ddd/src/Projection/Attribute/Projection.php`
- Create: `packages/nexus-ddd/src/Projection/ProjectionPosition.php`
- Test: `packages/nexus-ddd/tests/Unit/Projection/ProjectorTest.php`
- Test: `packages/nexus-ddd/tests/Unit/Projection/ProjectionPositionTest.php`

**Step 1: Write failing tests**

Test: `Projection` attribute holds projection name, `ProjectionPosition` tracks name + last sequence nr, `Projector` declares `subscribesTo()`.

**Step 2: Implement**

`packages/nexus-ddd/src/Projection/Attribute/Projection.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Projection\Attribute;

use Attribute;

/** @psalm-api */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Projection
{
    public function __construct(public string $name) {}
}
```

`packages/nexus-ddd/src/Projection/ProjectionPosition.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Projection;

/** @psalm-api */
final readonly class ProjectionPosition
{
    public function __construct(
        public string $projectionName,
        public int $lastProcessedSequenceNr,
    ) {}
}
```

`packages/nexus-ddd/src/Projection/Projector.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Projection;

/** @psalm-api */
abstract class Projector
{
    /** @return list<class-string> */
    abstract protected function subscribesTo(): array;
}
```

**Step 3: Run tests, commit**

```bash
git commit -m "feat(ddd): add Projector base class with Projection attribute and position tracking"
```

---

## Task 14: Interceptor Attributes and MethodInvocation

**Files:**
- Create: `packages/nexus-ddd/src/Interceptor/Attribute/Before.php`
- Create: `packages/nexus-ddd/src/Interceptor/Attribute/After.php`
- Create: `packages/nexus-ddd/src/Interceptor/Attribute/Around.php`
- Create: `packages/nexus-ddd/src/Interceptor/MethodInvocation.php`
- Test: `packages/nexus-ddd/tests/Unit/Interceptor/InterceptorTest.php`

**Step 1: Write failing tests**

Test: attributes hold pointcut string, MethodInvocation interface has `proceed()`, `getArguments()`, `getTarget()`, `getMethodName()`.

**Step 2: Implement attributes**

```php
// Before.php
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Before
{
    /** @param class-string $pointcut */
    public function __construct(public string $pointcut) {}
}

// After.php — same pattern
// Around.php — same pattern
```

**Step 3: Implement MethodInvocation interface**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Interceptor;

/** @psalm-api */
interface MethodInvocation
{
    public function proceed(): mixed;

    /** @return list<mixed> */
    public function getArguments(): array;

    public function getTarget(): object;

    public function getMethodName(): string;
}
```

**Step 4: Run tests, commit**

```bash
git commit -m "feat(ddd): add Before, After, Around interceptor attributes and MethodInvocation"
```

---

## Task 15: Outbox Interface

**Files:**
- Create: `packages/nexus-ddd/src/Outbox/OutboxStore.php`
- Create: `packages/nexus-ddd/src/Outbox/OutboxEntry.php`

**Step 1: Create OutboxEntry value object**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Outbox;

use DateTimeImmutable;

/** @psalm-api */
final readonly class OutboxEntry
{
    public function __construct(
        public string $id,
        public string $eventType,
        public string $eventPayload,
        public DateTimeImmutable $createdAt,
        public bool $published = false,
    ) {}
}
```

**Step 2: Create OutboxStore interface**

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Outbox;

/** @psalm-api */
interface OutboxStore
{
    public function store(OutboxEntry ...$entries): void;

    /** @return list<OutboxEntry> */
    public function fetchPending(int $batchSize = 100): array;

    public function markPublished(string ...$entryIds): void;
}
```

**Step 3: Commit**

```bash
git commit -m "feat(ddd): add OutboxStore interface and OutboxEntry value object"
```

---

## Task 16: NexusDdd Configuration Builder

The entry point that wires everything together. This is the fluent API where all infrastructure is configured externally.

**Files:**
- Create: `packages/nexus-ddd/src/Configuration/NexusDdd.php`
- Create: `packages/nexus-ddd/src/Configuration/AggregateConfiguration.php`
- Create: `packages/nexus-ddd/src/Configuration/ProcessManagerConfiguration.php`
- Create: `packages/nexus-ddd/src/Configuration/ProjectionConfiguration.php`
- Test: `packages/nexus-ddd/tests/Unit/Configuration/NexusDddTest.php`

**Step 1: Write failing tests**

Test: fluent builder API — `NexusDdd::configure($system)`, chained `->aggregate()`, `->processManager()`, `->projection()`, `->handler()`, `->interceptor()`, `->defaultOutbox()`, `->withContainer()`, `->build()`. Verify configurations are collected correctly.

**Step 2: Implement configuration classes**

`packages/nexus-ddd/src/Configuration/AggregateConfiguration.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\DDD\Configuration;

use Monadial\Nexus\DDD\Outbox\OutboxStore;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;

/** @psalm-api */
final class AggregateConfiguration
{
    private ?EventStore $eventStore = null;
    private ?SnapshotStore $snapshotStore = null;
    private ?SnapshotStrategy $snapshotStrategy = null;
    private ?OutboxStore $outboxStore = null;
    private ?object $stateRepository = null;

    /** @param class-string $aggregateClass */
    public function __construct(
        public readonly string $aggregateClass,
        private readonly NexusDdd $parent,
    ) {}

    public function eventSourced(EventStore $eventStore): self
    {
        $this->eventStore = $eventStore;

        return $this;
    }

    public function withSnapshots(SnapshotStore $store, SnapshotStrategy $strategy): self
    {
        $this->snapshotStore = $store;
        $this->snapshotStrategy = $strategy;

        return $this;
    }

    public function stateStored(object $repository): self
    {
        $this->stateRepository = $repository;

        return $this;
    }

    public function withOutbox(OutboxStore $store): self
    {
        $this->outboxStore = $store;

        return $this;
    }

    // Delegate back to parent for chaining

    /** @param class-string $class */
    public function aggregate(string $class): AggregateConfiguration
    {
        return $this->parent->aggregate($class);
    }

    /** @param class-string $class */
    public function processManager(string $class): ProcessManagerConfiguration
    {
        return $this->parent->processManager($class);
    }

    /** @param class-string $class */
    public function projection(string $class): ProjectionConfiguration
    {
        return $this->parent->projection($class);
    }

    /** @param class-string $class */
    public function handler(string $class): NexusDdd
    {
        return $this->parent->handler($class);
    }

    /** @param class-string $class */
    public function interceptor(string $class): NexusDdd
    {
        return $this->parent->interceptor($class);
    }

    public function build(): void
    {
        $this->parent->build();
    }

    // Getters for framework internals
    public function getEventStore(): ?EventStore { return $this->eventStore; }
    public function getSnapshotStore(): ?SnapshotStore { return $this->snapshotStore; }
    public function getSnapshotStrategy(): ?SnapshotStrategy { return $this->snapshotStrategy; }
    public function getOutboxStore(): ?OutboxStore { return $this->outboxStore; }
    public function getStateRepository(): ?object { return $this->stateRepository; }
    public function isEventSourced(): bool { return $this->eventStore !== null; }
}
```

`NexusDdd.php` — collects all configurations, `build()` wires actors.

`ProcessManagerConfiguration.php` and `ProjectionConfiguration.php` — similar fluent builders.

**Step 3: Run tests, commit**

```bash
git commit -m "feat(ddd): add NexusDdd configuration builder with fluent API"
```

---

## Task 17: Remaining Exceptions

**Files:**
- Create: `packages/nexus-ddd/src/Exception/CommandRejected.php`
- Create: `packages/nexus-ddd/src/Exception/ProcessManagerException.php`
- Create: `packages/nexus-ddd/src/Exception/ProjectionException.php`

**Step 1: Implement all three**

Each extends `RuntimeException` with a static factory method:

```php
final class CommandRejected extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("Command rejected: {$reason}");
    }
}
```

**Step 2: Commit**

```bash
git commit -m "feat(ddd): add remaining DDD exception classes"
```

---

## Task 18: Run Full Suite — Psalm + Tests + Deptrac

**Step 1: Run unit tests**

```bash
docker compose exec php vendor/bin/phpunit --testsuite=unit-ddd -v
```

Expected: all pass.

**Step 2: Run Psalm**

```bash
docker compose exec php vendor/bin/psalm
```

Fix any level 1 issues.

**Step 3: Run Deptrac**

```bash
docker compose exec php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac
```

Verify DDD layer only depends on Core and Persistence.

**Step 4: Run PHPCS + CS-Fixer**

```bash
docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose exec php vendor/bin/phpcs
```

Fix any style violations (alphabetical arrays, blank lines before control structures, etc.).

**Step 5: Fix all issues and commit**

```bash
git commit -m "chore(ddd): fix psalm, deptrac, and code style issues"
```

---

## Task 19: Final Verification and Integration Commit

**Step 1: Run make test-unit**

```bash
make test-unit
```

Verify all existing tests still pass AND new DDD tests pass.

**Step 2: Run make psalm**

```bash
make psalm
```

**Step 3: Run make phpcs**

```bash
make phpcs
```

**Step 4: Final commit if needed**

```bash
git commit -m "feat(ddd): complete nexus-ddd package with all tactical DDD patterns"
```

---

## Summary

| Task | Component | Key Files |
|------|-----------|-----------|
| 1 | Package scaffolding | composer.json, phpunit.xml, psalm.xml, deptrac.yaml |
| 2 | ValueObject + SingleValueObject | ValueObject/, 2 classes + 2 tests |
| 3 | AggregateId + ProcessId | Identity VOs, 2 classes + 2 tests |
| 4 | DomainEvent, Command, Query | Marker interfaces, 3 classes + 1 test |
| 5 | Aggregate attributes | 4 attributes + 1 test |
| 6 | AggregateRoot base class | Core aggregate with event recording + 1 test |
| 7 | AggregateRepository + exceptions | Interface + 3 exceptions |
| 8 | MessageHeaders | Metadata propagation + 1 test |
| 9 | Bus interfaces | CommandBus, QueryBus, EventBus |
| 10 | Handler attributes | EventHandler, QueryHandler, Asynchronous |
| 11 | ProcessEffect | Deadlines, scheduling, completion + 1 test |
| 12 | ProcessManager base | OnEvent attribute + base class + 1 test |
| 13 | Projector base | Projection attribute + position + 1 test |
| 14 | Interceptor attributes | Before, After, Around + MethodInvocation |
| 15 | Outbox interface | OutboxStore + OutboxEntry |
| 16 | NexusDdd configuration | Fluent builder + 3 config classes + 1 test |
| 17 | Remaining exceptions | CommandRejected, ProcessManagerException, ProjectionException |
| 18 | Full suite verification | Psalm + Deptrac + PHPCS + tests |
| 19 | Final integration | Make targets pass |
