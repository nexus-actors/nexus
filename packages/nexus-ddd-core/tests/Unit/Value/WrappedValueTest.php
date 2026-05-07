<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Core\Value\Extractor\ValueExtractor;
use Monadial\Nexus\Ddd\Core\Value\WrappedValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WrappedValue::class)]
final class WrappedValueTest extends TestCase
{
    #[Test]
    public function extractReturnsConstructedInner(): void
    {
        $v = new IntStub(42);
        self::assertSame(42, ValueExtractor::extract($v));
    }

    #[Test]
    public function mapTransformsAndReturnsNewInstance(): void
    {
        $v = new IntStub(2);
        $mapped = $v->map(static fn(int $i): int => $i * 3);
        self::assertSame(6, ValueExtractor::extract($mapped));
        self::assertNotSame($v, $mapped);
        self::assertSame(2, ValueExtractor::extract($v));   // original unchanged
    }

    #[Test]
    public function flatMapReturnsResultOfFn(): void
    {
        $v = new IntStub(2);
        $result = $v->flatMap(static fn(int $i): IntStub => new IntStub($i + 100));
        self::assertSame(102, ValueExtractor::extract($result));
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
}
