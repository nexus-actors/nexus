<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Bootstrap;

use Monadial\Nexus\Doctrine\Dbal\Bootstrap\DoctrineBootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineBootstrap::class)]
final class DoctrineBootstrapTest extends TestCase
{
    #[Test]
    public function enableIsNoOpWithoutSwoole(): void
    {
        if (extension_loaded('swoole')) {
            self::markTestSkipped('Swoole-on path is covered by DoctrineBootstrapSwooleTest');
        }

        DoctrineBootstrap::enable();
        self::assertFalse(DoctrineBootstrap::isEnabled());
    }
}
