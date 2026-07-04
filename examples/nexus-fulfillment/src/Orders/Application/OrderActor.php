<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Application;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderAccepted;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderRejected;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Order;
use Monadial\Nexus\Example\Fulfillment\Platform\Actor\AggregateBehavior;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Bus\Publish;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Monadial\Nexus\Runtime\Duration;

/**
 * The Order entity's actor shell. All decisions live in the Order aggregate;
 * this class wires persistence, reply constructors, and publication.
 *
 * AggregateBehavior discovers command handlers from Order's method signatures:
 * place(PlaceOrder), cancel(CancelOrder), markStockReserved(MarkStockReserved).
 *
 * Engine publishes each persisted event to the bus (via withEventPublisher)
 * so thenRun closures carry no publish loops.
 *
 * @param ActorRef<Publish> $bus
 */
final class OrderActor
{
    /**
     * @param ActorRef<Publish> $bus
     */
    public static function behavior(
        TenantId $tenantId,
        OrderId $orderId,
        EventStore $store,
        SnapshotStore $snapshots,
        ActorRef $bus,
        Duration $passivateAfter,
    ): Behavior {
        return AggregateBehavior::for(
            aggregate: Order::empty($tenantId, $orderId),
            persistenceId: PersistenceId::of('Order', "{$tenantId->value}|{$orderId->value}"),
            accepted: static fn(Order $order): object => new OrderAccepted($order->orderId, $order->status, $order->total),
            rejected: static fn(Order $order, string $reason): object => new OrderRejected($order->orderId, $reason),
            store: $store,
            snapshots: $snapshots,
            publisher: static function (object $event) use ($bus): void {
                $bus->tell(new Publish($event));
            },
            passivateAfter: $passivateAfter,
        );
    }
}
