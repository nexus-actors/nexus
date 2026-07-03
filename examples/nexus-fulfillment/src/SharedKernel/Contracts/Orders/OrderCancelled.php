<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Serialization\MessageType;

/**
 * Published language: an order was cancelled (customer request, or —
 * in later milestones — saga compensation).
 */
#[MessageType('orders.order_cancelled.v1')]
final readonly class OrderCancelled
{
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
        public string $reason,
    ) {}
}
