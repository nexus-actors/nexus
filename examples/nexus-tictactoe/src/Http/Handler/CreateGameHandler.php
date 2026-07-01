<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Example\TicTacToe\Domain\Entity\GameSession;
use Monadial\Nexus\Example\TicTacToe\Http\Response\CreateGameResponse;
use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Uid\Ulid;

/**
 * `POST /api/games` — mint a game id and persist an empty row.
 *
 * The Ulid is globally unique, so this INSERT never races another
 * creator; every subsequent mutation flows through the per-id `GameActor`
 * whose `#[Version]` column enforces cross-worker single-writer safety
 * via optimistic-lock retry (see {@see GameSession}).
 */
final class CreateGameHandler
{
    public function __invoke(EntityManagerInterface $em): ResponseInterface
    {
        $gameId = (string) new Ulid();

        $em->persist(new GameSession($gameId));
        $em->flush();

        return JsonResponse::ok(new CreateGameResponse(
            gameId: $gameId,
            wsUrl: '/ws/games/' . $gameId,
        ));
    }
}
