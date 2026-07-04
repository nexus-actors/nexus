<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Inventory\Domain;

use Monadial\Nexus\Example\Fulfillment\Inventory\Domain\ItemState;
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
        $state = ItemState::empty($tenant, $sku);
        $state = ItemState::evolve($state, new Restocked($tenant, $sku, Quantity::of(10)));
        $state = ItemState::evolve($state, new StockReserved($tenant, $sku, $orderId, Quantity::of(3)));
        // available = 10 - 3 = 7

        self::assertTrue(ReservationPolicy::allows($state, Quantity::of(7)));
    }

    #[Test]
    public function oneMoreThanAvailableIsRejected(): void
    {
        $tenant = TenantId::fromString('acme');
        $sku = Sku::fromString('WIDGET-42');
        $orderId = OrderId::generate();
        $state = ItemState::empty($tenant, $sku);
        $state = ItemState::evolve($state, new Restocked($tenant, $sku, Quantity::of(10)));
        $state = ItemState::evolve($state, new StockReserved($tenant, $sku, $orderId, Quantity::of(3)));
        // available = 10 - 3 = 7

        self::assertFalse(ReservationPolicy::allows($state, Quantity::of(8)));
    }
}
