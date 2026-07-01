<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Example\TicTacToe\Domain\Entity\GameSession;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function is_string;

/**
 * `GET /api/games/{id}` — one-shot snapshot for spectators and reload
 * paths. Reads the committed row via the pooled EM; concurrent moves
 * inside the `GameActor` are safely observable because the version
 * column keeps the row consistent per-commit.
 */
final class GameStateHandler
{
    public function __invoke(ServerRequestInterface $request, EntityManagerInterface $em): ResponseInterface
    {
        $id = $request->getAttribute('id');

        if (!is_string($id) || $id === '') {
            return Response::badRequest('missing id');
        }

        $game = $em->find(GameSession::class, $id);

        if ($game === null) {
            return Response::notFound('game not found');
        }

        return JsonResponse::ok($game->toSnapshot());
    }
}
