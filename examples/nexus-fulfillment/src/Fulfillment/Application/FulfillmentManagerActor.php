<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Fulfillment\Application;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReservationRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;

/**
 * Stateless bus subscriber that routes fulfillment-relevant events to the
 * correct per-order saga actor. ProcessRefFactory transparently respawns
 * a passivated (or never-started) saga, which replays its journal before
 * processing the incoming event.
 *
 * At-most-once limitation: the ContextBus delivers in-process only. A crash
 * between a context persisting an event and publishing it to the bus loses
 * that event for live subscribers. The journal keeps it but this manager does
 * not replay from the journal. Journal-backed subscriptions and an outbox
 * pattern resolve this in the broker milestone.
 */
final class FulfillmentManagerActor
{
    public const string ACTOR_NAME = 'fulfillment-manager';

    /**
     * @psalm-suppress InvalidArgument -- manager is intentionally heterogeneous; T resolves as
     *                                    object union (OrderPlaced|StockReserved|StockReservationRejected)
     *                                    which Psalm cannot express as a single generic type parameter
     */
    public static function behavior(ProcessRefFactory $factory): Behavior
    {
        return Behavior::receive(
            static function (ActorContext $ctx, object $msg) use ($factory): Behavior {
                if ($msg instanceof OrderPlaced) {
                    $factory->of($msg->tenantId, $msg->orderId)->tell($msg);
                } elseif ($msg instanceof StockReserved) {
                    $factory->of($msg->tenantId, $msg->orderId)->tell($msg);
                } elseif ($msg instanceof StockReservationRejected) {
                    $factory->of($msg->tenantId, $msg->orderId)->tell($msg);
                }

                return Behavior::same();
            },
        );
    }
}
