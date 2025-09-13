<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit;

use Monadial\Nexus\Core\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Duration::class)]
final class DurationTest extends TestCase
{
    #[Test]
    public function createsFromSeconds(): void
    {
        $d = Duration::seconds(5);
        self::assertSame(5_000_000_000, $d->toNanos());
        self::assertSame(5_000, $d->toMillis());
        self::assertSame(5, $d->toSeconds());
    }

    #[Test]
    public function createsFromMillis(): void
    {
        $d = Duration::millis(1500);
        self::assertSame(1_500_000_000, $d->toNanos());
        self::assertSame(1500, $d->toMillis());
        self::assertSame(1, $d->toSeconds());
    }

    #[Test]
    public function createsFromMicros(): void
    {
        $d = Duration::micros(500);
        self::assertSame(500_000, $d->toNanos());
    }

    #[Test]
    public function createsFromNanos(): void
    {
        $d = Duration::nanos(42);
        self::assertSame(42, $d->toNanos());
    }

    #[Test]
    public function addsTwo(): void
    {
        $a = Duration::seconds(3);
        $b = Duration::millis(500);
        $result = $a->plus($b);

        self::assertSame(3500, $result->toMillis());
    }

    #[Test]
    public function subtractsTwo(): void
    {
        $a = Duration::seconds(5);
        $b = Duration::seconds(2);
        $result = $a->minus($b);

        self::assertSame(3, $result->toSeconds());
    }

    #[Test]
    public function multiplies(): void
    {
        $d = Duration::seconds(3);
        $result = $d->multipliedBy(4);

        self::assertSame(12, $result->toSeconds());
    }

    #[Test]
    public function divides(): void
    {
        $d = Duration::seconds(12);
        $result = $d->dividedBy(4);

        self::assertSame(3, $result->toSeconds());
    }

    #[Test]
    public function isImmutable(): void
    {
        $original = Duration::seconds(5);
        $modified = $original->plus(Duration::seconds(3));

        self::assertSame(5, $original->toSeconds());
        self::assertSame(8, $modified->toSeconds());
        self::assertNotSame($original, $modified);
    }

    #[Test]
    public function comparesEqual(): void
    {
        $a = Duration::millis(1000);
        $b = Duration::seconds(1);

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function comparesGreaterThan(): void
    {
        $a = Duration::seconds(5);
        $b = Duration::seconds(3);

        self::assertTrue($a->isGreaterThan($b));
        self::assertFalse($b->isGreaterThan($a));
    }

    #[Test]
    public function comparesLessThan(): void
    {
        $a = Duration::seconds(3);
        $b = Duration::seconds(5);

        self::assertTrue($a->isLessThan($b));
        self::assertFalse($b->isLessThan($a));
    }

    #[Test]
    public function convertsToFloat(): void
    {
        $d = Duration::millis(1500);
        self::assertEqualsWithDelta(1.5, $d->toSecondsFloat(), 0.001);
    }

    #[Test]
    public function toStringFormatsHumanReadable(): void
    {
        self::assertSame('5s', (string) Duration::seconds(5));
        self::assertSame('500ms', (string) Duration::millis(500));
        self::assertSame('100μs', (string) Duration::micros(100));
        self::assertSame('42ns', (string) Duration::nanos(42));
        self::assertSame('1s 500ms', (string) Duration::millis(1500));
    }

    #[Test]
    public function zeroIsValid(): void
    {
        $d = Duration::zero();
        self::assertSame(0, $d->toNanos());
        self::assertTrue($d->isZero());
    }

    #[Test]
    public function nonZeroIsNotZero(): void
    {
        $d = Duration::nanos(1);
        self::assertFalse($d->isZero());
    }
}
