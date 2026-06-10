<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

use FastRoute\Dispatcher as FastRouteDispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

/**
 * @psalm-api
 *
 * Thin wrapper over FastRoute. Stores Route objects in a side table so the
 * matched id can be resolved back to the Route. (FastRoute returns the
 * handler payload — we pass the route id and look up by id.)
 */
final class Dispatcher
{
    /** @param array<int, Route> $routes */
    private function __construct(private readonly FastRouteDispatcher $delegate, private readonly array $routes) {}

    /** @param list<Route> $routes */
    public static function build(array $routes): self
    {
        $byId = [];
        $dispatcher = simpleDispatcher(static function (RouteCollector $r) use ($routes, &$byId): void {
            foreach ($routes as $id => $route) {
                $byId[$id] = $route;
                $r->addRoute($route->method, $route->path, $id);
            }
        });

        return new self($dispatcher, $byId);
    }

    public function dispatch(string $method, string $path): DispatchResult
    {
        $info = $this->delegate->dispatch($method, $path);

        return match ($info[0]) {
            FastRouteDispatcher::FOUND              => DispatchResult::found($this->routes[$info[1]], $info[2]),
            FastRouteDispatcher::METHOD_NOT_ALLOWED => DispatchResult::methodNotAllowed($info[1]),
            default                                 => DispatchResult::notFound(),
        };
    }
}
