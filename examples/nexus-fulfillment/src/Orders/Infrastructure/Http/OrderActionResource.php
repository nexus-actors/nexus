<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\Http;

use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderAccepted;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;

final readonly class OrderActionResource
{
    public function __construct(
        public OrderId $orderId,
        public string $status,
        public ?Money $total,
    ) {}

    public static function fromReply(OrderAccepted $reply): self
    {
        return new self($reply->orderId, $reply->status->value, $reply->total);
    }
}
