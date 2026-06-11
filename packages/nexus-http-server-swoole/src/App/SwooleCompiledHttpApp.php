<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\App;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRouter;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @psalm-api
 *
 * Wraps a CompiledHttpApp + WebSocketRouter + ActorSystem. Implements
 * RequestHandlerInterface by delegating to the wrapped CompiledHttpApp.
 * Server adapters use it for both HTTP and WebSocket dispatching.
 */
final readonly class SwooleCompiledHttpApp implements RequestHandlerInterface
{
    public function __construct(
        private CompiledHttpApp $http,
        private WebSocketRouter $webSocketRouter,
        private ActorSystem $actorSystem,
    ) {
    }

    public function actorSystem(): ActorSystem
    {
        return $this->actorSystem;
    }

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->http->handle($request);
    }

    public function webSocketRouter(): WebSocketRouter
    {
        return $this->webSocketRouter;
    }
}
