<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

/**
 * Saga-internal event: a SKU's reservation attempt was rejected by inventory.
 * apply() is a no-op — state is unchanged; FulfillmentCompensated carries
 * the phase transition. Registered in MessageTypes as 'fulfillment.reservation_failed.v1'.
 */
final readonly class ReservationFailed
{
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
        public Sku $sku,
        public string $reason,
    ) {}
}
