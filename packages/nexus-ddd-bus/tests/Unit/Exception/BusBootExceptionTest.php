<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\BusBootException;
use Monadial\Nexus\Ddd\Bus\Exception\BusException;
use Monadial\Nexus\Ddd\Bus\Exception\BusInvariantException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(BusBootException::class)]
final class BusBootExceptionTest extends TestCase
{
    #[Test]
    public function isAbstractAndExtendsBusException(): void
    {
        $reflection = new ReflectionClass(BusBootException::class);

        self::assertTrue($reflection->isAbstract());
        self::assertSame(BusException::class, $reflection->getParentClass()->getName());
    }

    #[Test]
    public function implementsBusInvariantExceptionMarker(): void
    {
        self::assertTrue(is_subclass_of(BusBootException::class, BusInvariantException::class));
    }
}
