<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Envelope\Stamp;

use Monadial\Nexus\Ddd\Messaging\Clock\VectorClock;
use Monadial\Nexus\Ddd\Messaging\Envelope\Stamp;
use Monadial\Nexus\Ddd\Messaging\Envelope\Stamp\VectorClockStamp;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(VectorClockStamp::class)]
final class VectorClockStampTest extends TestCase
{
    #[Test]
    public function isFinalReadonlyImplementingStamp(): void
    {
        $reflection = new ReflectionClass(VectorClockStamp::class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertContains(Stamp::class, $reflection->getInterfaceNames());
    }

    #[Test]
    public function exposesClock(): void
    {
        $clock = VectorClock::empty()->tick(NodeId::generate());
        $stamp = new VectorClockStamp($clock);
        self::assertSame($clock, $stamp->clock);
    }
}
