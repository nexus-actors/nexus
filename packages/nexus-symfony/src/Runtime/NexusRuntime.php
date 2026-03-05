<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Runtime;

use Closure;
use Override;
use ReflectionFunction;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Runtime\Resolver\ClosureResolver;
use Symfony\Component\Runtime\ResolverInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\RuntimeInterface;

final class NexusRuntime implements RuntimeInterface
{
    private const array DEFAULT_OPTIONS = [
        'host'    => '0.0.0.0',
        'port'    => 8080,
        'workers' => 4,
    ];

    /**
     * Stored by getResolver() so getRunner() can boot a fresh kernel per worker.
     * If getResolver() is never called, getRunner() falls back to wrapping the application directly.
     *
     * @psalm-var null|(Closure(): HttpKernelInterface)
     */
    private ?Closure $kernelFactory = null;

    /** @param array<string, mixed> $options */
    public function __construct(private readonly array $options = []) {}

    /**
     * @psalm-suppress MixedPropertyTypeCoercion
     */
    #[Override]
    public function getResolver(callable $callable, ?ReflectionFunction $reflector = null): ResolverInterface
    {
        $closure = $callable(...);
        $this->kernelFactory = $closure;
        $arguments = static fn(): array => [];

        return new ClosureResolver($closure, $arguments);
    }

    /**
     * @psalm-suppress MissingClosureReturnType
     */
    #[Override]
    public function getRunner(mixed $application): RunnerInterface
    {
        $factory = $this->kernelFactory ?? static fn() => $application;

        return new NexusRunner(
            $factory,
            array_merge(self::DEFAULT_OPTIONS, $this->options),
        );
    }
}
