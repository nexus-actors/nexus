<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Clock;

use Monadial\Nexus\Ddd\Messaging\Clock\VectorClock;
use Monadial\Nexus\Ddd\Messaging\Clock\VectorClockOrdering;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VectorClock::class)]
final class VectorClockCompareTest extends TestCase
{
    #[Test]
    public function reflexiveEqualToItself(): void
    {
        $a = NodeId::generate();
        $clock = VectorClock::empty()->tick($a)->tick($a);
        self::assertSame(VectorClockOrdering::Equal, $clock->compareTo($clock));
    }

    #[Test]
    public function emptyClocksAreEqual(): void
    {
        self::assertSame(
            VectorClockOrdering::Equal,
            VectorClock::empty()->compareTo(VectorClock::empty()),
        );
    }

    #[Test]
    public function strictPredecessorReportsHappensBefore(): void
    {
        $a = NodeId::generate();
        $earlier = VectorClock::empty()->tick($a);
        $later = $earlier->tick($a);
        self::assertSame(VectorClockOrdering::HappensBefore, $earlier->compareTo($later));
    }

    #[Test]
    public function strictSuccessorReportsHappensAfter(): void
    {
        $a = NodeId::generate();
        $earlier = VectorClock::empty()->tick($a);
        $later = $earlier->tick($a);
        self::assertSame(VectorClockOrdering::HappensAfter, $later->compareTo($earlier));
    }

    #[Test]
    public function antisymmetricBetweenStrictRelations(): void
    {
        $a = NodeId::generate();
        $earlier = VectorClock::empty()->tick($a);
        $later = $earlier->tick($a);

        self::assertSame(VectorClockOrdering::HappensBefore, $earlier->compareTo($later));
        self::assertSame(VectorClockOrdering::HappensAfter, $later->compareTo($earlier));
    }

    #[Test]
    public function disjointAdvancesAreConcurrent(): void
    {
        $a = NodeId::generate();
        $b = NodeId::generate();
        $left = VectorClock::empty()->tick($a);
        $right = VectorClock::empty()->tick($b);
        self::assertSame(VectorClockOrdering::Concurrent, $left->compareTo($right));
        self::assertSame(VectorClockOrdering::Concurrent, $right->compareTo($left));
    }

    #[Test]
    public function concurrentRelationIsSymmetric(): void
    {
        $a = NodeId::generate();
        $b = NodeId::generate();
        $left = VectorClock::empty()->tick($a)->tick($a)->tick($b);
        $right = VectorClock::empty()->tick($a)->tick($b)->tick($b);

        self::assertSame(VectorClockOrdering::Concurrent, $left->compareTo($right));
        self::assertSame(VectorClockOrdering::Concurrent, $right->compareTo($left));
    }
}
