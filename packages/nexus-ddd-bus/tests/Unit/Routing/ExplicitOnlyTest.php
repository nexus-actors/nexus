<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Bus\Routing\ExplicitOnly;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingResolution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(ExplicitOnly::class)]
#[CoversClass(RoutingResolution::class)]
final class ExplicitOnlyTest extends TestCase
{
    #[Test]
    public function emptyRouterResolvesToNone(): void
    {
        $strategy = new ExplicitOnly();

        self::assertTrue($strategy->resolve(stdClass::class)->isNone());
    }

    #[Test]
    public function registeredClassResolvesToSome(): void
    {
        $strategy = new ExplicitOnly()->explicit(stdClass::class, 'orders');

        $result = $strategy->resolve(stdClass::class);

        self::assertTrue($result->isSome());

        $resolution = $result->getUnsafe();
        self::assertSame('orders', $resolution->busName);
        self::assertSame(ExplicitOnly::class, $resolution->resolvedBy);
    }

    #[Test]
    public function unregisteredClassResolvesToNone(): void
    {
        $strategy = new ExplicitOnly()->explicit(stdClass::class, 'orders');

        self::assertTrue($strategy->resolve(self::class)->isNone());
    }

    #[Test]
    public function explicitIsChainable(): void
    {
        $strategy = new ExplicitOnly()
            ->explicit(stdClass::class, 'orders')
            ->explicit(self::class, 'reporting');

        self::assertSame('orders', $strategy->resolve(stdClass::class)->getUnsafe()->busName);
        self::assertSame('reporting', $strategy->resolve(self::class)->getUnsafe()->busName);
    }
}
