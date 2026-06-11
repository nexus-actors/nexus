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
 * Resolves middleware class strings to MiddlewareInterface instances and
 * runs the PSR-15 chain.
 */
final class MiddlewarePipeline
{
    /** @var array<class-string, MiddlewareInterface> */
    private array $instances = [];

    public function __construct(private readonly ?ContainerInterface $container) {}

    /**
     * @param list<string|MiddlewareInterface> $middlewares
     * @param Closure(ServerRequestInterface): ResponseInterface $tail
     */
    public function process(array $middlewares, ServerRequestInterface $request, Closure $tail): ResponseInterface
    {
        $resolved = [];

        foreach ($middlewares as $mw) {
            /** @psalm-suppress ArgumentTypeCoercion */
            $resolved[] = $mw instanceof MiddlewareInterface
                ? $mw
                : $this->resolve($mw);
        }

        return (new MiddlewareInvoker($resolved, $tail))->handle($request);
    }

    /** @param class-string $class */
    private function resolve(string $class): MiddlewareInterface
    {
        if (isset($this->instances[$class])) {
            return $this->instances[$class];
        }

        /**
         * @var MiddlewareInterface $instance
         * @psalm-suppress MixedMethodCall
         */
        $instance = $this->container !== null && $this->container->has($class)
            ? $this->container->get($class)
            : new $class();

        $this->instances[$class] = $instance;

        return $instance;
    }
}
