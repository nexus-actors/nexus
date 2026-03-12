<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Value\Exception\InvalidUlid;
use Monadial\Nexus\Ddd\Value\UlidValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UlidValue::class)]
final class UlidValueTest extends TestCase
{
    private const string VALID_ULID = '01HWKBS2EXHRFNX2JB1YZNV0FP';

    #[Test]
    public function toStringReturnsUlid(): void
    {
        $value = new readonly class (self::VALID_ULID) extends UlidValue {};

        self::assertSame(self::VALID_ULID, (string) $value);
    }

    #[Test]
    public function equalsReturnsTrueForSameUlid(): void
    {
        $a = new readonly class (self::VALID_ULID) extends UlidValue {};
        $b = new readonly class (self::VALID_ULID) extends UlidValue {};

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentUlid(): void
    {
        $a = new readonly class (self::VALID_ULID) extends UlidValue {};
        $b = new readonly class (self::VALID_ULID) extends UlidValue {
            public static function make(): static
            {
                return static::generate();
            }
        };

        self::assertFalse($a->equals($b::make()));
    }

    #[Test]
    public function generateCreatesValidUniqueUlid(): void
    {
        $first = new readonly class (self::VALID_ULID) extends UlidValue {
            public static function make(): static
            {
                return static::generate();
            }
        };

        $a = $first::make();
        $b = $first::make();

        self::assertNotSame((string) $a, (string) $b);
        self::assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', (string) $a);
    }

    #[Test]
    public function throwsOnInvalidUlid(): void
    {
        $this->expectException(InvalidUlid::class);

        new readonly class ('not-a-ulid') extends UlidValue {};
    }
}
