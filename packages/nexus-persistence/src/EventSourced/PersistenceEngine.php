<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\EventSourced;

use Closure;
use DateTimeImmutable;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Recovery\ReplayFilter;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Ulid;

use function is_object;

/**
 * Core engine that wraps user command+event handlers into a Behavior
 * with automatic recovery and event persistence.
 *
 * On actor start (setup phase), the engine:
 *   1. Loads the latest snapshot from the SnapshotStore (if available)
 *   2. Replays events from the EventStore (from snapshot's sequenceNr + 1)
 *   3. Applies each event to state using the user's eventHandler
 *   4. Returns a stateful Behavior for command processing
 *
 * On each message, the engine delegates to the user's commandHandler,
 * interprets the returned Effect, and handles persistence + side effects.
 *
 * @psalm-type EngineState = array{sequenceNr: int, state: object}
 */
final class PersistenceEngine
{
    /**
     * Create a Behavior that wraps event-sourced persistence around
     * user-defined command and event handlers.
     *
     * @template S of object
     * @template E of object
     *
     * @param PersistenceId $persistenceId Unique identity for this persistent entity
     * @param S $emptyState Initial empty state before any events
     * @param Closure(S, ActorContext, object): Effect $commandHandler Processes commands, returns Effect
     * @param Closure(S, E): S $eventHandler Applies events to state (pure function)
     * @param EventStore $eventStore Store for persisting and loading events
     * @param SnapshotStore|null $snapshotStore Optional store for snapshots
     * @param SnapshotStrategy|null $snapshotStrategy When to take snapshots (default: never)
     * @param RetentionPolicy|null $retentionPolicy Event/snapshot retention (default: keep all)
     * @param Ulid $writerId Writer identity stamped on persisted events and snapshots
     * @param ReplayFilter|null $replayFilter Filter for detecting writer conflicts during recovery
     * @return Behavior The behavior to use when spawning the actor
     */
    public static function create(
        PersistenceId $persistenceId,
        object $emptyState,
        Closure $commandHandler,
        Closure $eventHandler,
        EventStore $eventStore,
        ?SnapshotStore $snapshotStore = null,
        ?SnapshotStrategy $snapshotStrategy = null,
        ?RetentionPolicy $retentionPolicy = null,
        Ulid $writerId = new Ulid(),
        ?ReplayFilter $replayFilter = null,
    ): Behavior {
        $strategy = $snapshotStrategy ?? SnapshotStrategy::never();
        $retention = $retentionPolicy ?? RetentionPolicy::none();
        $filter = $replayFilter ?? ReplayFilter::off();

        return Behavior::setup(
            /**
             * @param ActorContext<object> $_ctx
             * @return Behavior<object>
             */
            static function (ActorContext $_ctx) use (
                $persistenceId,
                $emptyState,
                $commandHandler,
                $eventHandler,
                $eventStore,
                $snapshotStore,
                $strategy,
                $retention,
                $writerId,
                $filter,
            ): Behavior {
                // === Recovery Phase ===
                $state = $emptyState;
                $sequenceNr = 0;

                // 1. Load snapshot (if available)
                if ($snapshotStore !== null) {
                    $snapshot = $snapshotStore->load($persistenceId);

                    if ($snapshot !== null) {
                        /**
                         * Snapshots stored under this PersistenceId hold states
                         * produced by the S-typed event handler, so the stored
                         * object is always the entity's state type S.
                         *
                         * @var S $snapshotState
                         */
                        $snapshotState = $snapshot->state;
                        $state = $snapshotState;
                        $sequenceNr = $snapshot->sequenceNr;
                    }
                }

                // 2. Replay events from snapshot's sequenceNr + 1
                $events = $eventStore->load($persistenceId, $sequenceNr + 1);

                // 3. Apply replay filter for single-writer violation detection
                $filteredEvents = $filter->filter($persistenceId, $events, new NullLogger());

                foreach ($filteredEvents as $envelope) {
                    /**
                     * Events stored under this PersistenceId were produced by
                     * the E-typed command handler, so each stored event is the
                     * entity's event type E.
                     *
                     * @var E $event
                     */
                    $event = $envelope->event;
                    $state = $eventHandler($state, $event);
                    $sequenceNr = $envelope->sequenceNr;
                }

                // === Command Processing Phase ===

                return Behavior::withState(
                    ['state' => $state, 'sequenceNr' => $sequenceNr],
                    /**
                     * @param ActorContext<object> $ctx
                     * @param EngineState $data
                     * @return BehaviorWithState<object, EngineState>
                     */
                    static function (ActorContext $ctx, object $msg, array $data) use (
                        $persistenceId,
                        $commandHandler,
                        $eventHandler,
                        $eventStore,
                        $snapshotStore,
                        $strategy,
                        $retention,
                        $writerId,
                    ): BehaviorWithState {
                        /**
                         * The engine only ever stores states recovered for this
                         * PersistenceId or produced by the S-typed event handler,
                         * so the stored object is always the entity's state type S.
                         *
                         * @var S $state
                         */
                        $state = $data['state'];
                        $sequenceNr = $data['sequenceNr'];

                        $effect = $commandHandler($state, $ctx, $msg);

                        $result = match ($effect->type) {
                            EffectType::Persist => self::handlePersist(
                                $effect,
                                $state,
                                $sequenceNr,
                                $persistenceId,
                                $eventHandler,
                                $eventStore,
                                $snapshotStore,
                                $strategy,
                                $retention,
                                $writerId,
                            ),
                            EffectType::None => self::sameState(),
                            EffectType::Unhandled => self::sameState(),
                            EffectType::Stash => self::handleStash($ctx),
                            EffectType::Stop => self::stoppedState(),
                            EffectType::Reply => self::handleReply($effect),
                        };

                        // Hooks on the persist path already ran inside handlePersist,
                        // after the durable write and with the post-persist state.
                        // Every other effect runs its hooks here with the current state.

                        if ($effect->type !== EffectType::Persist) {
                            self::runSideEffects($effect, $state);
                        }

                        return $result;
                    },
                );
            },
        );
    }

    /**
     * Handle a Persist effect: build envelopes, persist events, update state,
     * check snapshot strategy, apply retention, execute side effects.
     *
     * @template S of object
     * @template E of object
     *
     * @param S $state
     * @param Closure(S, E): S $eventHandler
     * @return BehaviorWithState<object, EngineState>
     */
    private static function handlePersist(
        Effect $effect,
        object $state,
        int $sequenceNr,
        PersistenceId $persistenceId,
        Closure $eventHandler,
        EventStore $eventStore,
        ?SnapshotStore $snapshotStore,
        SnapshotStrategy $strategy,
        RetentionPolicy $retention,
        Ulid $writerId,
    ): BehaviorWithState {
        // 1. Build EventEnvelopes with incrementing sequenceNr
        $envelopes = [];
        $newSeqNr = $sequenceNr;

        foreach ($effect->events as $event) {
            $newSeqNr++;
            $envelopes[] = new EventEnvelope(
                persistenceId: $persistenceId,
                sequenceNr: $newSeqNr,
                event: $event,
                eventType: $event::class,
                timestamp: new DateTimeImmutable(),
                writerId: $writerId,
            );
        }

        // 2. Persist to EventStore
        $eventStore->persist($persistenceId, ...$envelopes);

        // 3. Apply events to state via eventHandler
        $newState = $state;
        $lastEvent = null;

        foreach ($effect->events as $event) {
            /**
             * Effect::persist() erases the event type, but the events were
             * produced by the E-typed command handler for this entity.
             *
             * @var E $event
             */
            $newState = $eventHandler($newState, $event);
            $lastEvent = $event;
        }

        // 4. Check snapshot strategy and save snapshot if triggered

        if (
            $lastEvent !== null
            && $snapshotStore !== null
            && $strategy->shouldSnapshot($newState, $lastEvent, $newSeqNr)
        ) {
            $snapshotStore->save($persistenceId, new SnapshotEnvelope(
                persistenceId: $persistenceId,
                sequenceNr: $newSeqNr,
                state: $newState,
                stateType: $newState::class,
                timestamp: new DateTimeImmutable(),
                writerId: $writerId,
            ));

            // 5. Apply retention policy: delete old events up to snapshot
            if ($retention->deleteEventsToSnapshot) {
                $eventStore->deleteUpTo($persistenceId, $newSeqNr);
            }
        }

        // 6. Execute side effects (thenRun, thenReply) with the post-persist state
        self::runSideEffects($effect, $newState);

        // 7. Return with updated state and sequenceNr

        /** @var EngineState $nextData Widen state back to the engine's object-typed shape */
        $nextData = ['state' => $newState, 'sequenceNr' => $newSeqNr];

        return BehaviorWithState::next($nextData);
    }

    /**
     * Execute the side-effect hooks registered via thenRun()/thenReply().
     *
     * On the persist path `$state` is the post-persist projected state; for
     * every other effect it is the unchanged current state. Hooks execute in
     * registration order, after the effect's primary action.
     */
    private static function runSideEffects(Effect $effect, object $state): void
    {
        foreach ($effect->sideEffects as $sideEffect) {
            $sideEffect($state);
        }
    }

    /**
     * Handle a Stash effect: stash the current message in the actor context.
     *
     * @return BehaviorWithState<object, EngineState>
     */
    private static function handleStash(ActorContext $ctx): BehaviorWithState
    {
        $ctx->stash();

        return self::sameState();
    }

    /**
     * Handle a Reply effect: send the reply message to the reply target.
     *
     * @return BehaviorWithState<object, EngineState>
     */
    private static function handleReply(Effect $effect): BehaviorWithState
    {
        assert($effect->replyTo !== null);
        assert(is_object($effect->replyMsg));
        $effect->replyTo->tell($effect->replyMsg);

        return self::sameState();
    }

    /**
     * BehaviorWithState::same() resolves the class templates to their bounds
     * (object, mixed) in a static-call context, so pin the keep-current marker
     * to the engine's state shape.
     *
     * @return BehaviorWithState<object, EngineState>
     */
    private static function sameState(): BehaviorWithState
    {
        /** @var BehaviorWithState<object, EngineState> */
        return BehaviorWithState::same();
    }

    /**
     * As sameState(), for the stop marker.
     *
     * @return BehaviorWithState<object, EngineState>
     */
    private static function stoppedState(): BehaviorWithState
    {
        /** @var BehaviorWithState<object, EngineState> */
        return BehaviorWithState::stopped();
    }
}
