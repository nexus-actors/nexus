<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\DependencyInjection\Compiler;

use Monadial\Nexus\Symfony\Attribute\Actor;
use Monadial\Nexus\Symfony\Attribute\ActorType;
use Monadial\Nexus\Symfony\Attribute\AsActorHandler;
use Monadial\Nexus\Symfony\DependencyInjection\Compiler\GlobalActorPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[Actor(ActorType::Shared, 'payment-saga')]
final class StubSharedPaymentSaga
{
    #[AsActorHandler]
    public function handle(object $msg): void
    {
        // no-op stub
    }
}

#[Actor(ActorType::Isolated, 'local-orders')]
final class StubIsolatedService
{
    #[AsActorHandler]
    public function handle(object $msg): void
    {
        // no-op stub
    }
}

#[CoversClass(GlobalActorPass::class)]
final class GlobalActorPassTest extends TestCase
{
    #[Test]
    public function registersPropsFactoryForSharedActor(): void
    {
        $container  = new ContainerBuilder();
        $definition = new Definition(StubSharedPaymentSaga::class);
        $container->setDefinition(StubSharedPaymentSaga::class, $definition);

        $pass = new GlobalActorPass();
        $pass->process($container);

        self::assertTrue($container->hasDefinition('nexus.actor.payment-saga.props_factory'));
    }

    #[Test]
    public function degradesGracefullyWithoutWorkerPool(): void
    {
        $container  = new ContainerBuilder();
        $definition = new Definition(StubSharedPaymentSaga::class);
        $container->setDefinition(StubSharedPaymentSaga::class, $definition);

        $pass = new GlobalActorPass();
        $pass->process($container);

        self::assertTrue($container->hasDefinition('nexus.actor_ref.payment-saga'));
        // Without worker pool, no tag
        $actorRefDef = $container->getDefinition('nexus.actor_ref.payment-saga');
        self::assertEmpty($actorRefDef->getTag('nexus.global_actor'));
    }

    #[Test]
    public function tagsActorRefWhenWorkerPoolPresent(): void
    {
        $container  = new ContainerBuilder();
        $definition = new Definition(StubSharedPaymentSaga::class);
        $container->setDefinition(StubSharedPaymentSaga::class, $definition);
        // Simulate worker pool present
        $container->setDefinition('nexus.worker_pool', new Definition(stdClass::class));

        $pass = new GlobalActorPass();
        $pass->process($container);

        $actorRefDef = $container->getDefinition('nexus.actor_ref.payment-saga');
        self::assertNotEmpty($actorRefDef->getTag('nexus.global_actor'));
    }

    #[Test]
    public function ignoresIsolatedActors(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition(StubIsolatedService::class);
        $container->setDefinition(StubIsolatedService::class, $definition);

        $pass = new GlobalActorPass();
        $pass->process($container);

        // Isolated actors are NOT handled by this pass
        self::assertFalse($container->hasDefinition('nexus.actor.local-orders.props_factory'));
    }
}
