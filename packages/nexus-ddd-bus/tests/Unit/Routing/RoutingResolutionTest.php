<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Bus\Routing\AttributeBased;
use Monadial\Nexus\Ddd\Bus\Routing\ExplicitOnly;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingResolution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(RoutingResolution::class)]
final class RoutingResolutionTest extends TestCase
{
    #[Test]
    public function fieldsAreExposedAsConstructed(): void
    {
        $resolution = new RoutingResolution('orders', ExplicitOnly::class);

        self::assertSame('orders', $resolution->busName);
        self::assertSame(ExplicitOnly::class, $resolution->resolvedBy);
    }

    #[Test]
    public function displayNameReturnsUnqualifiedClassName(): void
    {
        $resolution = new RoutingResolution('orders', AttributeBased::class);

        self::assertSame('AttributeBased', $resolution->displayName());
    }

    #[Test]
    public function classIsReadonlyImmutableValueObject(): void
    {
        $reflection = new ReflectionClass(RoutingResolution::class);

        self::assertTrue($reflection->isReadOnly());
        self::assertTrue($reflection->isFinal());
    }
}
