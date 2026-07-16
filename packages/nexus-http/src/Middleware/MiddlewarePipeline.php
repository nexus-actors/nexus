<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * @psalm-api
 *
 * Runs a PSR-15 middleware chain. Accepts already-resolved
 * {@see MiddlewareInterface} instances OR class strings, lazily resolving the
 * latter via {@see MiddlewareResolver} and caching them across calls.
 *
 * Route-level middlewares are pre-resolved at compile time and arrive as
 * instances; global middlewares may still arrive as class strings.
 */
final class MiddlewarePipeline
{
    /** @var array<string, MiddlewareInterface> */
    private array $instances = [];

    private readonly MiddlewareResolver $resolver;

    public function __construct(?ContainerInterface $container)
    {
        $this->resolver = new MiddlewareResolver($container);
    }

    /**
     * @param list<string|MiddlewareInterface> $middlewares
     * @param Closure(ServerRequestInterface): ResponseInterface $tail
     */
    public function process(array $middlewares, ServerRequestInterface $request, Closure $tail): ResponseInterface
    {
        $resolved = [];

        foreach ($middlewares as $mw) {
            $resolved[] = $mw instanceof MiddlewareInterface
                ? $mw
                : $this->resolveCached($mw);
        }

        return (new MiddlewareInvoker($resolved, $tail))->handle($request);
    }

    private function resolveCached(string $class): MiddlewareInterface
    {
        if (isset($this->instances[$class])) {
            return $this->instances[$class];
        }

        $instance = $this->resolver->resolve($class);
        $this->instances[$class] = $instance;

        return $instance;
    }
}
