<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Value\FloatValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FloatValue::class)]
final class FloatValueTest extends TestCase
{
    #[Test]
    public function toStringReturnsWrappedValue(): void
    {
        $value = new readonly class (3.14) extends FloatValue {};

        self::assertSame('3.14', (string) $value);
    }

    #[Test]
    public function equalsReturnsTrueForSameValue(): void
    {
        $a = new readonly class (1.5) extends FloatValue {};
        $b = new readonly class (1.5) extends FloatValue {};

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentValue(): void
    {
        $a = new readonly class (1.5) extends FloatValue {};
        $b = new readonly class (2.5) extends FloatValue {};

        self::assertFalse($a->equals($b));
    }
}
