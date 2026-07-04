<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Serialization\MessageType;

/**
 * Published event: stock was successfully reserved for an order.
 */
#[MessageType('inventory.stock_reserved.v1')]
final readonly class StockReserved
{
    public function __construct(
        public TenantId $tenantId,
        public Sku $sku,
        public OrderId $orderId,
        public Quantity $quantity,
    ) {}
}
