<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Serialization\MessageType;

/**
 * Published command: the saga (or another context) asks Inventory to
 * release a previously held stock reservation for an order.
 */
#[MessageType('inventory.release_reservation.v1')]
final readonly class ReleaseReservation
{
    public function __construct(
        public TenantId $tenantId,
        public Sku $sku,
        public OrderId $orderId,
    ) {}
}
