<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

/**
 * Saga-internal event: the fulfillment process has started reserving stock.
 * No #[MessageType] attribute — Domain stays attribute-free; registered
 * explicitly in MessageTypes as 'fulfillment.started.v1'.
 *
 * @param non-empty-list<OrderLine> $lines
 */
final readonly class FulfillmentStarted
{
    /**
     * @param non-empty-list<OrderLine> $lines
     */
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
        public array $lines,
    ) {}
}
