<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Dsl;

use Closure;
use Monadial\Nexus\Http\Routing\Route;

/**
 * @psalm-api
 *
 * Fluent setter for one route. Mutating until HttpApp::compile() freezes
 * it into a Route value object.
 */
final class RouteBuilder
{
    /** @var list<string> */
    private array $middleware = [];

    private ?string $name = null;

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly string|Closure $handler,
    ) {}

    public function build(): Route
    {
        return new Route($this->method, $this->path, $this->handler, $this->middleware, $this->name);
    }

    public function middleware(string $class): self
    {
        $this->middleware[] = $class;

        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }
}
