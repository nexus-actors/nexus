<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Domain;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

/**
 * EVOLVE: the fold of the event log. Pure — no clock, no I/O, no context.
 */
final readonly class OrderState
{
    /**
     * @param list<OrderLine> $lines
     */
    private function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
        public OrderStatus $status,
        public array $lines,
        public ?Money $total,
        public ?string $cancelReason,
    ) {}

    public static function empty(TenantId $tenantId, OrderId $orderId): self
    {
        return new self($tenantId, $orderId, OrderStatus::NotCreated, [], null, null);
    }

    public static function evolve(self $state, object $event): self
    {
        return match (true) {
            $event instanceof OrderPlaced => new self(
                $state->tenantId,
                $state->orderId,
                OrderStatus::Placed,
                $event->lines,
                $event->total,
                null,
            ),
            $event instanceof OrderCancelled => new self(
                $state->tenantId,
                $state->orderId,
                OrderStatus::Cancelled,
                $state->lines,
                $state->total,
                $event->reason,
            ),
            default => $state,
        };
    }
}
