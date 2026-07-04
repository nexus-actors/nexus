<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Serialization\MessageType;

/**
 * Published event: a reservation was released and stock returned to the
 * available pool.
 */
#[MessageType('inventory.stock_released.v1')]
final readonly class StockReleased
{
    public function __construct(
        public TenantId $tenantId,
        public Sku $sku,
        public OrderId $orderId,
        public Quantity $quantity,
    ) {}
}
