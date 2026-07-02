<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http;

use Monadial\Nexus\Example\TicTacToe\Actor\GameRefFactory;
use Monadial\Nexus\Example\TicTacToe\Domain\Exception\GameDomainException;
use Monadial\Nexus\Example\TicTacToe\Http\Handler\CreateGameHandler;
use Monadial\Nexus\Example\TicTacToe\Http\Handler\GameStateHandler;
use Monadial\Nexus\Example\TicTacToe\Http\Handler\IndexHandler;
use Monadial\Nexus\Example\TicTacToe\Http\Handler\ListGamesHandler;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\ClientFrameCodec;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\GameChannelActor;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Ws\WsApplication;
use Monadial\Nexus\Serialization\MessageSerializer;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

use function json_encode;

/**
 * All routes in one place.
 *
 *  - `/`, `/health` — SPA + liveness
 *  - `/api/games`   — lobby list + create
 *  - `/api/games/{id}` — one-shot snapshot
 *  - `/ws/games/{id}`  — persistent WebSocket, {id} keys the GameChannelActor
 */
final class Routes
{
    public static function register(
        WsApplication $app,
        GameRefFactory $gameFactory,
        MessageSerializer $serializer,
        IndexHandler $index,
        LoggerInterface $log,
    ): void {
        $app->get('/health', static fn(): ResponseInterface => Response::ok());
        $app->get('/', $index(...));

        $app->onException(
            GameDomainException::class,
            static fn(GameDomainException $e): Psr7Response => new Psr7Response(
                409,
                ['content-type' => 'application/json'],
                (string) json_encode(['error' => $e->getMessage()]),
            ),
        );

        $app->get('/api/games', ListGamesHandler::class);
        $app->post('/api/games', CreateGameHandler::class);
        $app->get('/api/games/{id}', GameStateHandler::class);

        $codec = new ClientFrameCodec($serializer);

        $app->channel(
            '/ws/games/{id}',
            GameChannelActor::class,
            key: 'id',
            factory: static fn(): GameChannelActor => new GameChannelActor($gameFactory, $codec, $log),
        );
    }
}
