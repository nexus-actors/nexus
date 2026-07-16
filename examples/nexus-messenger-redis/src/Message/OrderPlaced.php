<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\MessengerRedis\Message;

use Monadial\Nexus\Serialization\MessageType;

/**
 * @psalm-api — constructed by bin/produce.php and rebuilt by the deserializer on the worker side.
 *
 * Domain event published by the producer and routed to OrderProcessor actors.
 *
 * The #[MessageType] attribute registers a stable wire-format name so the
 * serializer can resolve the class during deserialization. It is also the
 * key used in the NexusMessengerSerializer's "type" header, decoupling the
 * wire name from the fully qualified PHP class name.
 */
#[MessageType('order-placed')]
final readonly class OrderPlaced
{
    public function __construct(
        public string $orderId,
        public string $customerId,
        public int $amountCents,
    ) {
    }
}
