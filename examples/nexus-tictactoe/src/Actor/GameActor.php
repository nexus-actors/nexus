<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Example\TicTacToe\Actor\Message\GameEnvelope;
use Monadial\Nexus\Example\TicTacToe\Actor\Message\GameRejected;
use Monadial\Nexus\Example\TicTacToe\Actor\Message\Seated;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\Forfeit;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\GetSnapshot;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\JoinGame;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\MakeMove;
use Monadial\Nexus\Example\TicTacToe\Domain\Event\GameEvent;
use Monadial\Nexus\Example\TicTacToe\Domain\GameDecision;
use Monadial\Nexus\Example\TicTacToe\Domain\GameRules;
use Monadial\Nexus\Example\TicTacToe\Domain\State\GameState;
use Monadial\Nexus\Example\TicTacToe\Domain\View\GameSnapshot;
use Monadial\Nexus\Example\TicTacToe\ReadModel\GameReadModel;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\PersistenceId;
use Psr\Log\LoggerInterface;

/**
 * The per-game aggregate, event-sourced — single writer per game id.
 *
 * Two halves, in the CQRS/ES shape:
 *  - DECIDE ({@see dispatch}): route the enveloped command, ask the pure
 *    {@see GameRules} for a {@see GameDecision}, and turn it into an
 *    `Effect` — persist events + reply, or reply a rejection with no events.
 *  - EVOLVE ({@see applyEvent}): fold one event into the {@see GameState}.
 *
 * The event log is the source of truth: on (re)spawn the persistence engine
 * replays it through `applyEvent` to rebuild state before the first command.
 * After each persist the new state is projected into the {@see GameReadModel}
 * so the REST lobby can query it. Swap the injected {@see EventStore} from
 * `InMemoryEventStore` to `DbalEventStore` for durable journals — this class
 * does not change.
 *
 * Replies are addressed from the {@see GameEnvelope} (its `replyTo` channel
 * actor + originating `fd`), never `ctx->sender()`, so a private welcome or
 * rejection reaches exactly the connection that acted.
 */
final class GameActor
{
    /**
     * @return Behavior<GameEnvelope>
     */
    public static function behavior(
        string $gameId,
        EventStore $store,
        GameReadModel $readModel,
        LoggerInterface $log,
    ): Behavior {
        // The persistence engine erases the command type at toBehavior();
        // this actor's protocol is GameEnvelope (dispatch() unhandles the rest).
        /** @var Behavior<GameEnvelope> $behavior */
        $behavior = EventSourcedBehavior::create(
            PersistenceId::of('Game', $gameId),
            GameState::empty($gameId),
            static fn(GameState $state, ActorContext $ctx, object $command): Effect => self::dispatch(
                $state,
                $command,
                $readModel,
                $log,
            ),
            static fn(GameState $state, object $event): GameState => self::applyEvent($state, $event),
        )
            ->withEventStore($store)
            ->toBehavior();

        return $behavior;
    }

    private static function dispatch(
        GameState $state,
        object $command,
        GameReadModel $readModel,
        LoggerInterface $log,
    ): Effect {
        if (!$command instanceof GameEnvelope) {
            return Effect::unhandled();
        }

        $cmd = $command->command;
        $replyTo = $command->replyTo;
        $fd = $command->originFd;

        return match (true) {
            $cmd instanceof JoinGame => self::onJoin($state, $cmd, $replyTo, $fd, $readModel, $log),
            $cmd instanceof MakeMove => self::onMutation(
                $state,
                GameRules::move($state, $cmd),
                $replyTo,
                $fd,
                $readModel,
                $log,
                $cmd::class,
            ),
            $cmd instanceof Forfeit => self::onMutation(
                $state,
                GameRules::forfeit($state, $cmd),
                $replyTo,
                $fd,
                $readModel,
                $log,
                $cmd::class,
            ),
            $cmd instanceof GetSnapshot => Effect::reply(self::replyChannel($replyTo), $state->toSnapshot()),
            default => Effect::unhandled(),
        };
    }

    /**
     * @param ActorRef<GameRejected|Seated|GameSnapshot> $replyTo
     */
    private static function onJoin(
        GameState $state,
        JoinGame $cmd,
        ActorRef $replyTo,
        int $fd,
        GameReadModel $readModel,
        LoggerInterface $log,
    ): Effect {
        $reply = self::replyChannel($replyTo);
        $decision = GameRules::join($state, $cmd);

        if ($decision->isRejected()) {
            $log->debug('join rejected', ['gameId' => $state->gameId, 'reason' => $decision->rejection]);

            return Effect::reply($reply, new GameRejected((string) $decision->rejection, $fd));
        }

        $token = $cmd->playerId;

        if ($decision->events === []) {
            // Reconnect to a seat already held — no new fact, just re-welcome
            // this connection with the current snapshot.
            return Effect::reply($reply, new Seated($state->toSnapshot(), $fd, $token));
        }

        return self::persistWithProjection($decision->events, $readModel)
            ->thenReply($reply, static fn(GameState $next): Seated => new Seated($next->toSnapshot(), $fd, $token));
    }

    /**
     * @param ActorRef<GameRejected|Seated|GameSnapshot> $replyTo
     */
    private static function onMutation(
        GameState $state,
        GameDecision $decision,
        ActorRef $replyTo,
        int $fd,
        GameReadModel $readModel,
        LoggerInterface $log,
        string $commandClass,
    ): Effect {
        $reply = self::replyChannel($replyTo);

        if ($decision->isRejected()) {
            $log->debug('command rejected', [
                'command' => $commandClass,
                'gameId' => $state->gameId,
                'reason' => $decision->rejection,
            ]);

            return Effect::reply($reply, new GameRejected((string) $decision->rejection, $fd));
        }

        return self::persistWithProjection($decision->events, $readModel)
            ->thenReply($reply, static fn(GameState $next) => $next->toSnapshot());
    }

    /**
     * Erase the reply channel's message type for the persistence reply seam:
     * `Effect::reply()`/`thenReply()` take an `ActorRef<object>` by design —
     * the framework's reply-to is type-erased (Akka Typed's ActorRef[Nothing])
     * and `ActorRef<T>` is invariant in T.
     *
     * @param ActorRef<GameRejected|Seated|GameSnapshot> $replyTo
     * @return ActorRef<object>
     */
    private static function replyChannel(ActorRef $replyTo): ActorRef
    {
        /** @var ActorRef<object> $erased */
        $erased = $replyTo;

        return $erased;
    }

    /**
     * @param list<GameEvent> $events
     */
    private static function persistWithProjection(array $events, GameReadModel $readModel): Effect
    {
        return Effect::persist(...$events)->thenRun(static function (GameState $next) use ($readModel): void {
            $readModel->apply($next->toSnapshot());
        });
    }

    private static function applyEvent(GameState $state, object $event): GameState
    {
        return $event instanceof GameEvent
            ? $state->apply($event)
            : $state;
    }
}
