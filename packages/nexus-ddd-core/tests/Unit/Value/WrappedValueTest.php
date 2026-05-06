<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Core\Value\WrappedValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WrappedValue::class)]
final class WrappedValueTest extends TestCase
{
    #[Test]
    public function asIntReturnsConstructedInner(): void
    {
        $v = new IntStub(42);
        self::assertSame(42, $v->asInt());
    }

    #[Test]
    public function mapTransformsAndReturnsNewInstance(): void
    {
        $v = new IntStub(2);
        $mapped = $v->map(static fn(int $i): int => $i * 3);
        self::assertSame(6, $mapped->asInt());
        self::assertNotSame($v, $mapped);
        self::assertSame(2, $v->asInt());           // original unchanged
    }

    #[Test]
    public function flatMapReturnsResultOfFn(): void
    {
        $v = new IntStub(2);
        $result = $v->flatMap(static fn(int $i): IntStub => new IntStub($i + 100));
        self::assertSame(102, $result->asInt());
    }

    #[Test]
    public function equalsRequiresSameClassAndValue(): void
    {
        $a = new IntStub(1);
        $b = new IntStub(1);
        $c = new IntStub(2);
        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}

/**
 * @extends WrappedValue<int>
 * @psalm-immutable
 */
final readonly class IntStub extends WrappedValue
{
    public function __construct(int $value)
    {
        parent::__construct($value);
    }

    public function asInt(): int
    {
        /** @var int $v */
        $v = $this->getValue();

        return $v;
    }
}
