<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\BusRuntimeException;
use Monadial\Nexus\Ddd\Bus\Exception\InProcessConnectionMismatchException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InProcessConnectionMismatchException::class)]
final class InProcessConnectionMismatchExceptionTest extends TestCase
{
    #[Test]
    public function forBuildsExceptionWithBoundClassAndConnections(): void
    {
        $ex = InProcessConnectionMismatchException::for('App\\Order\\Order', 'orders_write', 'shipments_write');

        self::assertInstanceOf(BusRuntimeException::class, $ex);
        self::assertStringContainsString('App\\Order\\Order', $ex->getMessage());
        self::assertStringContainsString('orders_write', $ex->getMessage());
        self::assertStringContainsString('shipments_write', $ex->getMessage());
        self::assertStringContainsString('Bound class', $ex->getMessage());
    }
}
