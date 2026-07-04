<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Serialization\MessageType;

/**
 * Published command: the saga (or another context) asks Inventory to
 * reserve stock for an order. The orderId doubles as the idempotency key.
 */
#[MessageType('inventory.reserve_stock.v1')]
final readonly class ReserveStock
{
    public function __construct(
        public TenantId $tenantId,
        public Sku $sku,
        public OrderId $orderId,
        public Quantity $quantity,
    ) {}
}
