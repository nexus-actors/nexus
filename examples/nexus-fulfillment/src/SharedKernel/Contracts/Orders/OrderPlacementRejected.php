<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\RejectionEvent;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Serialization\MessageType;
use Override;

/**
 * Published rejection event: a PlaceOrder command was rejected because the
 * order was already cancelled. Persisted as an auditable fact.
 */
#[MessageType('orders.order_placement_rejected.v1')]
final readonly class OrderPlacementRejected implements RejectionEvent
{
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
        public string $reason,
    ) {}

    #[Override]
    public function reason(): string
    {
        return $this->reason;
    }
}
