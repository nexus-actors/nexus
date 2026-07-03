<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

/**
 * The client supplies the OrderId (a ULID) — it doubles as the
 * idempotency key: retrying the same id is safe by construction.
 */
final readonly class PlaceOrder
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
