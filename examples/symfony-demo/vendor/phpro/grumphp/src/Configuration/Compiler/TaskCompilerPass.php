<?php

declare(strict_types=1);

namespace GrumPHP\Configuration\Compiler;

use GrumPHP\Collection\TasksCollection;
use GrumPHP\Configuration\Configurator\TaskConfigurator;
use GrumPHP\Configuration\Resolver\TaskConfigResolver;
use GrumPHP\Exception\TaskConfigResolverException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TaskCompilerPass implements CompilerPassInterface
{
    private const TAG_GRUMPHP_TASK = 'grumphp.task';

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        $tasksCollection = $container->findDefinition(TasksCollection::class);
        $availableTasks = $this->fetchAvailableTasksInfo($container);
        $configuredTasks = $container->getParameter('tasks');
        $configuredTasks = is_array($configuredTasks) ? $configuredTasks : [];
        $taskResolverConfig = [];

        // Configure tasks
        foreach ($configuredTasks as $taskName => $config) {
            $taskConfig = $config ?? [];
            $metadataConfig = (array) ($taskConfig['metadata'] ?? []);
            $currentTaskName = ((string) ($metadataConfig['task'] ?? '')) ?: $taskName;
            if (!array_key_exists($currentTaskName, $availableTasks)) {
                throw TaskConfigResolverException::unknownTask($currentTaskName);
            }

            // Determine Keys:
            $currentTaskService = $availableTasks[$currentTaskName];
            ['id' => $taskId, 'class' => $taskClass, 'info' => $taskInfo] = $currentTaskService;
            $configuredTaskKey = $taskId.'.'.$taskName.'.configured';

            // Store the configuration in the task resolver config:
            // This way, the resolver knows how to build all task related configurations.
            // It is stores in a pain array so that env variables get resolved and can be used in the configuration.
            $taskResolverConfig[$taskName] = [
                'class' => $taskClass,
                'config' => array_merge(
                    $taskConfig,
                    [
                        'metadata' => array_merge(
                            ['priority' => $taskInfo['priority']],
                            $taskConfig['metadata'] ?? [],
                        ),
                    ],
                )
            ];

            // Configure task:
            $taskBuilder = new Definition($taskClass, [
                new Reference($taskId),
                new Reference(TaskConfigResolver::class),
                $taskName,
            ]);
            $taskBuilder->setFactory([new Reference(TaskConfigurator::class), '__invoke']);
            $taskBuilder->addTag('configured.task');

            // Register services:
            $container->setDefinition($configuredTaskKey, $taskBuilder);
            $tasksCollection->addMethodCall('add', [new Reference($configuredTaskKey)]);
        }

        // Register available and configured tasks for easy data usage in the application:
        $container->setDefinition(TaskConfigResolver::class, new Definition(
            TaskConfigResolver::class,
            [$taskResolverConfig]
        ));
        $container->setParameter('grumphp.tasks.configured', array_keys($configuredTasks));
    }

    private function getTaskTag(array $tag): array
    {
        static $taskTagResolver;
        if (null === $taskTagResolver) {
            $taskTagResolver = new OptionsResolver();

            $taskTagResolver->setRequired(['task']);
            $taskTagResolver->setDefined(['aliasFor', 'priority']);
            $taskTagResolver->setAllowedTypes('task', ['string']);
            $taskTagResolver->setAllowedTypes('aliasFor', ['string', 'null']);
            $taskTagResolver->setAllowedTypes('priority', ['int']);
            $taskTagResolver->setDefault('priority', 0);
        }

        return $taskTagResolver->resolve($tag);
    }

    private function fetchAvailableTasksInfo(ContainerBuilder $container): array
    {
        $map = [];
        $taggedServices = $container->findTaggedServiceIds(self::TAG_GRUMPHP_TASK);

        foreach ($taggedServices as $serviceId => $tags) {
            $definition = $container->findDefinition($serviceId);
            // Make sure to set shared to false so that a new instance is always returned
            $definition->setShared(false);

            foreach ($tags as $tag) {
                $taskInfo = $this->getTaskTag($tag);
                $name = $taskInfo['task'];
                $class = $definition->getClass();

                $map[$name] = [
                    'id' => $serviceId,
                    'class' => $class,
                    'task' => $name,
                    'info' => $taskInfo,
                ];
            }
        }

        return $map;
    }
}
