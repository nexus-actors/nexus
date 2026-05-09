<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Strategy\Stateful;

use Monadial\Nexus\Ddd\Aggregate\Exception\AggregateAlreadyExistsException;
use Monadial\Nexus\Ddd\Aggregate\Strategy\Stateful\InMemoryStatefulStrategy;
use Monadial\Nexus\Ddd\Core\Aggregate\StatefulAggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(InMemoryStatefulStrategy::class)]
final class InMemoryStatefulStrategyTest extends TestCase
{
    #[Test]
    public function loadReturnsNoneForAbsentAggregate(): void
    {
        $strategy = new InMemoryStatefulStrategy();

        self::assertTrue($strategy->load(Customer::class, self::newId())->isNone());
    }

    #[Test]
    public function persistThenLoadRoundTripsAggregate(): void
    {
        $strategy = new InMemoryStatefulStrategy();
        $id = self::newId();
        $original = Customer::register($id, 'alice');

        $strategy->persist($original);

        $reloaded = $strategy->load(Customer::class, $id)->getUnsafe();
        self::assertNotNull($reloaded);
        self::assertSame('alice', $reloaded->name);
        self::assertSame(1, $reloaded->version());
    }

    #[Test]
    public function persistOfFreshAggregateSucceeds(): void
    {
        $strategy = new InMemoryStatefulStrategy();

        $strategy->persist(Customer::register(self::newId(), 'alice'));

        // No exception is the assertion: the INSERT path completed cleanly.
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function persistOfFreshAggregateWithExistingIdRaisesAggregateAlreadyExistsException(): void
    {
        $strategy = new InMemoryStatefulStrategy();
        $id = self::newId();
        $strategy->persist(Customer::register($id, 'alice'));

        $this->expectException(AggregateAlreadyExistsException::class);
        $strategy->persist(Customer::register($id, 'bob'));
    }

    #[Test]
    public function persistOfLoadedAggregateRoundTripsCleanly(): void
    {
        $strategy = new InMemoryStatefulStrategy();
        $id = self::newId();
        $strategy->persist(Customer::register($id, 'alice'));

        $reloaded = $strategy->load(Customer::class, $id)->getUnsafe();
        self::assertNotNull($reloaded);

        $reloaded->rename('bob');
        $strategy->persist($reloaded);

        $final = $strategy->load(Customer::class, $id)->getUnsafe();
        self::assertNotNull($final);
        self::assertSame('bob', $final->name);
        self::assertSame(2, $final->version());
    }

    #[Test]
    public function persistOfStaleAggregateRaisesOptimisticLockException(): void
    {
        $strategy = new InMemoryStatefulStrategy();
        $id = self::newId();
        $strategy->persist(Customer::register($id, 'alice'));

        $a = $strategy->load(Customer::class, $id)->getUnsafe();
        $b = $strategy->load(Customer::class, $id)->getUnsafe();
        self::assertNotNull($a);
        self::assertNotNull($b);

        $a->rename('bob');
        $strategy->persist($a);

        $b->rename('charlie');

        $this->expectException(OptimisticLockException::class);
        $strategy->persist($b);
    }

    #[Test]
    public function loadReturnsTypedAggregateInstance(): void
    {
        $strategy = new InMemoryStatefulStrategy();
        $id = self::newId();
        $strategy->persist(Customer::register($id, 'alice'));

        $reloaded = $strategy->load(Customer::class, $id)->getUnsafe();

        self::assertInstanceOf(Customer::class, $reloaded);
    }

    private static function newId(): TestUlidId
    {
        return new TestUlidId((new Ulid())->toBase32());
    }
}

interface CustomerEvent extends DomainEvent {}

final readonly class CustomerRegistered implements CustomerEvent
{
    public function __construct(public string $customerId, public string $name) {}
}

final readonly class CustomerRenamed implements CustomerEvent
{
    public function __construct(public string $newName) {}
}

/** @extends StatefulAggregateRoot<TestUlidId, CustomerEvent> */
final class Customer extends StatefulAggregateRoot
{
    public string $name = '';

    public static function register(TestUlidId $id, string $name): self
    {
        $customer = new self($id);
        $customer->name = $name;
        $customer->recordThat(new CustomerRegistered($id->value(), $name));

        return $customer;
    }

    #[Override]
    public function id(): TestUlidId
    {
        /** @var TestUlidId */
        return $this->id;
    }

    public function rename(string $newName): void
    {
        $this->name = $newName;
        $this->recordThat(new CustomerRenamed($newName));
    }
}
