<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

use Monadial\Nexus\Http\Exception\DuplicateRouteNameException;

/**
 * @psalm-api
 *
 * Mutable collection used during boot. Frozen at HttpApp::compile().
 */
final class RouteCollection
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    public function add(Route $route): void
    {
        if ($route->name !== null) {
            if (isset($this->named[$route->name])) {
                throw new DuplicateRouteNameException($route->name);
            }

            $this->named[$route->name] = $route;
        }

        $this->routes[] = $route;
    }

    /** @return list<Route> */
    public function all(): array
    {
        return $this->routes;
    }

    public function findByName(string $name): ?Route
    {
        return $this->named[$name] ?? null;
    }
}
