<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

/**
 * @psalm-api
 *
 * Public, serialisable view of one registered route. Returned by
 * {@see \Monadial\Nexus\Http\Dsl\HttpApp::registeredRoutes()} for index
 * pages, smoke tests, and admin tooling that need to enumerate the
 * surface area without coupling to internal {@see Route} state.
 */
final readonly class RouteSummary
{
    public function __construct(
        public string $method,
        public string $path,
        public ?string $name,
        public string $handler,
    ) {}

    public static function fromRoute(Route $route): self
    {
        return new self(
            method: $route->method,
            path: $route->path,
            name: $route->name,
            handler: is_string($route->handler)
                ? $route->handler
                : 'Closure',
        );
    }
}
