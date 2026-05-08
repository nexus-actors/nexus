<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Event\Registry;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Event\Attribute\Event;
use Monadial\Nexus\Ddd\Aggregate\Event\Registry\EventNameRegistry;
use Monadial\Nexus\Ddd\Aggregate\Exception\EventNameCollisionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(EventNameRegistry::class)]
final class EventNameRegistryTest extends TestCase
{
    #[Test]
    public function scanRegistersEventClasses(): void
    {
        $registry = EventNameRegistry::scan([GoodOrderPlacedV1::class]);
        self::assertSame(
            GoodOrderPlacedV1::class,
            $registry->classFor('orders.OrderPlaced', 1)->getOrElse('miss'),
        );
    }

    #[Test]
    public function scanThrowsOnDuplicateNameAndVersion(): void
    {
        $this->expectException(EventNameCollisionException::class);
        $this->expectExceptionMessageMatches('/orders\.OrderPlaced/');
        EventNameRegistry::scan([GoodOrderPlacedV1::class, DuplicateOrderPlacedV1::class]);
    }

    #[Test]
    public function scanAllowsSameNameDifferentVersion(): void
    {
        $registry = EventNameRegistry::scan([GoodOrderPlacedV1::class, GoodOrderPlacedV2::class]);
        self::assertSame(
            GoodOrderPlacedV1::class,
            $registry->classFor('orders.OrderPlaced', 1)->getOrElse('miss'),
        );
        self::assertSame(
            GoodOrderPlacedV2::class,
            $registry->classFor('orders.OrderPlaced', 2)->getOrElse('miss'),
        );
    }

    #[Test]
    public function scanIgnoresClassesWithoutEventAttribute(): void
    {
        $registry = EventNameRegistry::scan([stdClass::class]);
        self::assertSame([], $registry->all());
    }

    #[Test]
    public function classForReturnsNoneOnMiss(): void
    {
        $registry = EventNameRegistry::scan([]);
        self::assertEquals(Option::none(), $registry->classFor('absent', 1));
    }

    #[Test]
    public function allReturnsEveryRegisteredEvent(): void
    {
        $registry = EventNameRegistry::scan([GoodOrderPlacedV1::class, GoodOrderPlacedV2::class]);
        self::assertCount(2, $registry->all());
    }
}

#[Event(name: 'orders.OrderPlaced', version: 1)]
final readonly class GoodOrderPlacedV1
{
}

#[Event(name: 'orders.OrderPlaced', version: 1)]
final readonly class DuplicateOrderPlacedV1
{
}

#[Event(name: 'orders.OrderPlaced', version: 2)]
final readonly class GoodOrderPlacedV2
{
}
