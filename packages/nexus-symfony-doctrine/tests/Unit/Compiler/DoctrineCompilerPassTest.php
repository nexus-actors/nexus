<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Doctrine\Tests\Unit\Compiler;

use Monadial\Nexus\Symfony\Doctrine\Compiler\DoctrineCompilerPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(DoctrineCompilerPass::class)]
final class DoctrineCompilerPassTest extends TestCase
{
    #[Test]
    public function fallsBackToStandardDoctrineWithoutSwoole(): void
    {
        if (extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole is loaded — testing fallback only.');
        }

        $container = new ContainerBuilder();
        $container->setParameter('nexus.doctrine.connections_per_worker', 2);

        $pass = new DoctrineCompilerPass();
        $pass->process($container);

        // No PDOPool service registered — standard Doctrine fallback
        self::assertFalse($container->hasDefinition('nexus.doctrine.pdo_pool'));
    }

    #[Test]
    public function registersPdoPoolWhenSwooleLoaded(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole is not loaded.');
        }

        $container = new ContainerBuilder();
        $container->setParameter('nexus.doctrine.connections_per_worker', 2);

        $pass = new DoctrineCompilerPass();
        $pass->process($container);

        self::assertTrue($container->hasDefinition('nexus.doctrine.pdo_pool'));
    }
}
