<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws;

use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketDispatcher;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRouter;
use Override;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
final readonly class CompiledWsApplication implements CompiledApplication
{
    public function __construct(
        private CompiledHttpApp $http,
        private WebSocketRouter $router,
        private WebSocketDispatcher $dispatcher,
        private ContainerInterface $container,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->http->handle($request);
    }

    #[Override]
    public function hasWebSocketRoutes(): bool
    {
        return $this->router->routes() !== [];
    }

    public function webSocketRouter(): WebSocketRouter
    {
        return $this->router;
    }

    public function dispatcher(): WebSocketDispatcher
    {
        return $this->dispatcher;
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }
}
