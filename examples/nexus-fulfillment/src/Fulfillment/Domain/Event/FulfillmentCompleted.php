<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

/**
 * Saga-internal event: all SKUs have been successfully reserved.
 * Transitions the process to FulfillmentPhase::Completed (terminal).
 * Registered in MessageTypes as 'fulfillment.completed.v1'.
 */
final readonly class FulfillmentCompleted
{
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
    ) {}
}
