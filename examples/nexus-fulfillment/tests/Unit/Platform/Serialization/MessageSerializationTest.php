<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Platform\Serialization;

use Monadial\Nexus\Example\Fulfillment\Platform\Serialization\MessageTypes;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Serialization\ValinorMessageSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageTypes::class)]
#[CoversClass(OrderPlaced::class)]
final class MessageSerializationTest extends TestCase
{
    #[Test]
    public function orderPlacedSurvivesTheWireWithValueObjectsIntact(): void
    {
        $event = new OrderPlaced(
            tenantId: TenantId::fromString('acme'),
            orderId: OrderId::generate(),
            lines: [
                new OrderLine(Sku::fromString('WIDGET-42'), Quantity::of(3), Money::of(1999, 'EUR')),
                new OrderLine(Sku::fromString('GADGET-7'), Quantity::of(1), Money::of(4900, 'EUR')),
            ],
            total: Money::of(10897, 'EUR'),
        );

        $serializer = new ValinorMessageSerializer(MessageTypes::registry());

        $wire = $serializer->serialize($event);
        $decoded = $serializer->deserialize($wire, 'orders.order_placed.v1');

        self::assertInstanceOf(OrderPlaced::class, $decoded);
        self::assertTrue($decoded->tenantId->equals($event->tenantId));
        self::assertTrue($decoded->orderId->equals($event->orderId));
        self::assertTrue($decoded->total->equals($event->total));
        self::assertCount(2, $decoded->lines);
        self::assertTrue($decoded->lines[0]->sku->equals(Sku::fromString('WIDGET-42')));
        self::assertTrue($decoded->lines[0]->total()->equals(Money::of(5997, 'EUR')));
    }

    #[Test]
    public function registryIsFreshPerCall(): void
    {
        self::assertNotSame(MessageTypes::registry(), MessageTypes::registry());
    }
}
