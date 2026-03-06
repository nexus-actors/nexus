<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\DependencyInjection\Compiler;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Symfony\Actor\ActorPropsFactory;
use Monadial\Nexus\Symfony\Attribute\Actor;
use Monadial\Nexus\Symfony\Attribute\ActorType;
use Override;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class ActorRegistrationPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();

            if ($class === null) {
                continue;
            }

            try {
                $ref = new ReflectionClass($class);
                $attrs = $ref->getAttributes(Actor::class);
            } catch (ReflectionException) {
                continue;
            }

            if ($attrs === []) {
                continue;
            }

            $attr = $attrs[0]->newInstance();

            if ($attr->type !== ActorType::Isolated) {
                continue;
            }

            $name = $attr->name;

            $container->setDefinition(
                "nexus.actor.{$name}.props_factory",
                (new Definition(ActorPropsFactory::class))
                    ->setArguments([new Reference('service_container'), $class])
                    ->setPublic(true)
                    ->addTag('nexus.isolated_actor', ['name' => $name]),
            );

            $actorRefDef = (new Definition(ActorRef::class))
                ->setSynthetic(true)
                ->setPublic(true);
            $container->setDefinition("nexus.actor_ref.{$name}", $actorRefDef);
        }
    }
}
