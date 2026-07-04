<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Serialization;

use Monadial\Nexus\Example\Fulfillment\Inventory\Domain\InventoryItem;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Order;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\ReleaseReservation;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\ReserveStock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restocked;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReleased;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReservationRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\MarkStockReservedRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancellationRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlacementRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderStockReserved;
use Monadial\Nexus\Serialization\TypeRegistry;

/**
 * The single catalog of wire-serializable contract messages. Every
 * published contract carries #[MessageType('{context}.{name}.v{N}')] and
 * is listed here; the version suffix is the upcasting seam.
 */
final class MessageTypes
{
    /** @var list<class-string> */
    private const array CONTRACTS = [
        MarkStockReservedRejected::class,
        OrderCancellationRejected::class,
        OrderCancelled::class,
        OrderPlaced::class,
        OrderPlacementRejected::class,
        OrderStockReserved::class,
        ReleaseReservation::class,
        ReserveStock::class,
        Restock::class,
        Restocked::class,
        StockReleased::class,
        StockReservationRejected::class,
        StockReserved::class,
    ];

    public static function registry(): TypeRegistry
    {
        $registry = new TypeRegistry();

        // Published contracts — carry #[MessageType] attribute; the attribute is the source of truth.
        foreach (self::CONTRACTS as $contract) {
            $registry->registerFromAttribute($contract);
        }

        // Persisted snapshot state types — explicitly registered; Domain stays pure (no attribute).
        // Wire names are UNCHANGED from the deleted OrderState/ItemState classes so existing
        // snapshots deserialize correctly: Valinor constructs Order/InventoryItem via their public
        // constructors which have identical parameter names and types.
        $registry->register(InventoryItem::class, 'inventory.item_state.v1');
        $registry->register(Order::class, 'orders.order_state.v1');

        return $registry;
    }
}
