<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(EventSourcedAggregateRoot::class)]
final class EventSourcedAggregateRootTest extends TestCase
{
    #[Test]
    public function eventSourcedAggregateIsEventSourceable(): void
    {
        $a = EsAggregate::create(new TestUlidId((new Ulid())->toBase32()));
        self::assertInstanceOf(EventSourceable::class, $a);
    }

    #[Test]
    public function replayReconstructsStateFromEvents(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
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
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = EsAggregate::create($id);
        $a->replay([new Incremented(3), new Incremented(2)]);

        self::assertCount(0, $a->pullRecordedEvents());
        self::assertSame(5, $a->total);
    }

    #[Test]
    public function rehydrateVersionSetsAggregateRevisionAndReplayContinuesFromThere(): void
    {
        // Simulates loading from a snapshot taken at revision 42, plus 3
        // events written after the snapshot.
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = EsAggregate::createWithSnapshotState($id, total: 100, atRevision: 42);

        self::assertSame(42, $a->version());
        self::assertSame(100, $a->total);

        $a->replay([new Incremented(5), new Incremented(7), new Incremented(3)]);

        self::assertSame(45, $a->version());        // 42 + 3 events
        self::assertSame(115, $a->total);           // 100 + 5 + 7 + 3
        self::assertCount(0, $a->pullRecordedEvents());
    }

    #[Test]
    public function setDispatcherSwapsTheStaticDispatcherAndReturnsThePrevious(): void
    {
        $custom = new \Monadial\Nexus\Ddd\Core\Aggregate\Internal\ApplyDispatcher();
        $previous = EsAggregate::setDispatcher($custom);

        try {
            $id = new TestUlidId((new Ulid())->toBase32());
            $a = EsAggregate::create($id);
            $a->incrementBy(2);
            self::assertSame(2, $a->total);
        } finally {
            EsAggregate::setDispatcher($previous);
        }
    }
}

final class EsAggregate extends EventSourcedAggregateRoot
{
    public int $total = 0;

    public static function create(Identifier $id): self
    {
        return new self($id);
    }

    /**
     * Stand-in for a snapshot rehydration constructor: builds the aggregate
     * with state already populated, then sets version to the snapshot's
     * stream revision via the framework rehydration hook.
     */
    public static function createWithSnapshotState(Identifier $id, int $total, int $atRevision): self
    {
        $a = new self($id);
        $a->total = $total;
        $a->rehydrateVersion($atRevision);

        return $a;
    }

    #[\Override]
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

final readonly class Incremented implements DomainEvent
{
    public function __construct(public int $by) {}
}
