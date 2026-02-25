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
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;

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
    ): Behavior {
        $strategy = $snapshotStrategy ?? SnapshotStrategy::never();
        $retention = $retentionPolicy ?? RetentionPolicy::none();

        /** @psalm-suppress UnusedClosureParam, InvalidArgument */
        return Behavior::setup(static function (ActorContext $_ctx) use (
            $persistenceId,
            $emptyState,
            $commandHandler,
            $eventHandler,
            $eventStore,
            $snapshotStore,
            $strategy,
            $retention,
        ): Behavior {
            // === Recovery Phase ===
            $state = $emptyState;
            $sequenceNr = 0;

            // 1. Load snapshot (if available)
            if ($snapshotStore !== null) {
                $snapshot = $snapshotStore->load($persistenceId);

                if ($snapshot !== null) {
                    $state = $snapshot->state;
                    $sequenceNr = $snapshot->sequenceNr;
                }
            }

            // 2. Replay events from snapshot's sequenceNr + 1
            $events = $eventStore->load($persistenceId, $sequenceNr + 1);

            foreach ($events as $envelope) {
                /** @psalm-suppress InvalidArgument */
                $state = $eventHandler($state, $envelope->event);
                $sequenceNr = $envelope->sequenceNr;
            }

            // === Command Processing Phase ===

            /** @psalm-suppress InvalidArgument */
            return Behavior::withState(
                ['state' => $state, 'sequenceNr' => $sequenceNr],
                static function (ActorContext $ctx, object $msg, mixed $data) use (
                    $persistenceId,
                    $commandHandler,
                    $eventHandler,
                    $eventStore,
                    $snapshotStore,
                    $strategy,
                    $retention,
                ): BehaviorWithState {
                    /** @var array{state: object, sequenceNr: int} $data */
                    $state = $data['state'];
                    $sequenceNr = $data['sequenceNr'];

                    /** @psalm-suppress InvalidArgument */
                    $effect = $commandHandler($state, $ctx, $msg);

                    return match ($effect->type) {
                        /** @psalm-suppress MixedArgument State loses type through closure capture */
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
                        ),
                        EffectType::None => BehaviorWithState::same(),
                        EffectType::Unhandled => BehaviorWithState::same(),
                        EffectType::Stash => self::handleStash($ctx),
                        EffectType::Stop => BehaviorWithState::stopped(),
                        EffectType::Reply => self::handleReply($effect),
                    };
                },
            );
        });
    }

    /**
     * Handle a Persist effect: build envelopes, persist events, update state,
     * check snapshot strategy, apply retention, execute side effects.
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
            );
        }

        // 2. Persist to EventStore
        $eventStore->persist($persistenceId, ...$envelopes);

        // 3. Apply events to state via eventHandler
        $newState = $state;
        $lastEvent = null;

        foreach ($effect->events as $event) {
            /** @psalm-suppress MixedAssignment eventHandler returns generic S but Psalm sees mixed */
            $newState = $eventHandler($newState, $event);
            $lastEvent = $event;
        }

        // 4. Check snapshot strategy and save snapshot if triggered

        /** @psalm-suppress MixedArgument $newState is object but Psalm loses type through closure */
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
            ));

            // 5. Apply retention policy: delete old events up to snapshot
            if ($retention->deleteEventsToSnapshot) {
                $eventStore->deleteUpTo($persistenceId, $newSeqNr);
            }
        }

        // 6. Execute side effects (thenRun, thenReply)
        foreach ($effect->sideEffects as $sideEffect) {
            $sideEffect($newState);
        }

        // 7. Return with updated state and sequenceNr
        return BehaviorWithState::next(['state' => $newState, 'sequenceNr' => $newSeqNr]);
    }

    /**
     * Handle a Stash effect: stash the current message in the actor context.
     */
    private static function handleStash(ActorContext $ctx): BehaviorWithState
    {
        $ctx->stash();

        return BehaviorWithState::same();
    }

    /**
     * Handle a Reply effect: send the reply message to the reply target.
     */
    private static function handleReply(Effect $effect): BehaviorWithState
    {
        assert($effect->replyTo !== null);
        assert(is_object($effect->replyMsg));
        $effect->replyTo->tell($effect->replyMsg);

        return BehaviorWithState::same();
    }
}
