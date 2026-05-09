<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate;

use LogicException;
use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRootAccessor;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(AggregateRootAccessor::class)]
final class AggregateRootAccessorTest extends TestCase
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    private AggregateRootAccessor $accessor;

    #[Test]
    public function idThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('friend-class accessor');

        $this->accessor->id();
    }

    #[Test]
    public function popRecordedEventsDrainsBufferOnStatefulAggregate(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $c = StatefulCustomer::register($id, 'alice');

        $events = $this->accessor->popRecordedEventsFrom($c);
        self::assertCount(1, $events);

        self::assertCount(0, $this->accessor->popRecordedEventsFrom($c));
    }

    #[Test]
    public function extractVersionReadsCurrentVersionOnStatefulAggregate(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $c = StatefulCustomer::register($id, 'alice');

        self::assertSame(1, $this->accessor->extractVersion($c));
    }

    #[Test]
    public function rehydrateVersionOnSetsRevisionOnStatefulAggregate(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $c = StatefulCustomer::register($id, 'alice');
        $this->accessor->rehydrateVersionOn($c, 42);

        self::assertSame(42, $c->version());
    }

    #[Test]
    public function popRecordedEventsDrainsBufferOnEventSourcedAggregate(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = EsAggregate::create($id);
        $a->incrementBy(1);
        $a->incrementBy(2);

        self::assertCount(2, $this->accessor->popRecordedEventsFrom($a));
        self::assertCount(0, $this->accessor->popRecordedEventsFrom($a));
    }

    #[Override]
    protected function setUp(): void
    {
        $this->accessor = new AggregateRootAccessor();
    }
}
