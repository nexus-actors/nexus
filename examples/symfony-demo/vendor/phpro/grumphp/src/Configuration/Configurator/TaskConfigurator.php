<?php

declare(strict_types=1);

namespace GrumPHP\Configuration\Configurator;

use GrumPHP\Configuration\Resolver\TaskConfigResolver;
use GrumPHP\Task\TaskInterface;

class TaskConfigurator
{
    public function __invoke(
        TaskInterface $task,
        TaskConfigResolver $configResolver,
        string $taskName
    ): TaskInterface {
        return $task->withConfig(
            $configResolver->resolve($taskName)
        );
    }
}
