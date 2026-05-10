<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Marker;

use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AcceptedSmokeTest extends TestCase
{
    #[Test]
    public function isConstructibleWithoutFactory(): void
    {
        self::assertInstanceOf(Accepted::class, new Accepted());
    }

    #[Test]
    public function hasNoFields(): void
    {
        $reflection = new ReflectionClass(Accepted::class);

        self::assertSame([], $reflection->getProperties());
    }

    #[Test]
    public function hasNoStaticInstanceCache(): void
    {
        $reflection = new ReflectionClass(Accepted::class);
        $statics = $reflection->getStaticProperties();

        self::assertSame([], $statics);
    }
}
