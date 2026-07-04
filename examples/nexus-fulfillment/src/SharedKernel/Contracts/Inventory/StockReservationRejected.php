<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\RejectionEvent;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Serialization\MessageType;
use Override;

/**
 * Published event: a reservation attempt was rejected. This is a persisted
 * domain fact (audit trail) — its apply() fold is a deliberate no-op.
 *
 * Note: $available is a plain int (can be 0); Quantity cannot be 0.
 */
#[MessageType('inventory.stock_reservation_rejected.v1')]
final readonly class StockReservationRejected implements RejectionEvent
{
    public function __construct(
        public TenantId $tenantId,
        public Sku $sku,
        public OrderId $orderId,
        public Quantity $requested,
        public int $available,
        public string $reason,
    ) {}

    #[Override]
    public function reason(): string
    {
        return $this->reason;
    }
}
