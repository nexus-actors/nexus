<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Clock;

use Monadial\Nexus\Ddd\Messaging\Clock\VectorClock;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VectorClock::class)]
final class VectorClockMergeTest extends TestCase
{
    #[Test]
    public function mergeTakesPointwiseMaxAcrossNodes(): void
    {
        $a = NodeId::generate();
        $b = NodeId::generate();

        $left = VectorClock::empty()->tick($a)->tick($a)->tick($b);
        $right = VectorClock::empty()->tick($a)->tick($b)->tick($b);

        $merged = $left->merge($right);

        self::assertSame(2, $merged->counters[$a->value()]);
        self::assertSame(2, $merged->counters[$b->value()]);
    }

    #[Test]
    public function mergeIsCommutative(): void
    {
        $a = NodeId::generate();
        $b = NodeId::generate();

        $left = VectorClock::empty()->tick($a)->tick($a);
        $right = VectorClock::empty()->tick($b)->tick($b)->tick($a);

        self::assertEquals($left->merge($right)->counters, $right->merge($left)->counters);
    }

    #[Test]
    public function mergeIsAssociative(): void
    {
        $a = NodeId::generate();
        $b = NodeId::generate();
        $c = NodeId::generate();

        $x = VectorClock::empty()->tick($a)->tick($a);
        $y = VectorClock::empty()->tick($b)->tick($a);
        $z = VectorClock::empty()->tick($c);

        $left = $x->merge($y)->merge($z);
        $right = $x->merge($y->merge($z));

        self::assertEquals($left->counters, $right->counters);
    }

    #[Test]
    public function mergeIsIdempotent(): void
    {
        $a = NodeId::generate();
        $clock = VectorClock::empty()->tick($a)->tick($a)->tick($a);
        self::assertEquals($clock->counters, $clock->merge($clock)->counters);
    }

    #[Test]
    public function mergeKeepsExclusiveEntriesFromBothSides(): void
    {
        $a = NodeId::generate();
        $b = NodeId::generate();

        $left = VectorClock::empty()->tick($a);
        $right = VectorClock::empty()->tick($b);

        $merged = $left->merge($right);
        self::assertSame(1, $merged->counters[$a->value()]);
        self::assertSame(1, $merged->counters[$b->value()]);
    }
}
