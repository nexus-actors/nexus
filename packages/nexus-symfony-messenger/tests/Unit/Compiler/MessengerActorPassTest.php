<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Messenger\Tests\Unit\Compiler;

use Monadial\Nexus\Symfony\Messenger\Compiler\MessengerActorPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(MessengerActorPass::class)]
final class MessengerActorPassTest extends TestCase
{
    #[Test]
    public function throwsWhenSwooleExtensionAbsent(): void
    {
        if (extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole is loaded — cannot test guard.');
        }

        $container = new ContainerBuilder();
        $pass      = new MessengerActorPass();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ext-swoole');

        $pass->process($container);
    }

    #[Test]
    public function passesWhenSwooleLoaded(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole is not loaded — cannot test pass-through.');
        }

        $container = new ContainerBuilder();
        $pass      = new MessengerActorPass();

        // Should not throw
        $pass->process($container);
        $this->addToAssertionCount(1);
    }
}
