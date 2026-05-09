<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Strategy;

use Fp\Functional\Option\Option;
use LogicException;
use Monadial\Nexus\Ddd\Aggregate\Strategy\CompositePersistenceStrategy;
use Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcedPersister;
use Monadial\Nexus\Ddd\Aggregate\Strategy\StatefulPersister;
use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRoot;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Aggregate\StatefulAggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(CompositePersistenceStrategy::class)]
final class CompositePersistenceStrategyTest extends TestCase
{
    #[Test]
    public function persistDispatchesEventSourcedAggregateToEventSourcedPersister(): void
    {
        $es = new RecordingEventSourcedPersister(Option::none());
        $stateful = new RecordingStatefulPersister(Option::none());
        $composite = new CompositePersistenceStrategy($es, $stateful);

        $aggregate = CompositeOrder::placeNew(self::newId());
        $composite->persist($aggregate);

        self::assertSame($aggregate, $es->lastPersisted);
        self::assertNull($stateful->lastPersisted);
    }

    #[Test]
    public function persistDispatchesStatefulAggregateToStatefulPersister(): void
    {
        $es = new RecordingEventSourcedPersister(Option::none());
        $stateful = new RecordingStatefulPersister(Option::none());
        $composite = new CompositePersistenceStrategy($es, $stateful);

        $aggregate = CompositeCustomer::register(self::newId());
        $composite->persist($aggregate);

        self::assertSame($aggregate, $stateful->lastPersisted);
        self::assertNull($es->lastPersisted);
    }

    #[Test]
    public function persistOfNonRecognizedAggregateThrowsLogicException(): void
    {
        $composite = new CompositePersistenceStrategy(
            new RecordingEventSourcedPersister(Option::none()),
            new RecordingStatefulPersister(Option::none()),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot persist');
        $composite->persist(new BareAggregate(self::newId()));
    }

    #[Test]
    public function loadOfEventSourcedClassDispatchesToEventSourcedPersister(): void
    {
        $es = new RecordingEventSourcedPersister(Option::none());
        $stateful = new RecordingStatefulPersister(Option::none());
        $composite = new CompositePersistenceStrategy($es, $stateful);

        $id = self::newId();
        $composite->load(CompositeOrder::class, $id);

        self::assertSame(CompositeOrder::class, $es->lastLoadedClass);
        self::assertSame($id, $es->lastLoadedId);
        self::assertNull($stateful->lastLoadedClass);
    }

    #[Test]
    public function loadOfStatefulClassDispatchesToStatefulPersister(): void
    {
        $es = new RecordingEventSourcedPersister(Option::none());
        $stateful = new RecordingStatefulPersister(Option::none());
        $composite = new CompositePersistenceStrategy($es, $stateful);

        $id = self::newId();
        $composite->load(CompositeCustomer::class, $id);

        self::assertSame(CompositeCustomer::class, $stateful->lastLoadedClass);
        self::assertSame($id, $stateful->lastLoadedId);
        self::assertNull($es->lastLoadedClass);
    }

    #[Test]
    public function loadOfNonRecognizedClassThrowsLogicException(): void
    {
        $composite = new CompositePersistenceStrategy(
            new RecordingEventSourcedPersister(Option::none()),
            new RecordingStatefulPersister(Option::none()),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot load');
        $composite->load(BareAggregate::class, self::newId());
    }

    #[Test]
    public function loadReturnsOptionFromUnderlyingPersister(): void
    {
        $id = self::newId();
        $persistedOrder = CompositeOrder::placeNew($id);
        $persistedCustomer = CompositeCustomer::register($id);

        $es = new RecordingEventSourcedPersister(Option::some($persistedOrder));
        $stateful = new RecordingStatefulPersister(Option::some($persistedCustomer));
        $composite = new CompositePersistenceStrategy($es, $stateful);

        self::assertSame($persistedOrder, $composite->load(CompositeOrder::class, $id)->getUnsafe());
        self::assertSame($persistedCustomer, $composite->load(CompositeCustomer::class, $id)->getUnsafe());

        $emptyEs = new RecordingEventSourcedPersister(Option::none());
        $emptyStateful = new RecordingStatefulPersister(Option::none());
        $emptyComposite = new CompositePersistenceStrategy($emptyEs, $emptyStateful);

        self::assertTrue($emptyComposite->load(CompositeOrder::class, $id)->isNone());
        self::assertTrue($emptyComposite->load(CompositeCustomer::class, $id)->isNone());
    }

    private static function newId(): TestUlidId
    {
        return new TestUlidId((new Ulid())->toBase32());
    }
}

interface CompositeOrderEvent extends DomainEvent {}

final readonly class CompositeOrderPlaced implements CompositeOrderEvent
{
    public function __construct(public string $orderId) {}
}

/** @extends EventSourcedAggregateRoot<TestUlidId, CompositeOrderEvent> */
final class CompositeOrder extends EventSourcedAggregateRoot
{
    public bool $placed = false;

    public static function placeNew(TestUlidId $id): self
    {
        $order = new self($id);
        $order->recordThat(new CompositeOrderPlaced($id->value()));

        return $order;
    }

    #[Override]
    public function id(): TestUlidId
    {
        /** @var TestUlidId */
        return $this->id;
    }

    #[Override]
    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof CompositeOrderPlaced => $this->placed = true,
        };
    }
}

interface CompositeCustomerEvent extends DomainEvent {}

final readonly class CompositeCustomerRegistered implements CompositeCustomerEvent
{
    public function __construct(public string $customerId) {}
}

/** @extends StatefulAggregateRoot<TestUlidId, CompositeCustomerEvent> */
final class CompositeCustomer extends StatefulAggregateRoot
{
    public static function register(TestUlidId $id): self
    {
        $customer = new self($id);
        $customer->recordThat(new CompositeCustomerRegistered($id->value()));

        return $customer;
    }

    #[Override]
    public function id(): TestUlidId
    {
        /** @var TestUlidId */
        return $this->id;
    }
}

/**
 * Direct AggregateRoot subclass — neither event-sourced nor stateful — used
 * to exercise the composite's "unrecognised aggregate kind" defensive branch.
 *
 * @extends AggregateRoot<TestUlidId, DomainEvent>
 */
final class BareAggregate extends AggregateRoot
{
    public function __construct(TestUlidId $id)
    {
        parent::__construct($id);
    }

    #[Override]
    public function id(): TestUlidId
    {
        /** @var TestUlidId */
        return $this->id;
    }
}

final class RecordingEventSourcedPersister implements EventSourcedPersister
{
    public ?EventSourcedAggregateRoot $lastPersisted = null;
    public ?string $lastLoadedClass = null;
    public ?Identifier $lastLoadedId = null;

    /** @param Option<EventSourcedAggregateRoot> $loadResult */
    public function __construct(private Option $loadResult) {}

    #[Override]
    public function persist(EventSourcedAggregateRoot $entity): void
    {
        $this->lastPersisted = $entity;
    }

    #[Override]
    public function load(string $entityClass, Identifier $id): Option
    {
        $this->lastLoadedClass = $entityClass;
        $this->lastLoadedId = $id;

        /** @var Option<EventSourcedAggregateRoot> */
        return $this->loadResult;
    }
}

final class RecordingStatefulPersister implements StatefulPersister
{
    public ?StatefulAggregateRoot $lastPersisted = null;
    public ?string $lastLoadedClass = null;
    public ?Identifier $lastLoadedId = null;

    /** @param Option<StatefulAggregateRoot> $loadResult */
    public function __construct(private Option $loadResult) {}

    #[Override]
    public function persist(StatefulAggregateRoot $entity): void
    {
        $this->lastPersisted = $entity;
    }

    #[Override]
    public function load(string $entityClass, Identifier $id): Option
    {
        $this->lastLoadedClass = $entityClass;
        $this->lastLoadedId = $id;

        /** @var Option<StatefulAggregateRoot> */
        return $this->loadResult;
    }
}
