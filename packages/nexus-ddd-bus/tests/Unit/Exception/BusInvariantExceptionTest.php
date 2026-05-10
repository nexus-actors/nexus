<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\BusInvariantException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversNothing]
final class BusInvariantExceptionTest extends TestCase
{
    #[Test]
    public function existsAsInterfaceWithNoMethods(): void
    {
        $reflection = new ReflectionClass(BusInvariantException::class);

        self::assertTrue($reflection->isInterface());
        self::assertSame([], $reflection->getMethods());
    }
}
