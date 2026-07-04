<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Domain;

use LogicException;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\CancelOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\MarkStockReserved;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\PlaceOrder;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderStockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;

use function array_reduce;

/**
 * DECIDE: command in, events (or a Rejection) out. Pure — the only place
 * order invariants live. An empty event list means "already done":
 * idempotent success.
 */
final class OrderRules
{
    /**
     * @return list<object>|Rejection
     */
    public static function decide(OrderState $state, object $command): array|Rejection
    {
        return match (true) {
            $command instanceof CancelOrder => self::cancel($state, $command),
            $command instanceof MarkStockReserved => self::markStockReserved($state, $command),
            $command instanceof PlaceOrder => self::place($state, $command),
            default => new Rejection('Unknown command ' . $command::class),
        };
    }

    /**
     * @return list<object>|Rejection
     */
    private static function place(OrderState $state, PlaceOrder $command): array|Rejection
    {
        return match ($state->status) {
            OrderStatus::NotCreated => [
                new OrderPlaced($command->tenantId, $command->orderId, $command->lines, self::total($command->lines)),
            ],
            OrderStatus::Placed,
            OrderStatus::StockReserved => [],
            OrderStatus::Cancelled => new Rejection('Order was cancelled; place a new order instead'),
        };
    }

    /**
     * @return list<object>|Rejection
     */
    private static function cancel(OrderState $state, CancelOrder $command): array|Rejection
    {
        return match ($state->status) {
            OrderStatus::NotCreated => new Rejection('Order does not exist'),
            OrderStatus::Placed => [new OrderCancelled($command->tenantId, $command->orderId, $command->reason)],
            OrderStatus::StockReserved => new Rejection('cancellation after stock reservation arrives in milestone 4'),
            OrderStatus::Cancelled => [],
        };
    }

    /**
     * @return list<object>|Rejection
     */
    private static function markStockReserved(OrderState $state, MarkStockReserved $command): array|Rejection
    {
        return match ($state->status) {
            OrderStatus::Placed => [new OrderStockReserved($command->tenantId, $command->orderId)],
            OrderStatus::StockReserved => [],
            OrderStatus::NotCreated,
            OrderStatus::Cancelled => new Rejection('Cannot mark stock reserved for order in status ' . $state->status->value),
        };
    }

    /**
     * @param non-empty-list<OrderLine> $lines
     */
    private static function total(array $lines): Money
    {
        return array_reduce(
            $lines,
            static fn(?Money $carry, OrderLine $line): Money => $carry === null
                ? $line->total()
                : $carry->add($line->total()),
            null,
        ) ?? throw new LogicException('non-empty-list guarantees at least one line');
    }
}
