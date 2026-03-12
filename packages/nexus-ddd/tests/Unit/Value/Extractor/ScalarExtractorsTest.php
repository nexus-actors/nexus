<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Tests\Unit\Value\Extractor;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Value\BoolValue;
use Monadial\Nexus\Ddd\Value\DateTimeValue;
use Monadial\Nexus\Ddd\Value\Extractor\ScalarBoolExtractor;
use Monadial\Nexus\Ddd\Value\Extractor\ScalarDateTimeExtractor;
use Monadial\Nexus\Ddd\Value\Extractor\ScalarFloatExtractor;
use Monadial\Nexus\Ddd\Value\Extractor\ScalarIntExtractor;
use Monadial\Nexus\Ddd\Value\Extractor\ScalarStringExtractor;
use Monadial\Nexus\Ddd\Value\Extractor\ScalarUlidExtractor;
use Monadial\Nexus\Ddd\Value\FloatValue;
use Monadial\Nexus\Ddd\Value\IntValue;
use Monadial\Nexus\Ddd\Value\StringValue;
use Monadial\Nexus\Ddd\Value\UlidValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(ScalarBoolExtractor::class)]
#[CoversClass(ScalarDateTimeExtractor::class)]
#[CoversClass(ScalarFloatExtractor::class)]
#[CoversClass(ScalarIntExtractor::class)]
#[CoversClass(ScalarStringExtractor::class)]
#[CoversClass(ScalarUlidExtractor::class)]
final class ScalarExtractorsTest extends TestCase
{
    #[Test]
    public function extractsString(): void
    {
        $value = new readonly class ('hello') extends StringValue {};

        self::assertSame('hello', ScalarStringExtractor::extract($value));
    }

    #[Test]
    public function extractsInt(): void
    {
        $value = new readonly class (42) extends IntValue {};

        self::assertSame(42, ScalarIntExtractor::extract($value));
    }

    #[Test]
    public function extractsFloat(): void
    {
        $value = new readonly class (3.14) extends FloatValue {};

        self::assertSame(3.14, ScalarFloatExtractor::extract($value));
    }

    #[Test]
    public function extractsBool(): void
    {
        $value = new readonly class (true) extends BoolValue {};

        self::assertTrue(ScalarBoolExtractor::extract($value));
    }

    #[Test]
    public function extractsUlid(): void
    {
        $ulid  = (string) new Ulid();
        $value = new readonly class ($ulid) extends UlidValue {};

        self::assertSame($ulid, ScalarUlidExtractor::extract($value));
    }

    #[Test]
    public function extractsDateTime(): void
    {
        $dt    = new DateTimeImmutable('2026-03-03T12:00:00+00:00');
        $value = new readonly class ($dt) extends DateTimeValue {};

        self::assertEquals($dt, ScalarDateTimeExtractor::extract($value));
    }
}
