<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

/**
 * Saga-internal event: a single SKU's stock reservation was confirmed.
 * Registered in MessageTypes as 'fulfillment.reservation_confirmed.v1'.
 */
final readonly class ReservationConfirmed
{
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
        public Sku $sku,
    ) {}
}
