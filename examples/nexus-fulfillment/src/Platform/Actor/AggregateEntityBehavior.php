<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Actor;

use Closure;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\RejectionEvent;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\EventSourced\RetentionPolicy;
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Monadial\Nexus\Runtime\Duration;

/**
 * Builds the full Behavior for a mutable aggregate entity actor.
 *
 * Encapsulates the ceremony that is identical across all entity actors:
 * command routing, event draining, reply idiom, engine-level publication,
 * passivation, and snapshot/retention conventions.
 *
 * The aggregate TAgg must implement:
 *   - `releaseEvents(): list<object>` — drain recorded events
 *   - `apply(object $event): void`    — fold an event onto state
 *
 * Reply idiom:
 *   - Unknown command  → Effect::unhandled() (dead-lettered; no reply)
 *   - No events drained → Effect::reply($sender, $accepted($agg)) with null-sender guard
 *   - RejectionEvent drained → Effect::persist(...)->thenRun(reply $rejected($next, $reason))
 *   - Success events drained → Effect::persist(...)->thenRun(reply $accepted($next))
 *
 * The engine invokes $publisher for each persisted event after fold, before thenRun.
 * thenRun closures therefore do NOT publish — that loop is gone.
 *
 * @psalm-api
 */
final class AggregateEntityBehavior
{
    /**
     * Build a complete Behavior for an aggregate entity actor.
     *
     * @template TAgg of object
     *
     * @param TAgg $emptyState Initial empty aggregate (before any events)
     * @param array<class-string, Closure> $routes Map of command class → handler closure(TAgg, TCmd): void
     * @param Closure(TAgg): object $accepted Build the accepted reply from the (post-fold) aggregate
     * @param Closure(TAgg, string): object $rejected Build the rejected reply from the (post-fold) aggregate + reason
     * @param Closure(object): void $publisher Invoked by the engine for each persisted event (after fold)
     */
    public static function build(
        PersistenceId $persistenceId,
        object $emptyState,
        array $routes,
        Closure $accepted,
        Closure $rejected,
        EventStore $store,
        SnapshotStore $snapshots,
        Closure $publisher,
        Duration $passivateAfter,
    ): Behavior {
        $es = EventSourcedBehavior::create(
            $persistenceId,
            $emptyState,
            static function (object $agg, ActorContext $ctx, object $msg) use ($routes, $accepted, $rejected): Effect {
                $handler = $routes[$msg::class] ?? null;

                if ($handler === null) {
                    return Effect::unhandled();
                }

                /** @psalm-suppress MixedFunctionCall — route closures are typed Closure(TAgg, TCmd): void at each call-site */
                $handler($agg, $msg);

                $sender = $ctx->sender();

                /** @psalm-suppress MixedMethodCall,MixedAssignment — TAgg guarantees releaseEvents(); $agg is TAgg at runtime */
                $events = $agg->releaseEvents();

                if ($events === []) {
                    if ($sender === null) {
                        return Effect::none();
                    }

                    /** @psalm-suppress InvalidArgument — $agg is TAgg at runtime; $accepted declared Closure(TAgg): object */
                    return Effect::reply($sender, $accepted($agg));
                }

                $rejectionEvent = null;

                /** @psalm-suppress MixedAssignment — $events is list<object> at runtime */
                foreach ($events as $event) {
                    if ($event instanceof RejectionEvent) {
                        $rejectionEvent = $event;
                        break;
                    }
                }

                if ($rejectionEvent !== null) {
                    $reason = $rejectionEvent->reason();

                    /** @psalm-suppress MixedArgument — $events is list<object> at runtime */
                    return Effect::persist(...$events)->thenRun(
                        static function (object $next) use ($sender, $rejected, $reason): void {
                            /** @psalm-suppress InvalidArgument — $next is TAgg at runtime; $rejected declared Closure(TAgg, string): object */
                            $sender?->tell($rejected($next, $reason));
                        },
                    );
                }

                /** @psalm-suppress MixedArgument — $events is list<object> at runtime */
                return Effect::persist(...$events)->thenRun(
                    static function (object $next) use ($sender, $accepted): void {
                        /** @psalm-suppress InvalidArgument — $next is TAgg at runtime; $accepted declared Closure(TAgg): object */
                        $sender?->tell($accepted($next));
                    },
                );
            },
            static function (object $agg, object $event): object {
                /** @psalm-suppress MixedMethodCall — TAgg guarantees apply(); $agg is TAgg at runtime */
                $agg->apply($event);

                return $agg;
            },
        )
            ->withEventPublisher($publisher)
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
