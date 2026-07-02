<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Monadial\Nexus\Http\Ws\WebSocket\Exception\UnsupportedRouteException;

use function array_values;
use function FastRoute\simpleDispatcher;

/** @psalm-api */
final readonly class WebSocketRouter
{
    /** @param array<int, WebSocketRoute> $routes */
    private function __construct(private Dispatcher $delegate, private array $routes) {}

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

    /** @return list<WebSocketRoute> */
    public function routes(): array
    {
        return array_values($this->routes);
    }

    /** @return array{params: array<string,string>, route: WebSocketRoute}|null */
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

        return [
            'params' => $params,
            'route' => $this->routes[$id],
        ];
    }

    public function assertNoChannelRoutes(): void
    {
        foreach ($this->routes as $route) {
            if ($route->mode === WebSocketRoute::MODE_CHANNEL) {
                throw new UnsupportedRouteException(
                    "WebSocket channel-actor routes are not supported in this runtime "
                    . "(route '{$route->path}'). Use handler-mode WebSocket here, "
                    . 'or switch to the worker-mode runner for channel actors.',
                );
            }
        }
    }
}
