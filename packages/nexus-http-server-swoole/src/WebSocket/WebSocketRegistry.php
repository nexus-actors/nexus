<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

/**
 * @psalm-api
 *
 * Boot-time WebSocket route accumulator. Frozen at SwooleHttpApp::compile()
 * into a WebSocketRouter.
 */
final class WebSocketRegistry
{
    /** @var list<WebSocketRoute> */
    private array $routes = [];

    public function add(WebSocketRoute $route): void
    {
        $this->routes[] = $route;
    }

    /** @return list<WebSocketRoute> */
    public function all(): array
    {
        return $this->routes;
    }
}
