<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;

final readonly class OrderRejected
{
    public function __construct(
        public OrderId $orderId,
        public string $reason,
    ) {}
}
