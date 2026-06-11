<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

/**
 * @psalm-api
 *
 * FastRoute dispatcher over WebSocket upgrade paths. Returns a matched
 * WebSocketRoute plus extracted path parameters.
 */
final class WebSocketRouter
{
    /** @param array<int, WebSocketRoute> $routes */
    private function __construct(
        private readonly Dispatcher $delegate,
        private readonly array $routes,
    ) {
    }

    /** @param list<WebSocketRoute> $routes */
    public static function build(array $routes): self
    {
        $byId = [];
        $dispatcher = simpleDispatcher(static function (RouteCollector $r) use ($routes, &$byId): void {
            foreach ($routes as $id => $route) {
                $byId[$id] = $route;
                $r->addRoute('GET', $route->path, $id);
            }
        });

        /** @var array<int, WebSocketRoute> $byId */
        return new self($dispatcher, $byId);
    }

    /** @return array{route: WebSocketRoute, params: array<string,string>}|null */
    public function match(string $path): ?array
    {
        $info = $this->delegate->dispatch('GET', $path);

        if ($info[0] !== Dispatcher::FOUND) {
            return null;
        }

        /** @var int $id */
        $id = $info[1];
        /** @var array<string,string> $params */
        $params = $info[2];

        return ['params' => $params, 'route' => $this->routes[$id]];
    }
}
