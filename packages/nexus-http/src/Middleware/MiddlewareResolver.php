<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * @psalm-api
 *
 * Resolves middleware class strings to MiddlewareInterface instances via a
 * PSR-11 container when available, falling back to no-arg construction.
 * Used by both HttpApp::compile() (to pre-resolve route middlewares) and
 * MiddlewarePipeline::process() (to lazily resolve global middlewares).
 */
final readonly class MiddlewareResolver
{
    public function __construct(private ?ContainerInterface $container) {}

    /** @param class-string $class */
    public function resolve(string $class): MiddlewareInterface
    {
        if ($this->container !== null && $this->container->has($class)) {
            /** @var MiddlewareInterface */
            return $this->container->get($class);
        }

        /**
         * @var MiddlewareInterface
         * @psalm-suppress MixedMethodCall
         */
        return new $class();
    }
}
