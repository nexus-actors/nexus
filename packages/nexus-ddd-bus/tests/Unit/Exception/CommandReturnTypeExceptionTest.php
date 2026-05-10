<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\BusBootException;
use Monadial\Nexus\Ddd\Bus\Exception\BusInvariantException;
use Monadial\Nexus\Ddd\Bus\Exception\CommandReturnTypeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommandReturnTypeException::class)]
final class CommandReturnTypeExceptionTest extends TestCase
{
    #[Test]
    public function forBuildsExceptionWithHandlerAndActualReturn(): void
    {
        $ex = CommandReturnTypeException::for('App\\Handler\\PlaceOrderHandler', 'string');

        self::assertInstanceOf(BusBootException::class, $ex);
        self::assertInstanceOf(BusInvariantException::class, $ex);
        self::assertStringContainsString('App\\Handler\\PlaceOrderHandler', $ex->getMessage());
        self::assertStringContainsString('string', $ex->getMessage());
    }
}
