<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

final readonly class CancelOrder
{
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
        public string $reason,
    ) {}
}
