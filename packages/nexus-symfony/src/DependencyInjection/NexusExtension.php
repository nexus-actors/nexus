<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\DependencyInjection;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Symfony\Actor\EnvelopeContext;
use Monadial\Nexus\Symfony\Coroutine\CoroutineScope;
use Monadial\Nexus\Symfony\Coroutine\CoroutineScopeListener;
use Monadial\Nexus\Symfony\Coroutine\SwooleCoroutineContext;
use Monadial\Nexus\Symfony\Tracing\NexusMonologProcessor;
use Monadial\Nexus\Symfony\Tracing\RequestIdListener;
use Monadial\Nexus\Symfony\Tracing\ResponseIdListener;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class NexusExtension extends Extension
{
    #[\Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $container->setParameter('nexus.app_name', $config['name']);
        $container->setParameter('nexus.shutdown_timeout', $config['shutdown_timeout']);

        $this->registerCoroutineServices($container);
        $this->registerTracingServices($container);
        $this->registerActorSystem($container);
    }

    private function registerCoroutineServices(ContainerBuilder $container): void
    {
        $container->setDefinition(
            'nexus.coroutine_context',
            (new Definition(SwooleCoroutineContext::class))->setPublic(false),
        );

        $container->setDefinition(
            'nexus.coroutine_scope',
            (new Definition(CoroutineScope::class))
                ->setArguments([new Reference('nexus.coroutine_context')]),
        );

        $container->setDefinition(
            'nexus.coroutine_scope_listener',
            (new Definition(CoroutineScopeListener::class))
                ->setArguments([new Reference('nexus.coroutine_scope'), []])
                ->addTag('kernel.event_listener'),
        );

        $container->setDefinition(
            'nexus.envelope_context',
            (new Definition(EnvelopeContext::class))
                ->setArguments([new Reference('nexus.coroutine_context')]),
        );
    }

    private function registerTracingServices(ContainerBuilder $container): void
    {
        $container->setDefinition(
            'nexus.monolog_processor',
            (new Definition(NexusMonologProcessor::class))
                ->setArguments([
                    new Reference('nexus.coroutine_context'),
                    new Reference('nexus.envelope_context'),
                ])
                ->addTag('monolog.processor'),
        );

        $container->setDefinition(
            'nexus.tracing.request_id_listener',
            (new Definition(RequestIdListener::class))
                ->setArguments([new Reference('nexus.coroutine_context')])
                ->addTag('kernel.event_listener'),
        );

        $container->setDefinition(
            'nexus.tracing.response_id_listener',
            (new Definition(ResponseIdListener::class))
                ->setArguments([new Reference('nexus.coroutine_context')])
                ->addTag('kernel.event_listener'),
        );
    }

    private function registerActorSystem(ContainerBuilder $container): void
    {
        $definition = new Definition(ActorSystem::class);
        $definition->setSynthetic(true);
        $definition->setPublic(true);
        $container->setDefinition('nexus.actor_system', $definition);
        $container->setAlias(ActorSystem::class, 'nexus.actor_system');
    }
}
