<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Tests\Unit\Value;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Value\DateTimeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DateTimeValue::class)]
final class DateTimeValueTest extends TestCase
{
    #[Test]
    public function toStringFormatsAsAtom(): void
    {
        $dt    = new DateTimeImmutable('2026-03-03T12:00:00+00:00');
        $value = new readonly class ($dt) extends DateTimeValue {};

        self::assertSame('2026-03-03T12:00:00+00:00', (string) $value);
    }

    #[Test]
    public function equalsReturnsTrueForSameMoment(): void
    {
        $dt = new DateTimeImmutable('2026-03-03T12:00:00+00:00');
        $a  = new readonly class ($dt) extends DateTimeValue {};
        $b  = new readonly class ($dt) extends DateTimeValue {};

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentMoment(): void
    {
        $a = new readonly class (new DateTimeImmutable('2026-01-01')) extends DateTimeValue {};
        $b = new readonly class (new DateTimeImmutable('2026-12-31')) extends DateTimeValue {};

        self::assertFalse($a->equals($b));
    }
}
