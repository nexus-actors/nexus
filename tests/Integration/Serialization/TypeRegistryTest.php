<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Serialization;

use Monadial\Nexus\Serialization\TypeRegistry;
use Monadial\Nexus\Serialization\ValinorMessageSerializer;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\Address;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\CartItem;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\CartUpdated;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\OrderPlaced;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\PaymentProcessed;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\ShipmentCreated;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\UserProfileUpdated;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypeRegistry::class)]
final class TypeRegistryTest extends TestCase
{
    #[Test]
    public function registerMultipleClassesFromAttributesAndRoundtripLookups(): void
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(OrderPlaced::class);
        $registry->registerFromAttribute(ShipmentCreated::class);
        $registry->registerFromAttribute(CartUpdated::class);
        $registry->registerFromAttribute(UserProfileUpdated::class);
        $registry->registerFromAttribute(PaymentProcessed::class);

        // Verify forward lookups (class -> name)
        self::assertSame('order.placed', $registry->nameForClass(OrderPlaced::class)->get());
        self::assertSame('shipment.created', $registry->nameForClass(ShipmentCreated::class)->get());
        self::assertSame('cart.updated', $registry->nameForClass(CartUpdated::class)->get());
        self::assertSame('user.profile.updated', $registry->nameForClass(UserProfileUpdated::class)->get());
        self::assertSame('payment.processed', $registry->nameForClass(PaymentProcessed::class)->get());

        // Verify reverse lookups (name -> class)
        self::assertSame(OrderPlaced::class, $registry->classForName('order.placed')->get());
        self::assertSame(ShipmentCreated::class, $registry->classForName('shipment.created')->get());
        self::assertSame(CartUpdated::class, $registry->classForName('cart.updated')->get());
        self::assertSame(UserProfileUpdated::class, $registry->classForName('user.profile.updated')->get());
        self::assertSame(PaymentProcessed::class, $registry->classForName('payment.processed')->get());
    }

    #[Test]
    public function registryWorksWithValinorSerializerEndToEnd(): void
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(OrderPlaced::class);
        $registry->registerFromAttribute(PaymentProcessed::class);
        $serializer = new ValinorMessageSerializer($registry);

        // Serialize OrderPlaced
        $order = new OrderPlaced('ORD-REG-1', 200.00);
        $orderJson = $serializer->serialize($order);

        // The type name from the registry should allow deserialization
        $typeName = $registry->nameForClass(OrderPlaced::class)->get();
        $restoredOrder = $serializer->deserialize($orderJson, $typeName);

        self::assertInstanceOf(OrderPlaced::class, $restoredOrder);
        self::assertSame('ORD-REG-1', $restoredOrder->orderId);
        self::assertSame(200.00, $restoredOrder->amount);

        // Serialize PaymentProcessed
        $payment = new PaymentProcessed('PAY-REG-1', 200.00, 'USD', 'completed');
        $paymentJson = $serializer->serialize($payment);

        $paymentTypeName = $registry->nameForClass(PaymentProcessed::class)->get();
        $restoredPayment = $serializer->deserialize($paymentJson, $paymentTypeName);

        self::assertInstanceOf(PaymentProcessed::class, $restoredPayment);
        self::assertSame('PAY-REG-1', $restoredPayment->paymentId);
        self::assertSame('USD', $restoredPayment->currency);
        self::assertSame('completed', $restoredPayment->status);
    }

    #[Test]
    public function fullPipelineAttributeScanSerializeDeserialize(): void
    {
        // Step 1: Scan attributes and build registry
        $registry = new TypeRegistry();
        $messageClasses = [
            OrderPlaced::class,
            ShipmentCreated::class,
            CartUpdated::class,
            UserProfileUpdated::class,
            PaymentProcessed::class,
        ];

        foreach ($messageClasses as $class) {
            $registry->registerFromAttribute($class);
        }

        // Step 2: Create serializer from registry
        $serializer = new ValinorMessageSerializer($registry);

        // Step 3: Serialize a complex message
        $items = [
            new CartItem('ITEM-A', 2, 25.00),
            new CartItem('ITEM-B', 1, 75.00),
        ];
        $cartMessage = new CartUpdated('CART-PIPE', $items);
        $json = $serializer->serialize($cartMessage);

        // Step 4: Look up the type name and deserialize
        $typeName = $registry->nameForClass(CartUpdated::class)->get();
        self::assertSame('cart.updated', $typeName);

        $restored = $serializer->deserialize($json, $typeName);

        // Step 5: Verify full fidelity
        self::assertInstanceOf(CartUpdated::class, $restored);
        self::assertSame('CART-PIPE', $restored->cartId);
        self::assertCount(2, $restored->items);
        self::assertSame('ITEM-A', $restored->items[0]->productId);
        self::assertSame(2, $restored->items[0]->quantity);
        self::assertSame(25.00, $restored->items[0]->price);
        self::assertSame('ITEM-B', $restored->items[1]->productId);
        self::assertSame(1, $restored->items[1]->quantity);
        self::assertSame(75.00, $restored->items[1]->price);
    }

    #[Test]
    public function pipelineWithNestedObjectAndNullableFields(): void
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(UserProfileUpdated::class);
        $serializer = new ValinorMessageSerializer($registry);

        // With all fields populated
        $address = new Address('100 Broadway', 'New York', '10001', 'US');
        $withAddress = new UserProfileUpdated('USR-PIPE-1', 'Charlie', 'charlie@test.com', $address);

        $typeName = $registry->nameForClass(UserProfileUpdated::class)->get();

        $json = $serializer->serialize($withAddress);
        $restored = $serializer->deserialize($json, $typeName);

        self::assertInstanceOf(UserProfileUpdated::class, $restored);
        self::assertSame('Charlie', $restored->name);
        self::assertSame('charlie@test.com', $restored->email);
        self::assertNotNull($restored->address);
        self::assertSame('100 Broadway', $restored->address->street);

        // With nullable fields as null
        $withoutAddress = new UserProfileUpdated('USR-PIPE-2', 'Dana', null, null);

        $json = $serializer->serialize($withoutAddress);
        $restored = $serializer->deserialize($json, $typeName);

        self::assertInstanceOf(UserProfileUpdated::class, $restored);
        self::assertSame('Dana', $restored->name);
        self::assertNull($restored->email);
        self::assertNull($restored->address);
    }

    #[Test]
    public function reverseResolveFromClassNameInDeserializedPayload(): void
    {
        // Simulates the DefaultEnvelopeSerializer flow where the FQCN is stored as messageType
        $registry = new TypeRegistry();
        // Register with FQCN as type name (as DefaultEnvelopeSerializer uses)
        $registry->register(OrderPlaced::class, OrderPlaced::class);
        $serializer = new ValinorMessageSerializer($registry);

        $original = new OrderPlaced('ORD-FQCN', 500.00);
        $json = $serializer->serialize($original);

        // Deserialize using FQCN as the type name (as envelope deserializer would)
        $restored = $serializer->deserialize($json, OrderPlaced::class);

        self::assertInstanceOf(OrderPlaced::class, $restored);
        self::assertSame('ORD-FQCN', $restored->orderId);
        self::assertSame(500.00, $restored->amount);
    }
}
