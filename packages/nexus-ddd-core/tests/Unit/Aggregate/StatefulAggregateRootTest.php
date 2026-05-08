<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRootAccessor;
use Monadial\Nexus\Ddd\Core\Aggregate\StatefulAggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(StatefulAggregateRoot::class)]
final class StatefulAggregateRootTest extends TestCase
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    private EventSourcedAggregateRootAccessor $accessor;

    #[Test]
    public function statefulAggregateRecordsEventsAndMutatesStateDirectly(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $c = StatefulCustomer::register($id, 'Ada Lovelace');

        self::assertSame('Ada Lovelace', $c->name);

        $events = $this->accessor->popRecordedEventsFrom($c);
        self::assertCount(1, $events);
        self::assertInstanceOf(CustomerRegistered::class, $events[0]);

        self::assertSame(1, $c->version());
    }

    #[Override]
    protected function setUp(): void
    {
        $this->accessor = new EventSourcedAggregateRootAccessor();
    }

    #[Test]
    public function statefulAggregateIsNotEventSourceable(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $c = StatefulCustomer::register($id, 'Ada');

        // The whole point of the StatefulAggregateRoot/EventSourcedAggregateRoot
        // split: a stateful aggregate is NOT event-sourceable.
        self::assertNotInstanceOf(EventSourceable::class, $c);
        self::assertInstanceOf(StatefulAggregateRoot::class, $c);
    }
}

/** @extends StatefulAggregateRoot<CustomerRegistered> */
/** @extends StatefulAggregateRoot<TestUlidId, CustomerRegistered> */
final class StatefulCustomer extends StatefulAggregateRoot
{
    public string $name = '';

    public static function register(TestUlidId $id, string $name): self
    {
        $c = new self($id);
        $c->name = $name;
        $c->recordThat(new CustomerRegistered($id, $name));

        return $c;
    }

    #[Override]
    public function id(): TestUlidId
    {
        /** @var TestUlidId */
        return $this->id;
    }
}

final readonly class CustomerRegistered implements DomainEvent
{
    public function __construct(public Identifier $id, public string $name,) {}
}
