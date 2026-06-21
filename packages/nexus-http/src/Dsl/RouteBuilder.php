<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Dsl;

use Closure;
use Monadial\Nexus\Http\Routing\Route;

/**
 * Fluent per-route configuration builder returned by HTTP verb methods.
 *
 * {@see RouteBuilder} is the object you receive after calling
 * `$app->get(...)`, `$app->post(...)`, etc. It lets you chain per-route
 * settings — middleware and a named route identifier — before
 * {@see HttpApp::compile()} freezes the builder into an immutable
 * {@see Route} value object that drives the dispatcher.
 *
 * The builder is intentionally mutable; the terminal `compile()` call on
 * `HttpApp` converts all pending builders atomically and nothing else holds
 * a reference to the builder after that point.
 *
 * Example — adding per-route middleware and a name:
 * ```php
 * $app->post('/users', UserHandler::class)
 *     ->middleware(AuthenticationMiddleware::class)
 *     ->middleware(RateLimitMiddleware::class)
 *     ->name('user.create');
 * ```
 *
 * @see HttpApp::get()         Returns a RouteBuilder for GET routes
 * @see HttpApp::post()        Returns a RouteBuilder for POST routes
 * @see HttpApp::compile()     Freezes all pending builders into the compiled app
 *
 * @psalm-api
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

    /**
     * Freeze this builder into an immutable Route value object.
     *
     * Called internally by {@see HttpApp::compile()}; application code
     * should not need to call this directly.
     */
    public function build(): Route
    {
        return new Route($this->method, $this->path, $this->handler, $this->middleware, $this->name);
    }

    /**
     * Append a PSR-15 middleware class name to this route's middleware chain.
     *
     * Route middleware runs after global middleware and before the handler.
     * Multiple calls append in order.
     *
     * @param string $class Fully-qualified class name of the PSR-15 middleware.
     */
    public function middleware(string $class): self
    {
        $this->middleware[] = $class;

        return $this;
    }

    /**
     * Assign a human-readable name to this route for reverse routing and logging.
     *
     * @param string $name Unique route name within the application.
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }
}
