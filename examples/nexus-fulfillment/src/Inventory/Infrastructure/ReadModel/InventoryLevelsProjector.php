<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Infrastructure\ReadModel;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restocked;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReleased;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;

/**
 * One projector actor per read model (spec): consumes the ContextBus,
 * folds Inventory events into inventory_levels. Restart-safe — reserve/release
 * folds are idempotent per order; restock increments on_hand.
 */
final class InventoryLevelsProjector
{
    public const string ACTOR_NAME = 'inventory-projector';

    /**
     * @psalm-suppress InvalidArgument -- bus subscribers are intentionally heterogeneous: the handler takes any object and filters
     */
    public static function behavior(InventoryReadModel $readModel): Behavior
    {
        return Behavior::receive(static function (ActorContext $ctx, object $event) use ($readModel): Behavior {
            if ($event instanceof Restocked || $event instanceof StockReleased || $event instanceof StockReserved) {
                $readModel->apply($event);
            }

            return Behavior::same();
        });
    }
}
