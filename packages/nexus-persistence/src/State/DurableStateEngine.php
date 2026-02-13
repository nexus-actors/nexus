<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\State;

use Closure;
use DateTimeImmutable;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Persistence\PersistenceId;

/**
 * Core engine that wraps user command handlers into a Behavior
 * with automatic recovery and state persistence.
 *
 * Unlike PersistenceEngine (event sourcing), DurableStateEngine persists
 * the CURRENT state directly. No events, no replay, no snapshots.
 *
 * On actor start (setup phase), the engine:
 *   1. Loads the current state from the DurableStateStore (if available)
 *   2. Returns a stateful Behavior for command processing
 *
 * On each message, the engine delegates to the user's commandHandler,
 * interprets the returned DurableEffect, and handles persistence + side effects.
 */
final class DurableStateEngine
{
    /**
     * Create a Behavior that wraps durable state persistence around
     * a user-defined command handler.
     *
     * @template S of object
     *
     * @param PersistenceId $persistenceId Unique identity for this persistent entity
     * @param S $emptyState Initial empty state before any persisted state
     * @param Closure(S, ActorContext, object): DurableEffect $commandHandler Processes commands, returns DurableEffect
     * @param DurableStateStore $stateStore Store for persisting and loading state
     * @return Behavior The behavior to use when spawning the actor
     */
    public static function create(
        PersistenceId $persistenceId,
        object $emptyState,
        Closure $commandHandler,
        DurableStateStore $stateStore,
    ): Behavior {
        return Behavior::setup(static function (ActorContext $ctx) use (
            $persistenceId, $emptyState, $commandHandler, $stateStore,
        ): Behavior {
            // === Recovery Phase ===
            $state = $emptyState;
            $version = 0;

            $existing = $stateStore->get($persistenceId);
            if ($existing !== null) {
                $state = $existing->state;
                $version = $existing->version;
            }

            // === Command Processing Phase ===
            return Behavior::withState(
                ['state' => $state, 'version' => $version],
                static function (ActorContext $ctx, object $msg, mixed $data) use (
                    $persistenceId, $commandHandler, $stateStore,
                ): BehaviorWithState {
                    /** @var array{state: object, version: int} $data */
                    $state = $data['state'];
                    $version = $data['version'];

                    $effect = $commandHandler($state, $ctx, $msg);

                    return match ($effect->type) {
                        DurableEffectType::Persist => self::handlePersist(
                            $effect, $version, $persistenceId, $stateStore,
                        ),
                        DurableEffectType::None => BehaviorWithState::same(),
                        DurableEffectType::Unhandled => BehaviorWithState::same(),
                        DurableEffectType::Stash => self::handleStash($ctx),
                        DurableEffectType::Stop => BehaviorWithState::stopped(),
                        DurableEffectType::Reply => self::handleReply($effect),
                    };
                },
            );
        });
    }

    /**
     * Handle a Persist effect: increment version, upsert state,
     * execute side effects.
     */
    private static function handlePersist(
        DurableEffect $effect,
        int $version,
        PersistenceId $persistenceId,
        DurableStateStore $stateStore,
    ): BehaviorWithState {
        $newVersion = $version + 1;
        $newState = $effect->state;

        $stateStore->upsert($persistenceId, new DurableStateEnvelope(
            persistenceId: $persistenceId,
            version: $newVersion,
            state: $newState,
            stateType: $newState::class,
            timestamp: new DateTimeImmutable(),
        ));

        // Execute side effects (thenRun, thenReply)
        foreach ($effect->sideEffects as $sideEffect) {
            $sideEffect($newState);
        }

        return BehaviorWithState::next(['state' => $newState, 'version' => $newVersion]);
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
    private static function handleReply(DurableEffect $effect): BehaviorWithState
    {
        $effect->replyTo->tell($effect->replyMsg);

        return BehaviorWithState::same();
    }
}
