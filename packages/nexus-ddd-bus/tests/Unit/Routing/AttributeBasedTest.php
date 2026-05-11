<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Bus\Attribute\OnBus;
use Monadial\Nexus\Ddd\Bus\Routing\AttributeBased;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingResolution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AttributeBased::class)]
#[CoversClass(RoutingResolution::class)]
final class AttributeBasedTest extends TestCase
{
    #[Test]
    public function classWithoutAttributeResolvesToNone(): void
    {
        $strategy = new AttributeBased();

        self::assertTrue($strategy->resolve(MessageWithoutOnBus::class)->isNone());
    }

    #[Test]
    public function classWithOnBusAttributeResolvesToSome(): void
    {
        $strategy = new AttributeBased();

        $result = $strategy->resolve(MessageOnOrdersBus::class);

        self::assertTrue($result->isSome());

        $resolution = $result->getUnsafe();
        self::assertSame('orders', $resolution->busName);
        self::assertSame(AttributeBased::class, $resolution->resolvedBy);
    }

    #[Test]
    public function busNameMatchesAttributeArgument(): void
    {
        $strategy = new AttributeBased();

        self::assertSame(
            'reporting',
            $strategy->resolve(MessageOnReportingBus::class)->getUnsafe()->busName,
        );
    }
}

final readonly class MessageWithoutOnBus {}

#[OnBus(name: 'orders')]
final readonly class MessageOnOrdersBus {}

#[OnBus(name: 'reporting')]
final readonly class MessageOnReportingBus {}
