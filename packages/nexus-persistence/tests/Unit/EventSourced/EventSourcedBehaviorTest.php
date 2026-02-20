<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\EventSourced;

use Closure;
use LogicException;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\EventSourced\RetentionPolicy;
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\InMemorySnapshotStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(EventSourcedBehavior::class)]
final class EventSourcedBehaviorTest extends TestCase
{
    private PersistenceId $persistenceId;
    private Closure $commandHandler;
    private Closure $eventHandler;
    private stdClass $emptyState;

    #[Test]
    public function createReturnsEventSourcedBehaviorInstance(): void
    {
        $builder = EventSourcedBehavior::create(
            $this->persistenceId,
            $this->emptyState,
            $this->commandHandler,
            $this->eventHandler,
        );

        self::assertInstanceOf(EventSourcedBehavior::class, $builder);
    }

    #[Test]
    public function withEventStoreReturnsNewImmutableInstance(): void
    {
        $original = EventSourcedBehavior::create(
            $this->persistenceId,
            $this->emptyState,
            $this->commandHandler,
            $this->eventHandler,
        );

        $withStore = $original->withEventStore(new InMemoryEventStore());

        self::assertInstanceOf(EventSourcedBehavior::class, $withStore);
        self::assertNotSame($original, $withStore);
    }

    #[Test]
    public function withSnapshotStoreReturnsNewImmutableInstance(): void
    {
        $original = EventSourcedBehavior::create(
            $this->persistenceId,
            $this->emptyState,
            $this->commandHandler,
            $this->eventHandler,
        );

        $withStore = $original->withSnapshotStore(new InMemorySnapshotStore());

        self::assertInstanceOf(EventSourcedBehavior::class, $withStore);
        self::assertNotSame($original, $withStore);
    }

    #[Test]
    public function withSnapshotStrategyReturnsNewImmutableInstance(): void
    {
        $original = EventSourcedBehavior::create(
            $this->persistenceId,
            $this->emptyState,
            $this->commandHandler,
            $this->eventHandler,
        );

        $withStrategy = $original->withSnapshotStrategy(SnapshotStrategy::everyN(100));

        self::assertInstanceOf(EventSourcedBehavior::class, $withStrategy);
        self::assertNotSame($original, $withStrategy);
    }

    #[Test]
    public function withRetentionReturnsNewImmutableInstance(): void
    {
        $original = EventSourcedBehavior::create(
            $this->persistenceId,
            $this->emptyState,
            $this->commandHandler,
            $this->eventHandler,
        );

        $withRetention = $original->withRetention(RetentionPolicy::none());

        self::assertInstanceOf(EventSourcedBehavior::class, $withRetention);
        self::assertNotSame($original, $withRetention);
    }

    #[Test]
    public function toBehaviorReturnsBehaviorWhenEventStoreIsSet(): void
    {
        $behavior = EventSourcedBehavior::create(
            $this->persistenceId,
            $this->emptyState,
            $this->commandHandler,
            $this->eventHandler,
        )
            ->withEventStore(new InMemoryEventStore())
            ->toBehavior();

        self::assertInstanceOf(Behavior::class, $behavior);
    }

    #[Test]
    public function toBehaviorThrowsLogicExceptionWhenEventStoreIsNotSet(): void
    {
        $builder = EventSourcedBehavior::create(
            $this->persistenceId,
            $this->emptyState,
            $this->commandHandler,
            $this->eventHandler,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('EventStore is required');

        $builder->toBehavior();
    }

    #[Test]
    public function fullBuilderChainReturnsBehavior(): void
    {
        $behavior = EventSourcedBehavior::create(
            $this->persistenceId,
            $this->emptyState,
            $this->commandHandler,
            $this->eventHandler,
        )
            ->withEventStore(new InMemoryEventStore())
            ->withSnapshotStore(new InMemorySnapshotStore())
            ->withSnapshotStrategy(SnapshotStrategy::everyN(100))
            ->withRetention(RetentionPolicy::snapshotAndEvents(3, deleteEventsTo: true))
            ->toBehavior();

        self::assertInstanceOf(Behavior::class, $behavior);
    }

    protected function setUp(): void
    {
        $this->persistenceId = PersistenceId::of('TestEntity', 'test-1');
        $this->emptyState = new stdClass();
        $this->commandHandler = static fn(object $state, ActorContext $ctx, object $msg): Effect => Effect::none();
        $this->eventHandler = static fn(object $state, object $event): object => $state;
    }
}
