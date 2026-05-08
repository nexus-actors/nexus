<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate;

use LogicException;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRootAccessor;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(EventSourcedAggregateRootAccessor::class)]
final class EventSourcedAggregateRootAccessorTest extends TestCase
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    private EventSourcedAggregateRootAccessor $accessor;

    #[Test]
    public function idThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('friend-class accessor');

        $this->accessor->id();
    }

    #[Test]
    public function replayOnAppliesEventsAndAdvancesVersion(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = EsAggregate::create($id);

        $this->accessor->replayOn($a, [new Incremented(3), new Incremented(4)]);

        self::assertSame(7, $a->total);
        self::assertSame(2, $a->version());
    }

    #[Test]
    public function replayOnDoesNotLeaveEventsInBuffer(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = EsAggregate::create($id);
        $this->accessor->replayOn($a, [new Incremented(5)]);

        self::assertCount(0, $this->accessor->popRecordedEventsFrom($a));
    }

    #[Test]
    public function popRecordedEventsDrainsBuffer(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = EsAggregate::create($id);
        $a->incrementBy(10);
        $a->incrementBy(20);

        $events = $this->accessor->popRecordedEventsFrom($a);
        self::assertCount(2, $events);

        self::assertCount(0, $this->accessor->popRecordedEventsFrom($a));
    }

    #[Test]
    public function extractVersionReadsCurrentVersion(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = EsAggregate::create($id);
        $a->incrementBy(1);
        $a->incrementBy(1);

        self::assertSame(2, $this->accessor->extractVersion($a));
    }

    #[Test]
    public function rehydrateVersionOnSetsRevision(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = EsAggregate::create($id);
        $this->accessor->rehydrateVersionOn($a, 99);

        self::assertSame(99, $a->version());
    }

    #[Override]
    protected function setUp(): void
    {
        $this->accessor = new EventSourcedAggregateRootAccessor();
    }
}
