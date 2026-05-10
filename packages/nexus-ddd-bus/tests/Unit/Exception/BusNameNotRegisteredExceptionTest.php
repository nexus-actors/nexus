<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\BusBootException;
use Monadial\Nexus\Ddd\Bus\Exception\BusInvariantException;
use Monadial\Nexus\Ddd\Bus\Exception\BusNameNotRegisteredException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusNameNotRegisteredException::class)]
final class BusNameNotRegisteredExceptionTest extends TestCase
{
    #[Test]
    public function forBuildsExceptionWithBusNameAndRegisteredList(): void
    {
        $ex = BusNameNotRegisteredException::for('commands.foo', ['commands.sync', 'queries.sync']);

        self::assertInstanceOf(BusBootException::class, $ex);
        self::assertInstanceOf(BusInvariantException::class, $ex);
        self::assertStringContainsString('commands.foo', $ex->getMessage());
        self::assertStringContainsString('commands.sync', $ex->getMessage());
        self::assertStringContainsString('queries.sync', $ex->getMessage());
    }
}
