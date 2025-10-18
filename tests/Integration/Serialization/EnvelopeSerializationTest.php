<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Serialization;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Serialization\DefaultEnvelopeSerializer;
use Monadial\Nexus\Serialization\PhpNativeSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Monadial\Nexus\Serialization\ValinorMessageSerializer;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\Address;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\CartItem;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\CartUpdated;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\OrderPlaced;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\ShipmentCreated;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultEnvelopeSerializer::class)]
final class EnvelopeSerializationTest extends TestCase
{
    #[Test]
    public function roundtripWithPhpNativeSerializer(): void
    {
        $serializer = new DefaultEnvelopeSerializer(new PhpNativeSerializer());

        $message = new OrderPlaced('ORD-ENV-1', 99.95);
        $sender = ActorPath::fromString('/user/checkout');
        $target = ActorPath::fromString('/user/orders');
        $envelope = Envelope::of($message, $sender, $target);

        $data = $serializer->serialize($envelope);
        $restored = $serializer->deserialize($data);

        self::assertInstanceOf(OrderPlaced::class, $restored->message);
        self::assertSame('ORD-ENV-1', $restored->message->orderId);
        self::assertSame(99.95, $restored->message->amount);
    }

    #[Test]
    public function roundtripWithValinorSerializer(): void
    {
        $registry = new TypeRegistry();
        $registry->register(OrderPlaced::class, OrderPlaced::class);
        $messageSerializer = new ValinorMessageSerializer($registry);
        $serializer = new DefaultEnvelopeSerializer($messageSerializer);

        $message = new OrderPlaced('ORD-ENV-2', 150.00);
        $sender = ActorPath::fromString('/user/api-gateway');
        $target = ActorPath::fromString('/user/order-service');
        $envelope = Envelope::of($message, $sender, $target);

        $data = $serializer->serialize($envelope);
        $restored = $serializer->deserialize($data);

        self::assertInstanceOf(OrderPlaced::class, $restored->message);
        self::assertSame('ORD-ENV-2', $restored->message->orderId);
        self::assertSame(150.00, $restored->message->amount);
    }

    #[Test]
    public function senderAndTargetPathsSurviveRoundtrip(): void
    {
        $serializer = new DefaultEnvelopeSerializer(new PhpNativeSerializer());

        $sender = ActorPath::fromString('/system/guardian');
        $target = ActorPath::fromString('/user/orders/order-123/items');
        $envelope = Envelope::of(new OrderPlaced('X', 1.0), $sender, $target);

        $data = $serializer->serialize($envelope);
        $restored = $serializer->deserialize($data);

        self::assertSame('/system/guardian', (string) $restored->sender);
        self::assertSame('/user/orders/order-123/items', (string) $restored->target);
        self::assertTrue($restored->sender->equals($sender));
        self::assertTrue($restored->target->equals($target));
    }

    #[Test]
    public function rootPathsSurviveRoundtrip(): void
    {
        $serializer = new DefaultEnvelopeSerializer(new PhpNativeSerializer());

        $envelope = Envelope::of(
            new OrderPlaced('X', 1.0),
            ActorPath::root(),
            ActorPath::root(),
        );

        $data = $serializer->serialize($envelope);
        $restored = $serializer->deserialize($data);

        self::assertSame('/', (string) $restored->sender);
        self::assertSame('/', (string) $restored->target);
    }

    #[Test]
    public function metadataSurvivesRoundtrip(): void
    {
        $serializer = new DefaultEnvelopeSerializer(new PhpNativeSerializer());

        $sender = ActorPath::fromString('/sender');
        $target = ActorPath::fromString('/target');
        $envelope = new Envelope(
            new OrderPlaced('ORD-META', 42.0),
            $sender,
            $target,
            [
                'correlation-id' => 'corr-001',
                'request-id' => 'req-789',
                'trace-id' => 'abc-123-def-456',
            ],
        );

        $data = $serializer->serialize($envelope);
        $restored = $serializer->deserialize($data);

        self::assertSame('abc-123-def-456', $restored->metadata['trace-id']);
        self::assertSame('req-789', $restored->metadata['request-id']);
        self::assertSame('corr-001', $restored->metadata['correlation-id']);
    }

    #[Test]
    public function emptyMetadataSurvivesRoundtrip(): void
    {
        $serializer = new DefaultEnvelopeSerializer(new PhpNativeSerializer());

        $envelope = Envelope::of(
            new OrderPlaced('ORD-EMPTY-META', 10.0),
            ActorPath::fromString('/a'),
            ActorPath::fromString('/b'),
        );

        $data = $serializer->serialize($envelope);
        $restored = $serializer->deserialize($data);

        self::assertCount(0, $restored->metadata);
    }

    #[Test]
    public function complexMessageInsideEnvelope(): void
    {
        $registry = new TypeRegistry();
        $registry->register(ShipmentCreated::class, ShipmentCreated::class);
        $messageSerializer = new ValinorMessageSerializer($registry);
        $serializer = new DefaultEnvelopeSerializer($messageSerializer);

        $address = new Address('789 Pine Rd', 'Denver', '80202', 'US');
        $message = new ShipmentCreated('SHP-ENV', $address);
        $sender = ActorPath::fromString('/user/warehouse');
        $target = ActorPath::fromString('/user/shipping');
        $envelope = new Envelope($message, $sender, $target, ['priority' => 'high']);

        $data = $serializer->serialize($envelope);
        $restored = $serializer->deserialize($data);

        self::assertInstanceOf(ShipmentCreated::class, $restored->message);
        self::assertSame('SHP-ENV', $restored->message->shipmentId);
        self::assertSame('789 Pine Rd', $restored->message->address->street);
        self::assertSame('Denver', $restored->message->address->city);
        self::assertSame('80202', $restored->message->address->zip);
        self::assertSame('US', $restored->message->address->country);
        self::assertSame('/user/warehouse', (string) $restored->sender);
        self::assertSame('/user/shipping', (string) $restored->target);
        self::assertSame('high', $restored->metadata['priority']);
    }

    #[Test]
    public function envelopeWithArrayMessageAndMetadata(): void
    {
        $registry = new TypeRegistry();
        $registry->register(CartUpdated::class, CartUpdated::class);
        $messageSerializer = new ValinorMessageSerializer($registry);
        $serializer = new DefaultEnvelopeSerializer($messageSerializer);

        $items = [
            new CartItem('SKU-1', 3, 12.50),
            new CartItem('SKU-2', 1, 99.99),
        ];
        $message = new CartUpdated('CART-ENV', $items);
        $envelope = new Envelope(
            $message,
            ActorPath::fromString('/user/web'),
            ActorPath::fromString('/user/cart-service'),
            [
                'session-id' => 'sess-abc',
                'user-agent' => 'test-client/1.0',
            ],
        );

        $data = $serializer->serialize($envelope);
        $restored = $serializer->deserialize($data);

        self::assertInstanceOf(CartUpdated::class, $restored->message);
        self::assertSame('CART-ENV', $restored->message->cartId);
        self::assertCount(2, $restored->message->items);
        self::assertSame('SKU-1', $restored->message->items[0]->productId);
        self::assertSame(3, $restored->message->items[0]->quantity);
        self::assertSame(12.50, $restored->message->items[0]->price);
        self::assertSame('SKU-2', $restored->message->items[1]->productId);
        self::assertSame('sess-abc', $restored->metadata['session-id']);
        self::assertSame('test-client/1.0', $restored->metadata['user-agent']);
    }
}
