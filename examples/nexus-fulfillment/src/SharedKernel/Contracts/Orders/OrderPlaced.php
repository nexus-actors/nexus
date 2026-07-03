<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Serialization\MessageType;

/**
 * Published language: the Orders context announces that a customer placed
 * an order. Other contexts consume this contract — never Orders internals.
 */
#[MessageType('orders.order_placed.v1')]
final readonly class OrderPlaced
{
    /**
     * @param non-empty-list<OrderLine> $lines
     */
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
        public array $lines,
        public Money $total,
    ) {}
}
