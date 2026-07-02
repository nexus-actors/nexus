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
use Monadial\Nexus\Example\TicTacToe\Actor\Message\Seated;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\Forfeit;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\GetSnapshot;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\JoinGame;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\MakeMove;
use Monadial\Nexus\Example\TicTacToe\Domain\Entity\GameSession;
use Monadial\Nexus\Example\TicTacToe\Domain\Exception\GameDomainException;
use Psr\Log\LoggerInterface;

/**
 * Command handler for the per-game aggregate — single writer per game id.
 *
 * Dispatches the enclosed {@see \Monadial\Nexus\Example\TicTacToe\Domain\Command\GameCommand}
 * against {@see GameSession} and replies with one of three actor-layer
 * messages, each routed by the channel actor:
 *  - {@see Seated} — a successful join; broadcast state + private welcome.
 *  - {@see GameSnapshot} — a move/forfeit success or a read; the new state.
 *  - {@see GameRejected} — a domain-rule violation, carrying the offending
 *    `fd` so the error reaches only that client.
 *
 * No Doctrine knowledge lives here: {@see \Monadial\Nexus\Example\TicTacToe\Boot\DoctrineKit}
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
                $fd = $env->originFd;

                $log->info('game command received', [
                    'gameId' => $game->id(),
                    'command' => $cmd::class,
                    'fd' => $fd,
                    'statusBefore' => $game->status()->value,
                ]);

                try {
                    $effect = match (true) {
                        $cmd instanceof JoinGame => self::persist(
                            $replyTo,
                            static fn() => $game->join($cmd->playerId, $cmd->playerName),
                            static fn(GameSession $g): Seated => new Seated($g->toSnapshot(), $fd, $cmd->playerId),
                        ),
                        $cmd instanceof MakeMove => self::persist(
                            $replyTo,
                            static fn() => $game->makeMove($cmd->playerId, $cmd->cellIndex),
                            static fn(GameSession $g) => $g->toSnapshot(),
                        ),
                        $cmd instanceof Forfeit => self::persist(
                            $replyTo,
                            static fn() => $game->forfeit($cmd->playerId),
                            static fn(GameSession $g) => $g->toSnapshot(),
                        ),
                        $cmd instanceof GetSnapshot => self::read($game, $replyTo),
                        default => throw new LogicException('unhandled GameCommand: ' . $cmd::class),
                    };

                    $log->info('game command applied', [
                        'command' => $cmd::class,
                        'gameId' => $game->id(),
                        'statusAfter' => $game->status()->value,
                    ]);

                    return $effect;
                } catch (GameDomainException $e) {
                    $log->warning('game command rejected by aggregate', [
                        'command' => $cmd::class,
                        'error' => $e::class . ': ' . $e->getMessage(),
                        'gameId' => $game->id(),
                    ]);
                    $replyTo->tell(new GameRejected($e->getMessage(), $fd));

                    return EntityEffect::same();
                }
            };
    }

    /**
     * @param ActorRef<object> $replyTo
     * @param Closure(): void $mutate
     * @param Closure(GameSession): object $reply
     * @return EntityEffect<GameSession>
     */
    private static function persist(ActorRef $replyTo, Closure $mutate, Closure $reply): EntityEffect
    {
        $mutate();

        return EntityEffect::persist()->thenReply($replyTo, $reply);
    }

    /**
     * @param ActorRef<object> $replyTo
     * @return EntityEffect<GameSession>
     */
    private static function read(GameSession $game, ActorRef $replyTo): EntityEffect
    {
        $replyTo->tell($game->toSnapshot());

        return EntityEffect::same();
    }
}
