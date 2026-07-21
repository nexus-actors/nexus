<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws;

use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Middleware\MiddlewareResolver;
use Monadial\Nexus\Http\Ws\WebSocket\HandshakeGate;
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
        private ?HandshakeGate $handshakeGate = null,
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

    /**
     * The pre-upgrade authorization gate servers must consult before
     * completing the WebSocket handshake. Applications compiled without an
     * explicit gate get a middleware-free gate over the same router: unmatched
     * paths are still rejected with 404 before the 101 switch.
     */
    public function handshakeGate(): HandshakeGate
    {
        return $this->handshakeGate ?? new HandshakeGate($this->router, [], new MiddlewareResolver($this->container));
    }
}
