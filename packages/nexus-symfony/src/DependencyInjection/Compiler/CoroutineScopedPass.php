<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\DependencyInjection\Compiler;

use Monadial\Nexus\Symfony\Attribute\CoroutineScoped;
use Override;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class CoroutineScopedPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, string> $scopedIds service ID → service ID */
        $scopedIds = [];

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();

            if ($class === null) {
                continue;
            }

            try {
                $ref   = new ReflectionClass($class);
                $attrs = $ref->getAttributes(CoroutineScoped::class);
            } catch (ReflectionException) {
                continue;
            }

            if ($attrs === []) {
                continue;
            }

            // Prototype scope: each $container->get() returns a fresh instance.
            // CoroutineScopeListener calls get() once per coroutine and caches
            // the result in Coroutine::getContext() — so each request gets its
            // own instance.
            $definition->setShared(false);
            $scopedIds[$serviceId] = $serviceId;
        }

        $container->setParameter('nexus.coroutine_scoped_services', array_keys($scopedIds));

        /** @var array<string, Reference> $references */
        $references = [];

        foreach ($scopedIds as $id => $_) {
            $references[$id] = new Reference($id);
        }

        $locator = ServiceLocatorTagPass::register($container, $references);

        $container->setAlias('nexus.coroutine_scoped_locator', (string) $locator)->setPublic(false);
    }
}
