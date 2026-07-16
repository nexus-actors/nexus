<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;
use ReflectionClass;

use function is_subclass_of;

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

    public function resolve(string $class): MiddlewareInterface
    {
        if ($this->container !== null && $this->container->has($class)) {
            /** @var MiddlewareInterface */
            return $this->container->get($class);
        }

        if (!is_subclass_of($class, MiddlewareInterface::class)) {
            throw new LogicException("{$class} must implement " . MiddlewareInterface::class);
        }

        // Reflection-based construction keeps the dynamic class-string
        // instantiation type-safe; results are cached by the callers.
        return new ReflectionClass($class)->newInstance();
    }
}
