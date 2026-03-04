<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\DependencyInjection;

use Monadial\Nexus\Symfony\DependencyInjection\NexusExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(NexusExtension::class)]
final class NexusExtensionTest extends TestCase
{
    #[Test]
    public function loadsWithDefaultConfiguration(): void
    {
        $container = new ContainerBuilder();
        $extension = new NexusExtension();

        $extension->load([[]], $container);

        self::assertTrue($container->hasDefinition('nexus.coroutine_scope'));
        self::assertTrue($container->hasDefinition('nexus.coroutine_scope_listener'));
    }

    #[Test]
    public function registersActorSystemDefinition(): void
    {
        $container = new ContainerBuilder();
        $extension = new NexusExtension();

        $extension->load([['name' => 'my-app']], $container);

        self::assertTrue($container->hasDefinition('nexus.actor_system'));
    }
}
