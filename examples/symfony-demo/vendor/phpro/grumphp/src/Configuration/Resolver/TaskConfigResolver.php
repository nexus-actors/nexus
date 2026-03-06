<?php

declare(strict_types=1);

namespace GrumPHP\Configuration\Resolver;

use GrumPHP\Exception\TaskConfigResolverException;
use GrumPHP\Task\Config\ConfigOptionsResolver;
use GrumPHP\Task\Config\Metadata;
use GrumPHP\Task\Config\TaskConfig;
use GrumPHP\Task\Config\TaskConfigInterface;
use GrumPHP\Task\TaskInterface;

/**
 * @psalm-type TaskConfiguration = array{
 *     class: string,
 *     config: array
 * }
 */
class TaskConfigResolver
{
    /**
     * @var array<string, TaskConfiguration>
     */
    private $taskMap;

    public function __construct(array $taskMap)
    {
        $this->taskMap = $taskMap;
    }

    /**
     * @return array<string>
     */
    public function listAvailableTaskNames(): array
    {
        return array_keys($this->taskMap);
    }

    public function resolve(string $taskName): TaskConfigInterface
    {
        $resolver = $this->fetchByName($taskName);

        $config = $this->taskMap[$taskName]['config'] ?? [];
        $metadata = new Metadata($config['metadata'] ?? []);

        unset($config['metadata']);
        $resolvedConfig = $resolver->resolve($config);

        return new TaskConfig(
            $taskName,
            $resolvedConfig,
            $metadata
        );
    }

    public function fetchByName(string $taskName): ConfigOptionsResolver
    {
        if (!array_key_exists($taskName, $this->taskMap)) {
            throw TaskConfigResolverException::unknownTask($taskName);
        }

        $class = $this->taskMap[$taskName]['class'] ?? '';
        if (!$class || !class_exists($class) || !is_subclass_of($class, TaskInterface::class)) {
            throw TaskConfigResolverException::unknownClass($class);
        }

        return $class::getConfigurableOptions();
    }
}
