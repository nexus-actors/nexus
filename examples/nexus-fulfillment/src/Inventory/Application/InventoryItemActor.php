<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Application;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\Reply\StockCommandAccepted;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\Reply\StockCommandRejected;
use Monadial\Nexus\Example\Fulfillment\Inventory\Domain\InventoryItem;
use Monadial\Nexus\Example\Fulfillment\Platform\Actor\AggregateEntityBehavior;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Bus\Publish;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\ReleaseReservation;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\ReserveStock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Monadial\Nexus\Runtime\Duration;

/**
 * The InventoryItem entity's actor shell. Decisions live in the InventoryItem
 * aggregate; this class declares the route map and reply constructors.
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
        /**
         * @psalm-suppress ArgumentTypeCoercion
         * — Closures typed with InventoryItem (concrete TAgg) satisfy Closure(object) at
         *   the call-site via contravariance; the helper body suppresses the mixed-state side.
         */
        return AggregateEntityBehavior::build(
            persistenceId: PersistenceId::of('InventoryItem', "{$tenantId->value}|{$sku->value}"),
            emptyState: InventoryItem::empty($tenantId, $sku),
            routes: [
                ReleaseReservation::class => static function (InventoryItem $item, ReleaseReservation $cmd): void {
                    $item->release($cmd->orderId);
                },
                ReserveStock::class => static function (InventoryItem $item, ReserveStock $cmd): void {
                    $item->reserve($cmd->orderId, $cmd->quantity);
                },
                Restock::class => static function (InventoryItem $item, Restock $cmd): void {
                    $item->restock($cmd->quantity);
                },
            ],
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
