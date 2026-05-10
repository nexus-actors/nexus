<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\BusBootException;
use Monadial\Nexus\Ddd\Bus\Exception\BusInvariantException;
use Monadial\Nexus\Ddd\Bus\Exception\DuplicateRoutingException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DuplicateRoutingException::class)]
final class DuplicateRoutingExceptionTest extends TestCase
{
    #[Test]
    public function forBuildsExceptionWithCommandAndResolutions(): void
    {
        $ex = DuplicateRoutingException::for(
            'App\\Order\\PlaceOrder',
            ['AttributeStrategy: commands.sync', 'ConventionStrategy: commands.async'],
        );

        self::assertInstanceOf(BusBootException::class, $ex);
        self::assertInstanceOf(BusInvariantException::class, $ex);
        self::assertStringContainsString('App\\Order\\PlaceOrder', $ex->getMessage());
        self::assertStringContainsString('AttributeStrategy: commands.sync', $ex->getMessage());
        self::assertStringContainsString('ConventionStrategy: commands.async', $ex->getMessage());
    }
}
