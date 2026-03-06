<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\DependencyInjection\Compiler;

use Monadial\Nexus\Symfony\Attribute\CoroutineScoped;
use Monadial\Nexus\Symfony\DependencyInjection\Compiler\CoroutineScopedPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoroutineScoped]
final class FakeScopedService {}

final class FakeNonScopedService {}

#[CoversClass(CoroutineScopedPass::class)]
final class CoroutineScopedPassTest extends TestCase
{
    #[Test]
    public function marksCoroutineScopedServicesAsPrototype(): void
    {
        $container = new ContainerBuilder();
        $def       = new Definition(FakeScopedService::class);
        $def->setShared(true);
        $container->setDefinition('app.fake_scoped', $def);

        (new CoroutineScopedPass())->process($container);

        self::assertFalse($container->getDefinition('app.fake_scoped')->isShared());
    }

    #[Test]
    public function storesServiceIdsAsParameter(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.scoped', new Definition(FakeScopedService::class));

        (new CoroutineScopedPass())->process($container);

        self::assertContains('app.scoped', $container->getParameter('nexus.coroutine_scoped_services'));
    }

    #[Test]
    public function ignoresNonScopedServices(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.not_scoped', new Definition(FakeNonScopedService::class));

        (new CoroutineScopedPass())->process($container);

        self::assertSame([], $container->getParameter('nexus.coroutine_scoped_services'));
        self::assertTrue($container->getDefinition('app.not_scoped')->isShared());
    }
}
