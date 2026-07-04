<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Domain;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\ReleaseReservation;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\ReserveStock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restocked;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReleased;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReservationRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;

/**
 * DECIDE: command in, events (or a Rejection) out. Pure — the only place
 * inventory invariants live. An empty event list means "already done":
 * idempotent success.
 */
final class InventoryRules
{
    /**
     * @return list<object>|Rejection
     */
    public static function decide(ItemState $state, object $command): array|Rejection
    {
        return match (true) {
            $command instanceof ReserveStock => self::reserve($state, $command),
            $command instanceof ReleaseReservation => self::release($state, $command),
            $command instanceof Restock => self::restock($state, $command),
            default => new Rejection('Unknown command ' . $command::class),
        };
    }

    /**
     * @return list<object>|Rejection
     */
    private static function reserve(ItemState $state, ReserveStock $command): array|Rejection
    {
        // Idempotent: a reservation for this order already exists.
        if (isset($state->reservations[$command->orderId->value])) {
            return [];
        }

        if (!ReservationPolicy::allows($state, $command->quantity)) {
            return [
                new StockReservationRejected(
                    $command->tenantId,
                    $command->sku,
                    $command->orderId,
                    $command->quantity,
                    $state->available(),
                    'insufficient stock',
                ),
            ];
        }

        return [new StockReserved($command->tenantId, $command->sku, $command->orderId, $command->quantity)];
    }

    /**
     * @return list<object>
     */
    private static function release(ItemState $state, ReleaseReservation $command): array
    {
        // Idempotent: no reservation to release.
        if (!isset($state->reservations[$command->orderId->value])) {
            return [];
        }

        return [
            new StockReleased(
                $command->tenantId,
                $command->sku,
                $command->orderId,
                Quantity::of($state->reservations[$command->orderId->value]),
            ),
        ];
    }

    /**
     * @return list<object>
     */
    private static function restock(ItemState $state, Restock $command): array
    {
        return [new Restocked($command->tenantId, $command->sku, $command->quantity)];
    }
}
