<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Example\TicTacToe\Domain\Entity\GameSession;
use Monadial\Nexus\Example\TicTacToe\Domain\Value\GameStatus;
use Monadial\Nexus\Example\TicTacToe\Http\Response\GameListResponse;
use Monadial\Nexus\Example\TicTacToe\Http\Response\GameSummary;
use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

use function array_map;

/**
 * `GET /api/games` — lists open lobbies and active games, newest first.
 * The `(status, created_at)` composite index on {@see GameSession} keeps
 * this cheap as the table grows.
 */
final class ListGamesHandler
{
    private const int PAGE_SIZE = 50;

    public function __invoke(EntityManagerInterface $em): ResponseInterface
    {
        $liveValues = array_map(
            static fn(GameStatus $s): string => $s->value,
            [GameStatus::WaitingForOpponent, GameStatus::InProgress],
        );

        /** @var list<GameSession> $games */
        $games = $em->createQueryBuilder()
            ->select('g')
            ->from(GameSession::class, 'g')
            ->where('g.status IN (:live)')
            ->setParameter('live', $liveValues)
            ->orderBy('g.createdAt', 'DESC')
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        $summaries = array_map(
            static fn(GameSession $g): GameSummary => new GameSummary(
                gameId: $g->id(),
                status: $g->status()->value,
                playerX: $g->playerX()?->name,
                playerO: $g->playerO()?->name,
            ),
            $games,
        );

        return JsonResponse::ok(new GameListResponse($summaries));
    }
}
