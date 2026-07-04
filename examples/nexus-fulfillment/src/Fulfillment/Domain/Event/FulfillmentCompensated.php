<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

/**
 * Saga-internal event: the process is compensating — releasing confirmed
 * reservations and cancelling the order. Transitions to
 * FulfillmentPhase::Compensated (terminal).
 * Registered in MessageTypes as 'fulfillment.compensated.v1'.
 */
final readonly class FulfillmentCompensated
{
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
        public string $reason,
    ) {}
}
