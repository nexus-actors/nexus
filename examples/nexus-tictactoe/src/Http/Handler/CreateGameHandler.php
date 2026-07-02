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
 * `POST /api/games` — mint a game id and seed the lobby read-model row.
 *
 * The Ulid is globally unique, so this INSERT never races another creator.
 * Gameplay then flows through the per-id, event-sourced `GameActor`
 * (single writer per game); this `games` row is only the lobby projection,
 * refreshed as the actor applies events (see {@see GameSession}).
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
