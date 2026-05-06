<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Core\Value\ArrayValue;
use Monadial\Nexus\Ddd\Core\Value\BoolValue;
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
        $v = new class('hello') extends StringValue {};
        self::assertSame('hello', $v->value());
    }

    #[Test]
    public function intValueWrapsInt(): void
    {
        $v = new class(42) extends IntValue {};
        self::assertSame(42, $v->value());
    }

    #[Test]
    public function floatValueWrapsFloat(): void
    {
        $v = new class(3.14) extends FloatValue {};
        self::assertSame(3.14, $v->value());
    }

    #[Test]
    public function boolValueWrapsBool(): void
    {
        $v = new class(true) extends BoolValue {};
        self::assertSame(true, $v->value());
    }

    #[Test]
    public function arrayValueWrapsArray(): void
    {
        $v = new class([1, 2, 3]) extends ArrayValue {};
        self::assertSame([1, 2, 3], $v->value());
    }

    #[Test]
    public function stringValueMapPreservesType(): void
    {
        $v = new class('abc') extends StringValue {};
        $mapped = $v->map(strtoupper(...));
        self::assertSame('ABC', $mapped->value());
        self::assertInstanceOf($v::class, $mapped);
    }
}
