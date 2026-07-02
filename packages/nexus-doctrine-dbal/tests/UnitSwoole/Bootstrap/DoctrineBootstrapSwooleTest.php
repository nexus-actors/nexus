<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\UnitSwoole\Bootstrap;

use Monadial\Nexus\Doctrine\Dbal\Bootstrap\DoctrineBootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineBootstrap::class)]
#[RequiresPhpExtension('swoole')]
final class DoctrineBootstrapSwooleTest extends TestCase
{
    #[Test]
    public function enableMarksItselfEnabled(): void
    {
        DoctrineBootstrap::enable();
        self::assertTrue(DoctrineBootstrap::isEnabled());
    }

    #[Test]
    public function enableIsIdempotent(): void
    {
        DoctrineBootstrap::enable();
        DoctrineBootstrap::enable();
        self::assertTrue(DoctrineBootstrap::isEnabled());
    }
}
