<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply;

use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderStatus;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;

final readonly class OrderAccepted
{
    public function __construct(
        public OrderId $orderId,
        public OrderStatus $status,
        public ?Money $total,
    ) {}
}
