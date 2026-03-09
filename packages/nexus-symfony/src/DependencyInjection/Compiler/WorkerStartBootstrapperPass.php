<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\DependencyInjection\Compiler;

use Monadial\Nexus\Symfony\Runtime\WorkerStartBootstrapper;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class WorkerStartBootstrapperPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        $ids = array_keys($container->findTaggedServiceIds('nexus.worker_start'));

        foreach ($ids as $id) {
            $container->getDefinition($id)->setPublic(true);
        }

        $container->setParameter('nexus.worker_start_bootstrappers', $ids);
    }
}
