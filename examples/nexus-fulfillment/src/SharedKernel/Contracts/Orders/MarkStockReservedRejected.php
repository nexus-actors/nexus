<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\RejectionEvent;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Serialization\MessageType;

/**
 * Published rejection event: MarkStockReserved arrived in a state where it
 * cannot be applied (NotCreated or Cancelled). Persisted as an auditable fact.
 */
#[MessageType('orders.mark_stock_reserved_rejected.v1')]
final readonly class MarkStockReservedRejected implements RejectionEvent
{
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
        public string $reason,
    ) {}

    #[\Override]
    public function reason(): string
    {
        return $this->reason;
    }
}
