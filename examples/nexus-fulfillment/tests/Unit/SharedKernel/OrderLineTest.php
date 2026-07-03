<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\SharedKernel;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderLine::class)]
final class OrderLineTest extends TestCase
{
    #[Test]
    public function totalIsUnitPriceTimesQuantity(): void
    {
        $line = new OrderLine(
            sku: Sku::fromString('WIDGET-42'),
            quantity: Quantity::of(3),
            unitPrice: Money::of(1999, 'EUR'),
        );

        self::assertTrue($line->total()->equals(Money::of(5997, 'EUR')));
    }
}
