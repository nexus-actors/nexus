<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\DependencyInjection\Compiler;

use Monadial\Nexus\Symfony\Attribute\Actor;
use Monadial\Nexus\Symfony\Attribute\ActorType;
use Monadial\Nexus\Symfony\Attribute\AsActorHandler;
use Monadial\Nexus\Symfony\DependencyInjection\Compiler\ActorRegistrationPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[Actor(ActorType::Isolated, 'test-orders')]
final class StubOrderService
{
    #[AsActorHandler]
    public function handle(object $msg): void
    {
        // no-op stub
    }
}

#[Actor(ActorType::Shared, 'global-catalog')]
final class StubGlobalService
{
    #[AsActorHandler]
    public function handle(object $msg): void
    {
        // no-op stub
    }
}

#[CoversClass(ActorRegistrationPass::class)]
final class ActorRegistrationPassTest extends TestCase
{
    #[Test]
    public function registersPropsFactoryForIsolatedActor(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition(StubOrderService::class);
        $container->setDefinition(StubOrderService::class, $definition);

        $pass = new ActorRegistrationPass();
        $pass->process($container);

        self::assertTrue(
            $container->hasDefinition('nexus.actor.test-orders.props_factory'),
        );
    }

    #[Test]
    public function registersActorRefServiceByName(): void
    {
        $container  = new ContainerBuilder();
        $definition = new Definition(StubOrderService::class);
        $container->setDefinition(StubOrderService::class, $definition);

        $pass = new ActorRegistrationPass();
        $pass->process($container);

        self::assertTrue($container->hasDefinition('nexus.actor_ref.test-orders'));
    }

    #[Test]
    public function ignoresSharedActors(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition(StubGlobalService::class);
        $container->setDefinition(StubGlobalService::class, $definition);

        $pass = new ActorRegistrationPass();
        $pass->process($container);

        // Shared actors are NOT handled by this pass
        self::assertFalse($container->hasDefinition('nexus.actor.global-catalog.props_factory'));
    }

    #[Test]
    public function propsFactoryIsPublicAndTagged(): void
    {
        $container  = new ContainerBuilder();
        $definition = new Definition(StubOrderService::class);
        $container->setDefinition(StubOrderService::class, $definition);

        $pass = new ActorRegistrationPass();
        $pass->process($container);

        $factory = $container->getDefinition('nexus.actor.test-orders.props_factory');
        self::assertTrue($factory->isPublic());
        self::assertTrue($factory->hasTag('nexus.isolated_actor'));

        $tag = $factory->getTag('nexus.isolated_actor')[0];
        self::assertSame('test-orders', $tag['name']);
    }
}
