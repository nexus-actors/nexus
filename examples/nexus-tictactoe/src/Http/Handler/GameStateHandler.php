<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Example\TicTacToe\Domain\Entity\GameSession;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\SnapshotPayload;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Uid\Ulid;

use function is_string;

/**
 * `GET /api/games/{id}` — one-shot snapshot for spectators and reload
 * paths. Reads the `games` lobby read model via the pooled EM; the
 * event-sourced `GameActor` keeps that row current by projecting each new
 * state after its events are persisted.
 *
 * Returns the name-only {@see SnapshotPayload} — never the seat ids, which
 * are capability tokens. Same privacy boundary as the WebSocket broadcast.
 */
final class GameStateHandler
{
    public function __invoke(ServerRequestInterface $request, EntityManagerInterface $em): ResponseInterface
    {
        $id = $request->getAttribute('id');

        if (!is_string($id) || !Ulid::isValid($id)) {
            return Response::badRequest('id must be a ULID');
        }

        $game = $em->find(GameSession::class, $id);

        if ($game === null) {
            return Response::notFound('game not found');
        }

        return JsonResponse::ok(SnapshotPayload::of($game->toSnapshot()));
    }
}
