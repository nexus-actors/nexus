<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Clock;

use Monadial\Nexus\Ddd\Messaging\Clock\VectorClock;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VectorClock::class)]
final class VectorClockTickTest extends TestCase
{
    #[Test]
    public function emptyHasNoCounters(): void
    {
        self::assertSame([], VectorClock::empty()->counters);
    }

    #[Test]
    public function tickIncrementsTheNodesCounterFromZero(): void
    {
        $node = NodeId::generate();
        $clock = VectorClock::empty()->tick($node);
        self::assertSame(1, $clock->counters[$node->value()]);
    }

    #[Test]
    public function tickIncrementsExistingNodeCounterMonotonically(): void
    {
        $node = NodeId::generate();
        $clock = VectorClock::empty()->tick($node)->tick($node)->tick($node);
        self::assertSame(3, $clock->counters[$node->value()]);
    }

    #[Test]
    public function tickIsImmutableAndReturnsNewInstance(): void
    {
        $node = NodeId::generate();
        $original = VectorClock::empty();
        $advanced = $original->tick($node);
        self::assertSame([], $original->counters);
        self::assertNotSame($original, $advanced);
    }

    #[Test]
    public function tickKeepsOtherNodesCountersUnchanged(): void
    {
        $a = NodeId::generate();
        $b = NodeId::generate();
        $clock = VectorClock::empty()->tick($a)->tick($a)->tick($b);
        self::assertSame(2, $clock->counters[$a->value()]);
        self::assertSame(1, $clock->counters[$b->value()]);
    }
}
