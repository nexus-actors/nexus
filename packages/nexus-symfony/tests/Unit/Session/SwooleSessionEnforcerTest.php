<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Session;

use InvalidArgumentException;
use Monadial\Nexus\Symfony\Session\SwooleSessionEnforcer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[CoversClass(SwooleSessionEnforcer::class)]
final class SwooleSessionEnforcerTest extends TestCase
{
    #[Test]
    public function throwsWhenFileSessionHandlerConfigured(): void
    {
        $container = $this->containerWithHandlerId('session.handler.native_file');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File sessions are not Swoole-compatible');

        SwooleSessionEnforcer::assertCompatible($container);
    }

    #[Test]
    public function passesWhenRedisSessionHandlerConfigured(): void
    {
        $container = $this->containerWithHandlerId(
            'Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler',
        );

        SwooleSessionEnforcer::assertCompatible($container);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function passesWhenNoSessionConfigured(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('hasParameter')->willReturn(false);

        SwooleSessionEnforcer::assertCompatible($container);
        $this->addToAssertionCount(1);
    }

    private function containerWithHandlerId(string $handlerId): ContainerInterface
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('hasParameter')->willReturn(true);
        $container->method('getParameter')->willReturn($handlerId);

        return $container;
    }
}
