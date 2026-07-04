<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\ReadModel;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;

/**
 * One projector actor per read model (spec): consumes the ContextBus,
 * folds Orders events into orders_view. Restart-safe — upserts are
 * idempotent per event.
 */
final class OrdersViewProjector
{
    public const string ACTOR_NAME = 'orders-projector';

    /**
     * @psalm-suppress InvalidArgument -- bus subscribers are intentionally heterogeneous: the handler takes any object and filters
     */
    public static function behavior(OrdersReadModel $readModel): Behavior
    {
        return Behavior::receive(static function (ActorContext $ctx, object $event) use ($readModel): Behavior {
            if ($event instanceof OrderPlaced || $event instanceof OrderCancelled) {
                $readModel->apply($event);
            }

            return Behavior::same();
        });
    }
}
