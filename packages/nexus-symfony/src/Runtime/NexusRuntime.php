<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Runtime;

use Override;
use ReflectionFunction;
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

    /** @param array<string, mixed> $options */
    public function __construct(private readonly array $options = []) {}

    #[Override]
    public function getResolver(callable $callable, ?ReflectionFunction $reflector = null): ResolverInterface
    {
        $closure   = $callable(...);
        $arguments = static fn(): array => [];

        return new ClosureResolver($closure, $arguments);
    }

    #[Override]
    public function getRunner(mixed $application): RunnerInterface
    {
        return new NexusRunner(
            $application,
            array_merge(self::DEFAULT_OPTIONS, $this->options),
        );
    }
}
