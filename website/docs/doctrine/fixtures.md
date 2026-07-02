---
title: Test fixtures for EntityBehavior aggregates
related:
  - doctrine/entity-behavior
  - doctrine/second-level-cache
  - persistence/testing
  - runtimes/step
---

# Test fixtures for EntityBehavior aggregates

Testing `EntityBehavior`-based aggregates requires a database connection or an in-memory substitute. This page covers two strategies: seeding a real (SQLite or test Postgres) database, and using `StepRuntime` for deterministic command replay.

## Strategy 1: in-memory SQLite with a real EntityManager

Create a fresh SQLite database per test, run the schema creator, and seed fixtures via the `EntityManager` directly. The `EntityBehavior` actor then loads from this pre-seeded state.

<!-- verify:skip: requires Doctrine ORM and a test database -->
```php title="tests/Integration/OrderActorTest.php" verify:skip
<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Runtime\Step\StepRuntime;

// 1. Create schema
$schemaTool = new SchemaTool($entityManager);
$schemaTool->createSchema([$entityManager->getClassMetadata(Order::class)]);

// 2. Seed fixture
$order = new Order('order-test-1', ['item-a', 'item-b']);
$entityManager->persist($order);
$entityManager->flush();
$entityManager->clear(); // detach so actor loads fresh

// 3. Spawn actor and exercise
$runtime = new StepRuntime();
$system  = ActorSystem::create('test', $runtime);

$factory = EntityRefFactory::builder(Order::class, $entityManager)->build($system);
$ref     = $factory->of('order-test-1');

$ref->tell(new CancelOrder(replyTo: $captureRef));
$runtime->step();

self::assertSame(OrderStatus::Cancelled, $captured->status);
```

## Strategy 2: pre-seed the EntityRefFactory cache

`EntityRefFactory` holds a cache of actor refs keyed by entity identity. In tests, you can spawn an actor directly and pre-load it with a fixture entity by sending an initialisation command as the first message instead of relying on database load.

```php title="tests/Unit/OrderActorTest.php"
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Step\StepRuntime;

// Build a behavior that accepts a seed message before switching
// to the real command handler
$seedBehavior = Behavior::receive(
    static fn ($ctx, $msg) => match (true) {
        $msg instanceof SeedFixture => buildOrderBehavior($msg->order),
        default => Behavior::unhandled(),
    },
);

$runtime = new StepRuntime();
$system  = ActorSystem::create('test', $runtime);
$ref     = $system->spawn(Props::fromBehavior($seedBehavior), 'order');

$ref->tell(new SeedFixture(new Order('order-1', ['item-a'])));
$runtime->step(); // transitions to real behavior

$ref->tell(new CancelOrder(replyTo: $captureRef));
$runtime->step();

self::assertSame(OrderStatus::Cancelled, $captured->status);
```

This approach avoids a database entirely and keeps unit tests fast.

## Resetting between tests

In-memory SQLite databases are per-connection, so each test that creates a new connection starts with a fresh database. For test suites that reuse a connection, wrap each test in a transaction and roll back after:

<!-- verify:skip: requires Doctrine ORM transaction control -->
```php title="tests/Integration/TransactionalTestCase.php" verify:skip
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class TransactionalTestCase extends TestCase
{
    protected function setUp(): void
    {
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        $this->entityManager->clear();
    }
}
```

:::caution Rollback and EntityRefFactory
The `EntityRefFactory` cache is per-`ActorSystem` instance. Create a new `ActorSystem` (and therefore a new `EntityRefFactory`) per test to ensure the cache is empty and each test observes its own fixture state.
:::

## See also

- [Entity behavior](./entity-behavior.md) — `EntityBehavior` API and `EntityRefFactory`.
- [Testing persistent actors](../persistence/testing.md) — `StepRuntime` + in-memory event stores.
- [Step runtime](../runtimes/step.md) — deterministic message-by-message execution.
