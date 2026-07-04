<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Application;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\Reply\StockCommandAccepted;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\Reply\StockCommandRejected;
use Monadial\Nexus\Example\Fulfillment\Inventory\Domain\InventoryRules;
use Monadial\Nexus\Example\Fulfillment\Inventory\Domain\ItemState;
use Monadial\Nexus\Example\Fulfillment\Inventory\Domain\Rejection;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Bus\Publish;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReservationRejected;
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
 * The InventoryItem entity's actor shell. All decisions live in InventoryRules
 * (Domain); this class only wires persistence, replies, publication,
 * and passivation.
 *
 * Reply idiom (mirrored from OrderActor):
 *   - `$sender = $ctx->sender()` captures the ask() reply-to ref (or null for tell()).
 *   - No-persist replies use `Effect::reply($sender, $msg)` (not `none()->thenRun`
 *     — PersistenceEngine's None branch discards side-effects).
 *   - Persist replies use `Effect::persist(...)->thenRun(fn($next) { $sender?->tell(...) })`.
 *
 * Signal handler is threaded via `withSignalHandler()` so it reaches the inner
 * `WithStateBehavior` that PersistenceEngine creates — the only place the
 * ActorCell consults the signal handler at runtime.
 *
 * Every persisted event (including StockReservationRejected) is published to the
 * bus so the saga receives its failure signal without polling.
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
            ItemState::empty($tenantId, $sku),
            static function (ItemState $state, ActorContext $ctx, object $command) use ($bus): Effect {
                $sender = $ctx->sender();

                $decision = InventoryRules::decide($state, $command);

                if ($decision instanceof Rejection) {
                    if ($sender === null) {
                        return Effect::none();
                    }

                    return Effect::reply($sender, new StockCommandRejected($state->sku, $decision->reason));
                }

                if ($decision === []) {
                    if ($sender === null) {
                        return Effect::none();
                    }

                    return Effect::reply($sender, new StockCommandAccepted($state->sku, $state->onHand, $state->available()));
                }

                return Effect::persist(...$decision)->thenRun(
                    static function (ItemState $next) use ($bus, $sender, $decision): void {
                        foreach ($decision as $event) {
                            $bus->tell(new Publish($event));
                        }

                        $firstEvent = $decision[0];

                        if ($firstEvent instanceof StockReservationRejected) {
                            $sender?->tell(new StockCommandRejected($next->sku, $firstEvent->reason));
                        } else {
                            $sender?->tell(new StockCommandAccepted($next->sku, $next->onHand, $next->available()));
                        }
                    },
                );
            },
            static fn(ItemState $state, object $event): ItemState => ItemState::evolve($state, $event),
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
