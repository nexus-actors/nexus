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
        $v = new readonly class('hello') extends StringValue {
            public function asString(): string
            {
                /** @var string $v */
                $v = $this->getValue();

                return $v;
            }
        };
        self::assertSame('hello', $v->asString());
    }

    #[Test]
    public function intValueWrapsInt(): void
    {
        $v = new readonly class(42) extends IntValue {
            public function asInt(): int
            {
                /** @var int $v */
                $v = $this->getValue();

                return $v;
            }
        };
        self::assertSame(42, $v->asInt());
    }

    #[Test]
    public function floatValueWrapsFloat(): void
    {
        $v = new readonly class(3.14) extends FloatValue {
            public function asFloat(): float
            {
                /** @var float $v */
                $v = $this->getValue();

                return $v;
            }
        };
        self::assertSame(3.14, $v->asFloat());
    }

    #[Test]
    public function boolValueWrapsBool(): void
    {
        $v = new readonly class(true) extends BoolValue {
            public function asBool(): bool
            {
                /** @var bool $v */
                $v = $this->getValue();

                return $v;
            }
        };
        self::assertTrue($v->asBool());
    }

    #[Test]
    public function arrayValueWrapsArray(): void
    {
        $v = new readonly class([1, 2, 3]) extends ArrayValue {
            /** @return array<array-key, mixed> */
            public function asArray(): array
            {
                /** @var array<array-key, mixed> $v */
                $v = $this->getValue();

                return $v;
            }
        };
        self::assertSame([1, 2, 3], $v->asArray());
    }

    #[Test]
    public function stringValueMapPreservesType(): void
    {
        $v = new readonly class('abc') extends StringValue {
            public function asString(): string
            {
                /** @var string $v */
                $v = $this->getValue();

                return $v;
            }
        };
        $mapped = $v->map(strtoupper(...));
        self::assertSame('ABC', $mapped->asString());
        self::assertInstanceOf($v::class, $mapped);
    }
}
