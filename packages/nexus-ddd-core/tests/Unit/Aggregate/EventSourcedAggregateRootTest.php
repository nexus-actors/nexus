<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Aggregate\Internal\ApplyDispatcher;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(EventSourcedAggregateRoot::class)]
final class EventSourcedAggregateRootTest extends TestCase
{
    private ApplyDispatcher $dispatcher;

    #[Test]
    public function eventSourcedAggregateIsEventSourceable(): void
    {
        $a = EsAggregate::create(new TestUlidId((new Ulid())->toBase32()), $this->dispatcher);
        self::assertInstanceOf(EventSourceable::class, $a);
    }

    #[Test]
    public function replayReconstructsStateFromEvents(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = EsAggregate::create($id, $this->dispatcher);
        $a->incrementBy(5);
        $a->incrementBy(7);
        $events = $a->pullRecordedEvents();

        $rehydrated = EsAggregate::create($id, $this->dispatcher);
        $rehydrated->replay($events);

        self::assertSame(12, $rehydrated->total);
        self::assertSame(2, $rehydrated->version());
    }

    #[Test]
    public function replayDoesNotRecord(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = EsAggregate::create($id, $this->dispatcher);
        $a->replay([new Incremented(3), new Incremented(2)]);

        self::assertCount(0, $a->pullRecordedEvents());
        self::assertSame(5, $a->total);
    }

    #[Test]
    public function rehydrateVersionSetsAggregateRevisionAndReplayContinuesFromThere(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = EsAggregate::createWithSnapshotState($id, total: 100, atRevision: 42, dispatcher: $this->dispatcher);

        self::assertSame(42, $a->version());
        self::assertSame(100, $a->total);

        $a->replay([new Incremented(5), new Incremented(7), new Incremented(3)]);

        self::assertSame(45, $a->version());
        self::assertSame(115, $a->total);
        self::assertCount(0, $a->pullRecordedEvents());
    }

    #[Test]
    public function dispatcherIsSharedAcrossAggregatesOfTheSameClass(): void
    {
        $shared = new ApplyDispatcher();
        $a = EsAggregate::create(new TestUlidId((new Ulid())->toBase32()), $shared);
        $b = EsAggregate::create(new TestUlidId((new Ulid())->toBase32()), $shared);

        $a->incrementBy(3);
        $b->incrementBy(4);

        self::assertSame(3, $a->total);
        self::assertSame(4, $b->total);
    }

    protected function setUp(): void
    {
        $this->dispatcher = new ApplyDispatcher();
    }
}

/** @extends EventSourcedAggregateRoot<TestUlidId, Incremented> */
final class EsAggregate extends EventSourcedAggregateRoot
{
    public int $total = 0;

    public static function create(TestUlidId $id, ApplyDispatcher $dispatcher): self
    {
        return new self($id, $dispatcher);
    }

    /**
     * Stand-in for a snapshot rehydration constructor: builds the aggregate
     * with state already populated, then sets version to the snapshot's
     * stream revision via the framework rehydration hook.
     */
    public static function createWithSnapshotState(TestUlidId $id, int $total, int $atRevision, ApplyDispatcher $dispatcher): self
    {
        $a = new self($id, $dispatcher);
        $a->total = $total;
        $a->rehydrateVersion($atRevision);

        return $a;
    }

    #[\Override]
    public function id(): TestUlidId
    {
        /** @var TestUlidId */
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

final readonly class Incremented implements DomainEvent
{
    public function __construct(public int $by) {}
}
