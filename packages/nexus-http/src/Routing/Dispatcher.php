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
final readonly class Dispatcher
{
    /** @param array<int, Route> $routes */
    private function __construct(private FastRouteDispatcher $delegate, private array $routes) {}

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
        /**
         * FastRoute returns one of three shapes; the variant is fully
         * determined by $info[0]. Psalm's runtime type for the underlying
         * dispatcher call is `array<int, mixed>` so we annotate per branch.
         *
         * @var array<int, mixed> $info
         */
        $info = $this->delegate->dispatch($method, $path);

        if ($info[0] === FastRouteDispatcher::FOUND) {
            /** @var array{int, int, array<string, string>} $info */
            return DispatchResult::found($this->routes[$info[1]], $info[2]);
        }

        if ($info[0] === FastRouteDispatcher::METHOD_NOT_ALLOWED) {
            /** @var array{int, list<string>} $info */
            return DispatchResult::methodNotAllowed($info[1]);
        }

        return DispatchResult::notFound();
    }
}
