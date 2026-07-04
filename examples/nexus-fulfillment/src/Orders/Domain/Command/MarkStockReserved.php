<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

/**
 * Sent by the fulfillment saga to the order entity after all stock lines
 * have been confirmed reserved. Idempotent: a second delivery on an
 * already-reserved order is a no-op.
 */
final readonly class MarkStockReserved
{
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
    ) {}
}
