<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Value\Extractor;

use Monadial\Nexus\Ddd\Core\Value\ArrayValue;
use Monadial\Nexus\Ddd\Core\Value\BoolValue;
use Monadial\Nexus\Ddd\Core\Value\Extractor\ArrayExtractor;
use Monadial\Nexus\Ddd\Core\Value\Extractor\BoolExtractor;
use Monadial\Nexus\Ddd\Core\Value\Extractor\FloatExtractor;
use Monadial\Nexus\Ddd\Core\Value\Extractor\IntExtractor;
use Monadial\Nexus\Ddd\Core\Value\Extractor\StringExtractor;
use Monadial\Nexus\Ddd\Core\Value\Extractor\UlidExtractor;
use Monadial\Nexus\Ddd\Core\Value\Extractor\UuidExtractor;
use Monadial\Nexus\Ddd\Core\Value\Extractor\ValueExtractor;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUuidId;
use Monadial\Nexus\Ddd\Core\Value\FloatValue;
use Monadial\Nexus\Ddd\Core\Value\IntValue;
use Monadial\Nexus\Ddd\Core\Value\StringValue;
use Monadial\Nexus\Ddd\Core\Value\WrappedValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ValueExtractor::class)]
#[CoversClass(StringExtractor::class)]
#[CoversClass(IntExtractor::class)]
#[CoversClass(FloatExtractor::class)]
#[CoversClass(BoolExtractor::class)]
#[CoversClass(ArrayExtractor::class)]
#[CoversClass(UlidExtractor::class)]
#[CoversClass(UuidExtractor::class)]
final class ExtractorTest extends TestCase
{
    #[Test]
    public function genericValueExtractorReadsAnyWrappedValue(): void
    {
        $email = new readonly class('alice@example.com') extends StringValue {};
        /** @var string $raw */
        $raw = ValueExtractor::extract($email);
        self::assertSame('alice@example.com', $raw);
    }

    #[Test]
    public function stringExtractor(): void
    {
        $v = new readonly class('hello') extends StringValue {};
        self::assertSame('hello', StringExtractor::extract($v));
    }

    #[Test]
    public function intExtractor(): void
    {
        $v = new readonly class(42) extends IntValue {};
        self::assertSame(42, IntExtractor::extract($v));
    }

    #[Test]
    public function floatExtractor(): void
    {
        $v = new readonly class(3.14) extends FloatValue {};
        self::assertSame(3.14, FloatExtractor::extract($v));
    }

    #[Test]
    public function boolExtractor(): void
    {
        $v = new readonly class(true) extends BoolValue {};
        self::assertTrue(BoolExtractor::extract($v));
    }

    #[Test]
    public function arrayExtractorPreservesTemplateTypes(): void
    {
        /** @extends ArrayValue<int, int> */
        $v = new readonly class([1, 2, 3]) extends ArrayValue {};
        self::assertSame([1, 2, 3], ArrayExtractor::extract($v));

        /** @extends ArrayValue<string, string> */
        $named = new readonly class(['first' => 'Ada', 'last' => 'Lovelace']) extends ArrayValue {};
        self::assertSame(['first' => 'Ada', 'last' => 'Lovelace'], ArrayExtractor::extract($named));
    }

    #[Test]
    public function ulidExtractor(): void
    {
        $ulid = (new Ulid())->toBase32();
        $v = new TestUlidId($ulid);
        self::assertSame($ulid, UlidExtractor::extract($v));
    }

    #[Test]
    public function uuidExtractor(): void
    {
        $uuid = (string) Uuid::v7();
        $v = new TestUuidId($uuid);
        self::assertSame($uuid, UuidExtractor::extract($v));
    }

    #[Test]
    public function valueExtractorIsAlsoAWrappedValueByInheritanceTrick(): void
    {
        // Confirms the access trick: ValueExtractor extends WrappedValue,
        // which is what gives it permission to call protected getValue().
        self::assertTrue(is_subclass_of(ValueExtractor::class, WrappedValue::class));
    }
}
