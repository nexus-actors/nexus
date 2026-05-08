<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRootAccessor;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\Core\Exception\ApplyDuringReplayException;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(EventSourcedAggregateRoot::class)]
final class EventSourcedAggregateRootTest extends TestCase
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    private EventSourcedAggregateRootAccessor $accessor;

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
        $events = $this->accessor->popRecordedEventsFrom($a);

        $rehydrated = EsAggregate::create($id);
        $this->accessor->replayOn($rehydrated, $events);

        self::assertSame(12, $rehydrated->total);
        self::assertSame(2, $rehydrated->version());
    }

    #[Test]
    public function replayDoesNotRecord(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = EsAggregate::create($id);
        $this->accessor->replayOn($a, [new Incremented(3), new Incremented(2)]);

        self::assertCount(0, $this->accessor->popRecordedEventsFrom($a));
        self::assertSame(5, $a->total);
    }

    #[Test]
    public function rehydrateVersionSetsAggregateRevisionAndReplayContinuesFromThere(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = EsAggregate::createWithSnapshotState($id, total: 100);
        $this->accessor->rehydrateVersionOn($a, 42);

        self::assertSame(42, $a->version());
        self::assertSame(100, $a->total);

        $this->accessor->replayOn($a, [new Incremented(5), new Incremented(7), new Incremented(3)]);

        /** @psalm-suppress DocblockTypeContradiction — Psalm narrows to literal 42 from rehydrateVersionOn, but replay advances it */
        self::assertSame(45, $a->version());
        /** @psalm-suppress DocblockTypeContradiction — Psalm narrows to literal 100 from createWithSnapshotState, but replay advances it */
        self::assertSame(115, $a->total);
        self::assertCount(0, $this->accessor->popRecordedEventsFrom($a));
    }

    #[Test]
    public function recordThatFromInsideApplyDuringReplayThrows(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = MisbehavingAggregate::create($id);

        $this->expectException(ApplyDuringReplayException::class);
        $this->accessor->replayOn($a, [new MisbehavingEvent()]);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->accessor = new EventSourcedAggregateRootAccessor();
    }
}

/** @extends EventSourcedAggregateRoot<TestUlidId, Incremented> */
final class EsAggregate extends EventSourcedAggregateRoot
{
    public int $total = 0;

    public static function create(TestUlidId $id): self
    {
        return new self($id);
    }

    public static function createWithSnapshotState(TestUlidId $id, int $total): self
    {
        $a = new self($id);
        $a->total = $total;

        return $a;
    }

    #[Override]
    public function id(): TestUlidId
    {
        /** @var TestUlidId */
        return $this->id;
    }

    public function incrementBy(int $by): void
    {
        $this->recordThat(new Incremented($by));
    }

    #[Override]
    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof Incremented => $this->total += $event->by,
        };
    }
}

/** @psalm-immutable */
final readonly class Incremented implements DomainEvent
{
    public function __construct(public int $by) {}
}

/** @extends EventSourcedAggregateRoot<TestUlidId, MisbehavingEvent> */
final class MisbehavingAggregate extends EventSourcedAggregateRoot
{
    public static function create(TestUlidId $id): self
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
        $this->recordThat(new MisbehavingEvent());
    }
}

/** @psalm-immutable */
final readonly class MisbehavingEvent implements DomainEvent {}
