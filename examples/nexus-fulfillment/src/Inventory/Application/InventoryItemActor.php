<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Application;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\Reply\StockCommandAccepted;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\Reply\StockCommandRejected;
use Monadial\Nexus\Example\Fulfillment\Inventory\Domain\InventoryItem;
use Monadial\Nexus\Example\Fulfillment\Platform\Actor\AggregateBehavior;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Bus\Publish;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Monadial\Nexus\Runtime\Duration;

/**
 * The InventoryItem entity's actor shell. All decisions live in the InventoryItem
 * aggregate; this class wires persistence, reply constructors, and publication.
 *
 * AggregateBehavior discovers command handlers from InventoryItem's method signatures:
 * restock(Restock), reserve(ReserveStock), release(ReleaseReservation).
 *
 * Engine publishes each persisted event to the bus (via withEventPublisher)
 * so thenRun closures carry no publish loops. StockReservationRejected is a
 * persisted event and is therefore published to the bus automatically —
 * the saga receives its failure signal without polling.
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
        return AggregateBehavior::for(
            aggregate: InventoryItem::empty($tenantId, $sku),
            persistenceId: PersistenceId::of('InventoryItem', "{$tenantId->value}|{$sku->value}"),
            accepted: static fn(InventoryItem $item): object => new StockCommandAccepted($item->sku, $item->onHand, $item->available()),
            rejected: static fn(InventoryItem $item, string $reason): object => new StockCommandRejected($item->sku, $reason),
            store: $store,
            snapshots: $snapshots,
            publisher: static function (object $event) use ($bus): void {
                $bus->tell(new Publish($event));
            },
            passivateAfter: $passivateAfter,
        );
    }
}
