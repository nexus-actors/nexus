<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Application;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderAccepted;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderRejected;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\CancelOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\MarkStockReserved;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\PlaceOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Order;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Bus\Publish;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\RejectionEvent;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
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
 * The Order entity's actor shell. All decisions live in the Order aggregate
 * (Domain); this class only wires persistence, replies, publication, and
 * passivation.
 *
 * Reply idiom:
 *   - No events recorded → `Effect::reply($sender, Accepted(current state))` (null-sender guard).
 *   - Rejection event recorded → `Effect::persist(...)->thenRun(publish all + reply Rejected(reason))`.
 *   - Success events recorded → `Effect::persist(...)->thenRun(publish all + reply Accepted($next))`.
 *
 * Signal handler is threaded via `withSignalHandler()` so it reaches the inner
 * `WithStateBehavior` that PersistenceEngine creates.
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
        $es = EventSourcedBehavior::create(
            PersistenceId::of('Order', "{$tenantId->value}|{$orderId->value}"),
            Order::empty($tenantId, $orderId),
            static function (Order $order, ActorContext $ctx, object $command) use ($bus): Effect {
                $sender = $ctx->sender();

                if ($command instanceof PlaceOrder) {
                    $order->place($command->lines);
                } elseif ($command instanceof MarkStockReserved) {
                    $order->markStockReserved();
                } elseif ($command instanceof CancelOrder) {
                    $order->cancel($command->reason);
                }

                // Unknown commands: no events recorded — falls through to [] path below.
                $events = $order->releaseEvents();

                if ($events === []) {
                    if ($sender === null) {
                        return Effect::none();
                    }

                    return Effect::reply($sender, new OrderAccepted($order->orderId, $order->status, $order->total));
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
                        static function (Order $next) use ($bus, $sender, $events, $reason): void {
                            foreach ($events as $event) {
                                $bus->tell(new Publish($event));
                            }

                            $sender?->tell(new OrderRejected($next->orderId, $reason));
                        },
                    );
                }

                return Effect::persist(...$events)->thenRun(
                    static function (Order $next) use ($bus, $sender, $events): void {
                        foreach ($events as $event) {
                            $bus->tell(new Publish($event));
                        }

                        $sender?->tell(new OrderAccepted($next->orderId, $next->status, $next->total));
                    },
                );
            },
            static function (Order $order, object $event): Order {
                $order->apply($event);

                return $order;
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
