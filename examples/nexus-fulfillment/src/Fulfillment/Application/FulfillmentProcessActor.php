<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Fulfillment\Application;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event\FulfillmentCompensated;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event\FulfillmentCompleted;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event\FulfillmentStarted;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\FulfillmentProcess;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\InventoryRefFactory;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\OrderRefFactory;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\CancelOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\MarkStockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\ReleaseReservation;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\ReserveStock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReservationRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
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
 * FulfillmentProcess entity shell. All decisions live in the FulfillmentProcess
 * aggregate (Domain); this class only wires persistence, side-effects, and passivation.
 *
 * Sagas never reply — there is no sender. Unknown commands dead-letter via
 * Effect::unhandled(). Empty drain (late/duplicate) → Effect::none() (no side effects).
 *
 * Side-effect dispatch in thenRun:
 *   FulfillmentStarted     → tell InventoryRef ReserveStock per pending line
 *   FulfillmentCompleted   → tell OrderRef MarkStockReserved
 *   FulfillmentCompensated → tell InventoryRef ReleaseReservation per confirmed + in-flight
 *                            sku (confirmed ∪ pending); releasing an unconfirmed (in-flight)
 *                            sku is an idempotent no-op at inventory. This covers the
 *                            rejection-races-ahead scenario: StockReservationRejected(B)
 *                            processed before StockReserved(A) arrives at the saga — A's
 *                            reservation is released even though the saga has not yet seen
 *                            the confirmation.
 *                            + tell OrderRef CancelOrder(insufficient stock reason)
 *
 * At-most-once seam: thenRun closures do NOT re-run on replay. A crash between
 * persist and thenRun executing strands the saga until the broker milestone
 * introduces journal-backed redelivery. Documented here and in the README.
 *
 * Compensation sub-race (residual, documented): if inventory processes
 * ReleaseReservation(A) BEFORE it processes the original ReserveStock(A) command,
 * the release lands first, the reserve creates the hold afterwards, and A leaks
 * permanently. Journal-backed delivery (broker milestone) closes this by ensuring
 * the release is only sent once the full ReserveStock round-trip completes.
 * See README "Known limitations" for the full discussion.
 *
 * confirmed-set decision: fold-keeps — apply(FulfillmentCompensated) only sets
 * phase; $confirmed and $pending survive intact so the thenRun release loop reads
 * both $next->confirmed and array_keys($next->pending) to form the release set.
 */
final class FulfillmentProcessActor
{
    public static function behavior(
        TenantId $tenantId,
        OrderId $orderId,
        EventStore $store,
        SnapshotStore $snapshots,
        OrderRefFactory $orders,
        InventoryRefFactory $inventory,
        Duration $passivateAfter,
    ): Behavior {
        $es = EventSourcedBehavior::create(
            PersistenceId::of('FulfillmentProcess', "{$tenantId->value}|{$orderId->value}"),
            FulfillmentProcess::empty($tenantId, $orderId),
            static function (FulfillmentProcess $process, ActorContext $ctx, object $command) use ($orders, $inventory): Effect {
                if ($command instanceof OrderPlaced) {
                    $process->start($command->lines);
                } elseif ($command instanceof StockReserved) {
                    $process->confirmReservation($command->sku);
                } elseif ($command instanceof StockReservationRejected) {
                    $process->rejectReservation($command->sku, $command->reason());
                } else {
                    return Effect::unhandled();
                }

                $events = $process->releaseEvents();

                if ($events === []) {
                    return Effect::none();
                }

                return Effect::persist(...$events)->thenRun(
                    static function (FulfillmentProcess $next) use ($events, $orders, $inventory): void {
                        foreach ($events as $event) {
                            if ($event instanceof FulfillmentStarted) {
                                foreach ($next->pending as $skuStr => $qty) {
                                    $sku = new Sku($skuStr);
                                    $inventory->of($next->tenantId, $sku)->tell(
                                        new ReserveStock($next->tenantId, $sku, $next->orderId, Quantity::of($qty)),
                                    );
                                }
                            } elseif ($event instanceof FulfillmentCompleted) {
                                $orders->of($next->tenantId, $next->orderId)->tell(
                                    new MarkStockReserved($next->tenantId, $next->orderId),
                                );
                            } elseif ($event instanceof FulfillmentCompensated) {
                                // Release confirmed SKUs + in-flight (pending) SKUs.
                                // confirmed and pending are mutually exclusive; releasing a
                                // never-confirmed SKU is an idempotent no-op at inventory.
                                $toRelease = array_unique(
                                    array_merge($next->confirmed, array_keys($next->pending)),
                                );

                                foreach ($toRelease as $skuStr) {
                                    $sku = new Sku($skuStr);
                                    $inventory->of($next->tenantId, $sku)->tell(
                                        new ReleaseReservation($next->tenantId, $sku, $next->orderId),
                                    );
                                }

                                $orders->of($next->tenantId, $next->orderId)->tell(
                                    new CancelOrder($next->tenantId, $next->orderId, $event->reason),
                                );
                            }
                        }
                    },
                );
            },
            static function (FulfillmentProcess $process, object $event): FulfillmentProcess {
                $process->apply($event);

                return $process;
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
