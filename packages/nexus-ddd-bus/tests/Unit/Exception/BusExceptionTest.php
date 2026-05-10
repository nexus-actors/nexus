<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\BusException;
use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(BusException::class)]
final class BusExceptionTest extends TestCase
{
    #[Test]
    public function isAbstractAndExtendsNexusDddException(): void
    {
        $reflection = new ReflectionClass(BusException::class);

        self::assertTrue($reflection->isAbstract());
        self::assertSame(NexusDddException::class, $reflection->getParentClass()->getName());
    }
}
