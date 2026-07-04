<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\Http;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;

final readonly class PlaceOrderRequest
{
    /**
     * @param non-empty-list<OrderLine> $lines
     */
    public function __construct(
        public OrderId $orderId,
        public array $lines,
    ) {}
}
