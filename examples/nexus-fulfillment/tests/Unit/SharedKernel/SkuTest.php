<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\SharedKernel;

use InvalidArgumentException;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sku::class)]
final class SkuTest extends TestCase
{
    #[Test]
    public function acceptsUppercaseAlphanumericWithDashes(): void
    {
        self::assertSame('WIDGET-42', Sku::fromString('WIDGET-42')->value);
    }

    #[Test]
    public function rejectsLowercase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sku::fromString('widget-42');
    }

    #[Test]
    public function rejectsTooShort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sku::fromString('AB');
    }

    #[Test]
    public function equalsComparesByValue(): void
    {
        self::assertTrue(Sku::fromString('WIDGET-42')->equals(Sku::fromString('WIDGET-42')));
        self::assertFalse(Sku::fromString('WIDGET-42')->equals(Sku::fromString('GADGET-7')));
    }
}
