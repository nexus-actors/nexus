<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Inventory\Domain;

use Monadial\Nexus\Example\Fulfillment\Inventory\Domain\InventoryItem;
use Monadial\Nexus\Example\Fulfillment\Inventory\Domain\ReservationPolicy;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restocked;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReservationPolicy::class)]
final class ReservationPolicyTest extends TestCase
{
    #[Test]
    public function exactlyAvailableQuantityIsAllowed(): void
    {
        $tenant = TenantId::fromString('acme');
        $sku = Sku::fromString('WIDGET-42');
        $orderId = OrderId::generate();
        $item = InventoryItem::empty($tenant, $sku);
        $item->apply(new Restocked($tenant, $sku, Quantity::of(10)));
        $item->apply(new StockReserved($tenant, $sku, $orderId, Quantity::of(3)));
        // available = 10 - 3 = 7

        self::assertTrue(ReservationPolicy::allows($item, Quantity::of(7)));
    }

    #[Test]
    public function oneMoreThanAvailableIsRejected(): void
    {
        $tenant = TenantId::fromString('acme');
        $sku = Sku::fromString('WIDGET-42');
        $orderId = OrderId::generate();
        $item = InventoryItem::empty($tenant, $sku);
        $item->apply(new Restocked($tenant, $sku, Quantity::of(10)));
        $item->apply(new StockReserved($tenant, $sku, $orderId, Quantity::of(3)));
        // available = 10 - 3 = 7

        self::assertFalse(ReservationPolicy::allows($item, Quantity::of(8)));
    }
}
