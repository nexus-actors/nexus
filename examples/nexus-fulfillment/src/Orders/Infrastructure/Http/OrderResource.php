<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\Http;

use Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\ReadModel\OrderView;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;

final readonly class OrderResource
{
    public function __construct(
        public ?string $cancelReason,
        public int $lineCount,
        public OrderId $orderId,
        public string $status,
        public ?Money $total,
        public string $updatedAt,
    ) {}

    public static function fromView(OrderView $view): self
    {
        return new self(
            cancelReason: $view->cancelReason,
            lineCount: $view->lineCount,
            orderId: new OrderId($view->id),
            status: $view->status,
            total: Money::of($view->totalAmount, $view->currency),
            updatedAt: $view->updatedAt->format('c'),
        );
    }
}
