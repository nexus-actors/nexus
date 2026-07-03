<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\SharedKernel;

use InvalidArgumentException;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Quantity::class)]
final class QuantityTest extends TestCase
{
    #[Test]
    public function acceptsPositiveInteger(): void
    {
        self::assertSame(3, Quantity::of(3)->value);
    }

    #[Test]
    public function rejectsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Quantity::of(0);
    }

    #[Test]
    public function rejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Quantity::of(-1);
    }

    #[Test]
    public function equalsComparesByValue(): void
    {
        self::assertTrue(Quantity::of(2)->equals(Quantity::of(2)));
        self::assertFalse(Quantity::of(2)->equals(Quantity::of(3)));
    }
}
