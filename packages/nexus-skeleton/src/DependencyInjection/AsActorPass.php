<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Monadial\Nexus\App\ActorRegistry;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AsActorPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ActorRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(ActorRegistry::class);

        foreach ($container->findTaggedServiceIds('nexus.actor') as $id => $tags) {
            /** @var array<string, mixed> $tag */
            foreach ($tags as $tag) {
                $registry->addMethodCall('register', [(string) $tag['name'], $id]);
            }
        }
    }
}
