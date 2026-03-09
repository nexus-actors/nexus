<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Doctrine\Compiler;

use Monadial\Nexus\Symfony\Doctrine\SwooleCoroutinePdoPool;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class DoctrineCompilerPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!extension_loaded('swoole')) {
            return;
        }

        $raw = $container->hasParameter('nexus.doctrine.connections_per_worker')
            ? $container->getParameter('nexus.doctrine.connections_per_worker')
            : 2;
        $connectionsPerWorker = is_int($raw) ? $raw : (int) (string) $raw;

        $container->setDefinition(
            'nexus.doctrine.pdo_pool',
            (new Definition(SwooleCoroutinePdoPool::class))
                ->setArguments([
                    new Reference('doctrine.dbal.default_connection'),
                    $connectionsPerWorker,
                ])
                ->setPublic(false),
        );
    }
}
