<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Marker;

use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Accepted::class)]
final class AcceptedTest extends TestCase
{
    #[Test]
    public function classIsFinalReadonlyMarker(): void
    {
        $reflection = new ReflectionClass(Accepted::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }

    #[Test]
    public function instancesCanBeConstructedFreely(): void
    {
        $a = new Accepted();
        $b = new Accepted();

        self::assertNotSame($a, $b);
        self::assertEquals($a, $b);
    }

    #[Test]
    public function classDeclaresNoFields(): void
    {
        $reflection = new ReflectionClass(Accepted::class);

        self::assertSame([], $reflection->getProperties());
    }
}
