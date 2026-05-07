<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Core\Value\ArrayValue;
use Monadial\Nexus\Ddd\Core\Value\BoolValue;
use Monadial\Nexus\Ddd\Core\Value\Extractor\ArrayExtractor;
use Monadial\Nexus\Ddd\Core\Value\Extractor\BoolExtractor;
use Monadial\Nexus\Ddd\Core\Value\Extractor\FloatExtractor;
use Monadial\Nexus\Ddd\Core\Value\Extractor\IntExtractor;
use Monadial\Nexus\Ddd\Core\Value\Extractor\StringExtractor;
use Monadial\Nexus\Ddd\Core\Value\FloatValue;
use Monadial\Nexus\Ddd\Core\Value\IntValue;
use Monadial\Nexus\Ddd\Core\Value\StringValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StringValue::class)]
#[CoversClass(IntValue::class)]
#[CoversClass(FloatValue::class)]
#[CoversClass(BoolValue::class)]
#[CoversClass(ArrayValue::class)]
final class ConcreteWrappedValuesTest extends TestCase
{
    #[Test]
    public function stringValueWrapsString(): void
    {
        $v = new readonly class('hello') extends StringValue {};
        self::assertSame('hello', StringExtractor::extract($v));
    }

    #[Test]
    public function intValueWrapsInt(): void
    {
        $v = new readonly class(42) extends IntValue {};
        self::assertSame(42, IntExtractor::extract($v));
    }

    #[Test]
    public function floatValueWrapsFloat(): void
    {
        $v = new readonly class(3.14) extends FloatValue {};
        self::assertSame(3.14, FloatExtractor::extract($v));
    }

    #[Test]
    public function boolValueWrapsBool(): void
    {
        $v = new readonly class(true) extends BoolValue {};
        self::assertTrue(BoolExtractor::extract($v));
    }

    #[Test]
    public function arrayValueWrapsArrayWithTemplatedKeyAndValueTypes(): void
    {
        /** @extends ArrayValue<int, int> */
        $v = new readonly class([1, 2, 3]) extends ArrayValue {};
        self::assertSame([1, 2, 3], ArrayExtractor::extract($v));

        /** @extends ArrayValue<string, string> */
        $named = new readonly class(['first' => 'Ada', 'last' => 'Lovelace']) extends ArrayValue {};
        self::assertSame(['first' => 'Ada', 'last' => 'Lovelace'], ArrayExtractor::extract($named));
    }

    #[Test]
    public function stringValueMapPreservesType(): void
    {
        $v = new readonly class('abc') extends StringValue {};
        $mapped = $v->map(strtoupper(...));
        self::assertSame('ABC', StringExtractor::extract($mapped));
        self::assertInstanceOf($v::class, $mapped);
    }
}
