<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\BusException;
use Monadial\Nexus\Ddd\Bus\Exception\BusInvariantException;
use Monadial\Nexus\Ddd\Bus\Exception\BusRuntimeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(BusRuntimeException::class)]
final class BusRuntimeExceptionTest extends TestCase
{
    #[Test]
    public function isAbstractAndExtendsBusException(): void
    {
        $reflection = new ReflectionClass(BusRuntimeException::class);

        self::assertTrue($reflection->isAbstract());
        self::assertSame(BusException::class, $reflection->getParentClass()->getName());
    }

    #[Test]
    public function doesNotImplementBusInvariantException(): void
    {
        self::assertFalse(is_subclass_of(BusRuntimeException::class, BusInvariantException::class));
    }
}
