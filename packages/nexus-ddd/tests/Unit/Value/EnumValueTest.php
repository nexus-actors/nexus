<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Value\EnumValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

enum Colour
{
    case Blue;
    case Red;
}

#[CoversClass(EnumValue::class)]
final class EnumValueTest extends TestCase
{
    #[Test]
    public function toStringReturnsEnumName(): void
    {
        $value = new readonly class (Colour::Red) extends EnumValue {};

        self::assertSame('Red', (string) $value);
    }

    #[Test]
    public function equalsReturnsTrueForSameCase(): void
    {
        $a = new readonly class (Colour::Red) extends EnumValue {};
        $b = new readonly class (Colour::Red) extends EnumValue {};

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentCase(): void
    {
        $a = new readonly class (Colour::Red) extends EnumValue {};
        $b = new readonly class (Colour::Blue) extends EnumValue {};

        self::assertFalse($a->equals($b));
    }
}
