<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Actor;

use Closure;
use LogicException;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Example\TicTacToe\Actor\Message\GameEnvelope;
use Monadial\Nexus\Example\TicTacToe\Actor\Message\GameRejected;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\Forfeit;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\GetSnapshot;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\JoinGame;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\MakeMove;
use Monadial\Nexus\Example\TicTacToe\Domain\Entity\GameSession;
use Monadial\Nexus\Example\TicTacToe\Domain\Exception\GameDomainException;
use Psr\Log\LoggerInterface;

/**
 * Command handler for the per-game aggregate.
 *
 * The handler dispatches the enclosed {@see \Monadial\Nexus\Example\TicTacToe\Domain\Command\GameCommand}
 * against {@see GameSession}. On a domain-rule violation the caller
 * receives a {@see GameRejected} — not a snapshot — so the WebSocket
 * client never hangs waiting for a reply that will not come. No Doctrine
 * knowledge lives here: {@see \Monadial\Nexus\Example\TicTacToe\Boot\DoctrineKit}
 * wires the connection factory, ORM configuration, and passivation.
 */
final class GameActor
{
    /**
     * @return Closure(ActorContext<GameEnvelope>, GameEnvelope, GameSession): EntityEffect<GameSession>
     */
    public static function handler(LoggerInterface $log): Closure
    {
        return
            /**
             * @return EntityEffect<GameSession>
             */
            static function (ActorContext $ctx, GameEnvelope $env, GameSession $game) use ($log): EntityEffect {
                $cmd = $env->command;
                $replyTo = $env->replyTo;

                $log->info('game command received', [
                    'gameId' => $game->id(),
                    'command' => $cmd::class,
                    'statusBefore' => $game->status()->value,
                ]);

                try {
                    $effect = match (true) {
                        $cmd instanceof JoinGame    => self::persistAndReply(
                            $replyTo,
                            static fn() => $game->join($cmd->playerId, $cmd->playerName),
                        ),
                        $cmd instanceof MakeMove    => self::persistAndReply(
                            $replyTo,
                            static fn() => $game->makeMove($cmd->playerId, $cmd->cellIndex),
                        ),
                        $cmd instanceof Forfeit     => self::persistAndReply(
                            $replyTo,
                            static fn() => $game->forfeit($cmd->playerId),
                        ),
                        $cmd instanceof GetSnapshot => self::snapshotOnly($game, $replyTo),
                        default                     => throw new LogicException(
                            'unhandled GameCommand: ' . $cmd::class,
                        ),
                    };

                    $log->info('game command applied', [
                        'gameId' => $game->id(),
                        'command' => $cmd::class,
                        'statusAfter' => $game->status()->value,
                    ]);

                    return $effect;
                } catch (GameDomainException $e) {
                    $log->warning('game command rejected by aggregate', [
                        'gameId' => $game->id(),
                        'command' => $cmd::class,
                        'error' => $e::class . ': ' . $e->getMessage(),
                    ]);
                    $replyTo->tell(new GameRejected($e->getMessage()));

                    return EntityEffect::same();
                }
            };
    }

    /**
     * @param ActorRef<object> $replyTo
     * @param Closure(): void $mutate
     * @return EntityEffect<GameSession>
     */
    private static function persistAndReply(ActorRef $replyTo, Closure $mutate): EntityEffect
    {
        $mutate();

        return EntityEffect::persist()->thenReply(
            $replyTo,
            static fn(GameSession $g) => $g->toSnapshot(),
        );
    }

    /**
     * @param ActorRef<object> $replyTo
     * @return EntityEffect<GameSession>
     */
    private static function snapshotOnly(GameSession $game, ActorRef $replyTo): EntityEffect
    {
        $replyTo->tell($game->toSnapshot());

        return EntityEffect::same();
    }
}
