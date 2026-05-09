<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Repository;

use Fp\Functional\Option\Option;
use LogicException;
use Monadial\Nexus\Ddd\Aggregate\Exception\AggregateAlreadyExistsException;
use Monadial\Nexus\Ddd\Aggregate\Repository\GenericAggregateRepository;
use Monadial\Nexus\Ddd\Aggregate\Strategy\PersistenceStrategy;
use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRoot;
use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRootAccessor;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;
use Throwable;

#[CoversClass(GenericAggregateRepository::class)]
final class GenericAggregateRepositoryTest extends TestCase
{
    #[Test]
    public function findReturnsNoneWhenStrategyReturnsNone(): void
    {
        $strategy = new RecordingPersistenceStrategy(Option::none());
        $repo = new GenericAggregateRepository(TinyOrder::class, $strategy);

        $result = $repo->find(self::newId());

        self::assertTrue($result->isNone());
    }

    #[Test]
    public function findReturnsLoadedAggregateWhenStrategyReturnsSome(): void
    {
        $aggregate = TinyOrder::placeNew(self::newId());
        $strategy = new RecordingPersistenceStrategy(Option::some($aggregate));
        $repo = new GenericAggregateRepository(TinyOrder::class, $strategy);

        $result = $repo->find($aggregate->id());

        self::assertSame($aggregate, $result->getUnsafe());
        self::assertSame(TinyOrder::class, $strategy->lastLoadedClass);
        self::assertSame($aggregate->id(), $strategy->lastLoadedId);
    }

    #[Test]
    public function saveOfFreshAggregateDelegatesPersistToStrategy(): void
    {
        $strategy = new RecordingPersistenceStrategy(Option::none());
        $repo = new GenericAggregateRepository(TinyOrder::class, $strategy);
        $aggregate = TinyOrder::createBlank(self::newId());

        self::assertSame(0, $aggregate->version());

        $repo->save($aggregate);

        self::assertSame($aggregate, $strategy->lastPersisted);
    }

    #[Test]
    public function saveOfLoadedAggregateDelegatesPersistToStrategy(): void
    {
        $strategy = new RecordingPersistenceStrategy(Option::none());
        $repo = new GenericAggregateRepository(TinyOrder::class, $strategy);
        $aggregate = TinyOrder::placeNew(self::newId());

        new AggregateRootAccessor()->rehydrateVersionOn($aggregate, 7);

        $repo->save($aggregate);

        self::assertSame($aggregate, $strategy->lastPersisted);
    }

    #[Test]
    public function saveAcceptsBothFreshAndLoadedAggregates(): void
    {
        $strategy = new RecordingPersistenceStrategy(Option::none());
        $repo = new GenericAggregateRepository(TinyOrder::class, $strategy);
        $accessor = new AggregateRootAccessor();

        $fresh = TinyOrder::createBlank(self::newId());
        $repo->save($fresh);
        self::assertSame($fresh, $strategy->lastPersisted);

        $loaded = TinyOrder::createBlank(self::newId());
        $accessor->rehydrateVersionOn($loaded, 3);
        $repo->save($loaded);
        self::assertSame($loaded, $strategy->lastPersisted);
    }

    #[Test]
    public function exceptionsFromStrategyPropagateUnchanged(): void
    {
        $alreadyExists = AggregateAlreadyExistsException::for(TinyOrder::class, 'order-1');
        $strategy = new RecordingPersistenceStrategy(Option::none(), persistThrows: $alreadyExists);
        $repo = new GenericAggregateRepository(TinyOrder::class, $strategy);
        $aggregate = TinyOrder::createBlank(self::newId());

        try {
            $repo->save($aggregate);
            self::fail('Expected AggregateAlreadyExistsException to propagate.');
        } catch (AggregateAlreadyExistsException $caught) {
            self::assertSame($alreadyExists, $caught);
        }

        $optimisticLock = new OptimisticLockException(TinyOrder::class, 'order-2', 3, 5);
        $strategy2 = new RecordingPersistenceStrategy(Option::none(), persistThrows: $optimisticLock);
        $repo2 = new GenericAggregateRepository(TinyOrder::class, $strategy2);
        $loaded = TinyOrder::createBlank(self::newId());
        new AggregateRootAccessor()->rehydrateVersionOn($loaded, 3);

        try {
            $repo2->save($loaded);
            self::fail('Expected OptimisticLockException to propagate.');
        } catch (OptimisticLockException $caught) {
            self::assertSame($optimisticLock, $caught);
        }
    }

    private static function newId(): TestUlidId
    {
        return new TestUlidId(new Ulid()->toBase32());
    }
}

interface TinyOrderEvent extends DomainEvent {}

final readonly class TinyOrderPlaced implements TinyOrderEvent
{
    public function __construct(public string $orderId) {}
}

/** @extends EventSourcedAggregateRoot<TestUlidId, TinyOrderEvent> */
final class TinyOrder extends EventSourcedAggregateRoot
{
    public static function placeNew(TestUlidId $id): self
    {
        $order = new self($id);
        $order->recordThat(new TinyOrderPlaced($id->value()));

        return $order;
    }

    public static function createBlank(TestUlidId $id): self
    {
        return new self($id);
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
            $event instanceof TinyOrderPlaced => null,
            default => throw new LogicException('Unhandled event in TinyOrder: ' . $event::class),
        };
    }
}

final class RecordingPersistenceStrategy implements PersistenceStrategy
{
    public ?AggregateRoot $lastPersisted = null;
    public ?string $lastLoadedClass = null;
    public ?Identifier $lastLoadedId = null;

    /**
     * @param Option<AggregateRoot> $loadResult
     */
    public function __construct(private Option $loadResult, private ?Throwable $persistThrows = null) {}

    #[Override]
    public function load(string $entityClass, Identifier $id): Option
    {
        $this->lastLoadedClass = $entityClass;
        $this->lastLoadedId = $id;

        /** @var Option<AggregateRoot> */
        return $this->loadResult;
    }

    #[Override]
    public function persist(AggregateRoot $entity): void
    {
        if ($this->persistThrows !== null) {
            throw $this->persistThrows;
        }

        $this->lastPersisted = $entity;
    }
}
