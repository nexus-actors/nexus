<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Dsl;

use Closure;
use Monadial\Nexus\Http\Routing\Route;

/**
 * @psalm-api
 *
 * Group of routes sharing a prefix and middleware stack. Created via
 * HttpApp::group(); routes added here are committed back to the parent
 * collection with prefix + group MWs prepended.
 */
final class RouteGroup
{
    /** @var list<string> */
    private array $middleware = [];

    /** @var list<RouteBuilder> */
    private array $pending = [];

    public function __construct(private readonly string $prefix) {}

    /** @return list<Route> */
    public function commit(): array
    {
        $out = [];

        foreach ($this->pending as $builder) {
            $route = $builder->build()->withPrefixedPath($this->prefix);

            if ($this->middleware !== []) {
                $route = new Route(
                    $route->method,
                    $route->path,
                    $route->handler,
                    [...$this->middleware, ...$route->middleware],
                    $route->name,
                );
            }

            $out[] = $route;
        }

        return $out;
    }

    public function delete(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->register('DELETE', $path, $handler);
    }

    public function get(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->register('GET', $path, $handler);
    }

    public function middleware(string $class): self
    {
        $this->middleware[] = $class;

        return $this;
    }

    public function patch(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->register('PATCH', $path, $handler);
    }

    public function post(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->register('POST', $path, $handler);
    }

    public function put(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->register('PUT', $path, $handler);
    }

    private function register(string $method, string $path, string|Closure $handler): RouteBuilder
    {
        $builder = new RouteBuilder($method, $path, $handler);
        $this->pending[] = $builder;

        return $builder;
    }
}
