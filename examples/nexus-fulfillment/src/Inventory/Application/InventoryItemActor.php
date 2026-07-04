<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Application;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\Reply\StockCommandAccepted;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\Reply\StockCommandRejected;
use Monadial\Nexus\Example\Fulfillment\Inventory\Domain\InventoryItem;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Bus\Publish;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\ReleaseReservation;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\ReserveStock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\RejectionEvent;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\EventSourced\RetentionPolicy;
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Monadial\Nexus\Runtime\Duration;

/**
 * The InventoryItem entity's actor shell. All decisions live in the InventoryItem
 * aggregate (Domain); this class only wires persistence, replies, publication,
 * and passivation.
 *
 * Reply idiom:
 *   - Unknown command → `Effect::unhandled()` (dead-lettered by PersistenceEngine; no reply).
 *   - No events recorded → `Effect::reply($sender, Accepted(current state))` (null-sender guard).
 *   - Rejection event recorded → `Effect::persist(...)->thenRun(publish all + reply Rejected(reason))`.
 *   - Success events recorded → `Effect::persist(...)->thenRun(publish all + reply Accepted($next))`.
 *
 * Every persisted event (including StockReservationRejected) is published to the
 * bus so the saga receives its failure signal without polling.
 *
 * Signal handler is threaded via `withSignalHandler()` so it reaches the inner
 * `WithStateBehavior` that PersistenceEngine creates.
 *
 * @param ActorRef<Publish> $bus
 */
final class InventoryItemActor
{
    /**
     * @param ActorRef<Publish> $bus
     */
    public static function behavior(
        TenantId $tenantId,
        Sku $sku,
        EventStore $store,
        SnapshotStore $snapshots,
        ActorRef $bus,
        Duration $passivateAfter,
    ): Behavior {
        $es = EventSourcedBehavior::create(
            PersistenceId::of('InventoryItem', "{$tenantId->value}|{$sku->value}"),
            InventoryItem::empty($tenantId, $sku),
            static function (InventoryItem $item, ActorContext $ctx, object $command) use ($bus): Effect {
                $sender = $ctx->sender();

                if ($command instanceof Restock) {
                    $item->restock($command->quantity);
                } elseif ($command instanceof ReserveStock) {
                    $item->reserve($command->orderId, $command->quantity);
                } elseif ($command instanceof ReleaseReservation) {
                    $item->release($command->orderId);
                } else {
                    // Unknown commands dead-letter via PersistenceEngine; state is unchanged.
                    return Effect::unhandled();
                }

                $events = $item->releaseEvents();

                if ($events === []) {
                    if ($sender === null) {
                        return Effect::none();
                    }

                    return Effect::reply($sender, new StockCommandAccepted($item->sku, $item->onHand, $item->available()));
                }

                $rejectionEvent = null;

                foreach ($events as $event) {
                    if ($event instanceof RejectionEvent) {
                        $rejectionEvent = $event;
                        break;
                    }
                }

                if ($rejectionEvent !== null) {
                    $reason = $rejectionEvent->reason();

                    return Effect::persist(...$events)->thenRun(
                        static function (InventoryItem $next) use ($bus, $sender, $events, $reason): void {
                            foreach ($events as $event) {
                                $bus->tell(new Publish($event));
                            }

                            $sender?->tell(new StockCommandRejected($next->sku, $reason));
                        },
                    );
                }

                return Effect::persist(...$events)->thenRun(
                    static function (InventoryItem $next) use ($bus, $sender, $events): void {
                        foreach ($events as $event) {
                            $bus->tell(new Publish($event));
                        }

                        $sender?->tell(new StockCommandAccepted($next->sku, $next->onHand, $next->available()));
                    },
                );
            },
            static function (InventoryItem $item, object $event): InventoryItem {
                $item->apply($event);

                return $item;
            },
        )
            ->withEventStore($store)
            ->withRetention(RetentionPolicy::snapshotAndEvents(3, deleteEventsTo: false))
            ->withSignalHandler(static function (ActorContext $ctx, object $signal): Behavior {
                if ($signal instanceof ReceiveTimeout) {
                    return Behavior::stopped();
                }

                return Behavior::same();
            })
            ->withSnapshotStore($snapshots)
            ->withSnapshotStrategy(SnapshotStrategy::everyN(50))
            ->toBehavior();

        /** @psalm-suppress InvalidArgument $es is a Behavior<object> built by PersistenceEngine; generic T resolves at runtime */
        return Behavior::setup(static function (ActorContext $ctx) use ($es, $passivateAfter): Behavior {
            $ctx->setReceiveTimeout($passivateAfter);

            return $es;
        });
    }
}
