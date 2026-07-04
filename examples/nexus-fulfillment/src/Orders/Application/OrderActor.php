<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Application;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderAccepted;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderRejected;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderRules;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderState;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Rejection;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Bus\Publish;
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
 * The Order entity's actor shell. All decisions live in OrderRules
 * (Domain); this class only wires persistence, replies, publication,
 * and passivation.
 *
 * Reply idiom (mirrored from WalletActor):
 *   - `$sender = $ctx->sender()` captures the ask() reply-to ref (or null for tell()).
 *   - No-persist replies use `Effect::reply($sender, $msg)` (not `none()->thenRun`
 *     — PersistenceEngine's None branch discards side-effects).
 *   - Persist replies use `Effect::persist(...)->thenRun(fn($next) { $sender?->tell(...) })`.
 *
 * Signal handler is threaded via `withSignalHandler()` so it reaches the inner
 * `WithStateBehavior` that PersistenceEngine creates — the only place the
 * ActorCell consults the signal handler at runtime.
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
            OrderState::empty($tenantId, $orderId),
            static function (OrderState $state, ActorContext $ctx, object $command) use ($bus): Effect {
                $sender = $ctx->sender();

                $decision = OrderRules::decide($state, $command);

                if ($decision instanceof Rejection) {
                    if ($sender === null) {
                        return Effect::none();
                    }

                    return Effect::reply($sender, new OrderRejected($state->orderId, $decision->reason));
                }

                if ($decision === []) {
                    if ($sender === null) {
                        return Effect::none();
                    }

                    return Effect::reply($sender, new OrderAccepted($state->orderId, $state->status, $state->total));
                }

                return Effect::persist(...$decision)->thenRun(
                    static function (OrderState $next) use ($bus, $sender, $decision): void {
                        foreach ($decision as $event) {
                            $bus->tell(new Publish($event));
                        }

                        $sender?->tell(new OrderAccepted($next->orderId, $next->status, $next->total));
                    },
                );
            },
            static fn(OrderState $state, object $event): OrderState => OrderState::evolve($state, $event),
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
