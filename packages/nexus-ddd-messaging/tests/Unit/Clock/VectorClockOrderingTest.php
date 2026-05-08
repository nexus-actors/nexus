<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Clock;

use Monadial\Nexus\Ddd\Messaging\Clock\VectorClockOrdering;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VectorClockOrdering::class)]
final class VectorClockOrderingTest extends TestCase
{
    #[Test]
    public function enumExposesFourCases(): void
    {
        $cases = VectorClockOrdering::cases();
        self::assertCount(4, $cases);

        $names = array_map(static fn(VectorClockOrdering $c): string => $c->name, $cases);
        self::assertContains('HappensBefore', $names);
        self::assertContains('HappensAfter', $names);
        self::assertContains('Concurrent', $names);
        self::assertContains('Equal', $names);
    }
}
