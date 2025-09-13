<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Serialization;

use Monadial\Nexus\Serialization\TypeRegistry;
use Monadial\Nexus\Serialization\ValinorMessageSerializer;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\Address;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\CartItem;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\CartUpdated;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\OrderPlaced;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\ShipmentCreated;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\UserProfileUpdated;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValinorMessageSerializer::class)]
final class ValinorRoundtripTest extends TestCase
{
    #[Test]
    public function roundtripSimpleReadonlyMessage(): void
    {
        $registry = new TypeRegistry();
        $registry->register(OrderPlaced::class, 'order.placed');
        $serializer = new ValinorMessageSerializer($registry);

        $original = new OrderPlaced('ORD-001', 129.99);

        $json = $serializer->serialize($original);
        $restored = $serializer->deserialize($json, 'order.placed');

        self::assertInstanceOf(OrderPlaced::class, $restored);
        self::assertSame('ORD-001', $restored->orderId);
        self::assertSame(129.99, $restored->amount);
    }

    #[Test]
    public function roundtripNestedValueObject(): void
    {
        $registry = new TypeRegistry();
        $registry->register(ShipmentCreated::class, 'shipment.created');
        $serializer = new ValinorMessageSerializer($registry);

        $address = new Address('123 Main St', 'Springfield', '62704', 'US');
        $original = new ShipmentCreated('SHP-42', $address);

        $json = $serializer->serialize($original);
        $restored = $serializer->deserialize($json, 'shipment.created');

        self::assertInstanceOf(ShipmentCreated::class, $restored);
        self::assertSame('SHP-42', $restored->shipmentId);
        self::assertSame('123 Main St', $restored->address->street);
        self::assertSame('Springfield', $restored->address->city);
        self::assertSame('62704', $restored->address->zip);
        self::assertSame('US', $restored->address->country);
    }

    #[Test]
    public function roundtripArrayProperties(): void
    {
        $registry = new TypeRegistry();
        $registry->register(CartUpdated::class, 'cart.updated');
        $serializer = new ValinorMessageSerializer($registry);

        $items = [
            new CartItem('PROD-A', 2, 19.99),
            new CartItem('PROD-B', 1, 49.50),
            new CartItem('PROD-C', 5, 3.25),
        ];
        $original = new CartUpdated('CART-99', $items);

        $json = $serializer->serialize($original);
        $restored = $serializer->deserialize($json, 'cart.updated');

        self::assertInstanceOf(CartUpdated::class, $restored);
        self::assertSame('CART-99', $restored->cartId);
        self::assertCount(3, $restored->items);

        self::assertSame('PROD-A', $restored->items[0]->productId);
        self::assertSame(2, $restored->items[0]->quantity);
        self::assertSame(19.99, $restored->items[0]->price);

        self::assertSame('PROD-B', $restored->items[1]->productId);
        self::assertSame(1, $restored->items[1]->quantity);
        self::assertSame(49.50, $restored->items[1]->price);

        self::assertSame('PROD-C', $restored->items[2]->productId);
        self::assertSame(5, $restored->items[2]->quantity);
        self::assertSame(3.25, $restored->items[2]->price);
    }

    #[Test]
    public function roundtripEmptyArray(): void
    {
        $registry = new TypeRegistry();
        $registry->register(CartUpdated::class, 'cart.updated');
        $serializer = new ValinorMessageSerializer($registry);

        $original = new CartUpdated('CART-EMPTY', []);

        $json = $serializer->serialize($original);
        $restored = $serializer->deserialize($json, 'cart.updated');

        self::assertInstanceOf(CartUpdated::class, $restored);
        self::assertSame('CART-EMPTY', $restored->cartId);
        self::assertCount(0, $restored->items);
    }

    #[Test]
    public function roundtripNullablePropertiesWithValues(): void
    {
        $registry = new TypeRegistry();
        $registry->register(UserProfileUpdated::class, 'user.profile.updated');
        $serializer = new ValinorMessageSerializer($registry);

        $address = new Address('456 Oak Ave', 'Portland', '97201', 'US');
        $original = new UserProfileUpdated('USR-1', 'Alice', 'alice@example.com', $address);

        $json = $serializer->serialize($original);
        $restored = $serializer->deserialize($json, 'user.profile.updated');

        self::assertInstanceOf(UserProfileUpdated::class, $restored);
        self::assertSame('USR-1', $restored->userId);
        self::assertSame('Alice', $restored->name);
        self::assertSame('alice@example.com', $restored->email);
        self::assertNotNull($restored->address);
        self::assertSame('456 Oak Ave', $restored->address->street);
        self::assertSame('Portland', $restored->address->city);
    }

    #[Test]
    public function roundtripNullablePropertiesWithNulls(): void
    {
        $registry = new TypeRegistry();
        $registry->register(UserProfileUpdated::class, 'user.profile.updated');
        $serializer = new ValinorMessageSerializer($registry);

        $original = new UserProfileUpdated('USR-2', 'Bob', null, null);

        $json = $serializer->serialize($original);
        $restored = $serializer->deserialize($json, 'user.profile.updated');

        self::assertInstanceOf(UserProfileUpdated::class, $restored);
        self::assertSame('USR-2', $restored->userId);
        self::assertSame('Bob', $restored->name);
        self::assertNull($restored->email);
        self::assertNull($restored->address);
    }

    #[Test]
    public function roundtripWithMessageTypeAttributeRegistration(): void
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(OrderPlaced::class);
        $serializer = new ValinorMessageSerializer($registry);

        $original = new OrderPlaced('ORD-ATTR', 250.00);

        $json = $serializer->serialize($original);
        $restored = $serializer->deserialize($json, 'order.placed');

        self::assertInstanceOf(OrderPlaced::class, $restored);
        self::assertSame('ORD-ATTR', $restored->orderId);
        self::assertSame(250.00, $restored->amount);
    }

    #[Test]
    public function roundtripMultipleAttributeRegisteredTypes(): void
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(OrderPlaced::class);
        $registry->registerFromAttribute(ShipmentCreated::class);
        $registry->registerFromAttribute(CartUpdated::class);
        $serializer = new ValinorMessageSerializer($registry);

        // Test all three types round-trip correctly with the same serializer
        $order = new OrderPlaced('ORD-MULTI', 75.00);
        $_ = $serializer->serialize($order);
        $restoredOrder = $serializer->deserialize($_, 'order.placed');
        self::assertInstanceOf(OrderPlaced::class, $restoredOrder);
        self::assertSame('ORD-MULTI', $restoredOrder->orderId);

        $shipment = new ShipmentCreated('SHP-MULTI', new Address('1 Elm', 'Boston', '02101', 'US'));
        $_ = $serializer->serialize($shipment);
        $restoredShipment = $serializer->deserialize($_, 'shipment.created');
        self::assertInstanceOf(ShipmentCreated::class, $restoredShipment);
        self::assertSame('SHP-MULTI', $restoredShipment->shipmentId);

        $cart = new CartUpdated('CART-MULTI', [new CartItem('X', 1, 10.0)]);
        $_ = $serializer->serialize($cart);
        $restoredCart = $serializer->deserialize($_, 'cart.updated');
        self::assertInstanceOf(CartUpdated::class, $restoredCart);
        self::assertSame('CART-MULTI', $restoredCart->cartId);
    }
}
