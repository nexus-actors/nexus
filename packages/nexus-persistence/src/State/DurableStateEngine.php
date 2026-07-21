<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\State;

use Closure;
use DateTimeImmutable;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Persistence\PersistenceId;
use Symfony\Component\Uid\Ulid;

use function is_object;

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
 *
 * @psalm-api
 *
 * @psalm-type EngineState = array{state: object, version: int}
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
     * @param Ulid $writerId Writer identity stamped on persisted state
     * @return Behavior The behavior to use when spawning the actor
     */
    public static function create(
        PersistenceId $persistenceId,
        object $emptyState,
        Closure $commandHandler,
        DurableStateStore $stateStore,
        Ulid $writerId = new Ulid(),
    ): Behavior {
        return Behavior::setup(
            /**
             * @param ActorContext<object> $_ctx
             * @return Behavior<object>
             */
            static function (ActorContext $_ctx) use (
                $persistenceId,
                $emptyState,
                $commandHandler,
                $stateStore,
                $writerId,
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
                    /**
                     * @param ActorContext<object> $ctx
                     * @param EngineState $data
                     * @return BehaviorWithState<object, EngineState>
                     */
                    static function (ActorContext $ctx, object $msg, array $data) use (
                        $persistenceId,
                        $commandHandler,
                        $stateStore,
                        $writerId,
                    ): BehaviorWithState {
                        /**
                         * The engine only ever stores states recovered for this
                         * PersistenceId or produced by the S-typed command handler,
                         * so the stored object is always the entity's state type S.
                         *
                         * @var S $state
                         */
                        $state = $data['state'];
                        $version = $data['version'];

                        $effect = $commandHandler($state, $ctx, $msg);

                        $result = match ($effect->type) {
                            DurableEffectType::Persist => self::handlePersist(
                                $effect,
                                $version,
                                $persistenceId,
                                $stateStore,
                                $writerId,
                            ),
                            DurableEffectType::None => self::sameState(),
                            DurableEffectType::Unhandled => self::handleUnhandled($ctx, $msg),
                            DurableEffectType::Stash => self::handleStash($ctx),
                            DurableEffectType::Stop => self::stoppedState(),
                            DurableEffectType::Reply => self::handleReply($effect),
                        };

                        // Hooks on the persist path already ran inside handlePersist,
                        // after the durable write and with the persisted state.
                        // Every other effect runs its hooks here with the current state.

                        if ($effect->type !== DurableEffectType::Persist) {
                            self::runSideEffects($effect, $state);
                        }

                        return $result;
                    },
                );
            },
        );
    }

    /**
     * Handle a Persist effect: increment version, upsert state,
     * execute side effects.
     *
     * @return BehaviorWithState<object, EngineState>
     */
    private static function handlePersist(
        DurableEffect $effect,
        int $version,
        PersistenceId $persistenceId,
        DurableStateStore $stateStore,
        Ulid $writerId,
    ): BehaviorWithState {
        $newVersion = $version + 1;
        $newState = $effect->state;
        assert($newState !== null);

        $stateStore->upsert($persistenceId, new DurableStateEnvelope(
            persistenceId: $persistenceId,
            version: $newVersion,
            state: $newState,
            stateType: $newState::class,
            timestamp: new DateTimeImmutable(),
            writerId: $writerId,
        ));

        // Execute side effects (thenRun, thenReply) with the persisted state
        self::runSideEffects($effect, $newState);

        return BehaviorWithState::next(['state' => $newState, 'version' => $newVersion]);
    }

    /**
     * Execute the side-effect hooks registered via thenRun()/thenReply().
     *
     * On the persist path `$state` is the newly persisted state; for every
     * other effect it is the unchanged current state. Hooks execute in
     * registration order, after the effect's primary action.
     */
    private static function runSideEffects(DurableEffect $effect, object $state): void
    {
        foreach ($effect->sideEffects as $sideEffect) {
            $sideEffect($state);
        }
    }

    /**
     * Handle an Unhandled effect: route the command to dead letters so
     * protocol drift is observable, matching Behavior::unhandled() in the
     * stateless actor model, then keep the current state.
     *
     * @return BehaviorWithState<object, EngineState>
     */
    private static function handleUnhandled(ActorContext $ctx, object $command): BehaviorWithState
    {
        $ctx->toDeadLetters($command);

        return self::sameState();
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
    private static function handleReply(DurableEffect $effect): BehaviorWithState
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
