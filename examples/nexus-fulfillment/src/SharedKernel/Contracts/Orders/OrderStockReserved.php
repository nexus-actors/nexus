<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Serialization\MessageType;

/**
 * Published language: all stock lines for an order have been reserved by
 * the Inventory context. Emitted by the fulfillment saga once every item
 * replies with StockReserved; triggers the Orders context to advance the
 * order into the stock_reserved status.
 */
#[MessageType('orders.order_stock_reserved.v1')]
final readonly class OrderStockReserved
{
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
    ) {}
}
