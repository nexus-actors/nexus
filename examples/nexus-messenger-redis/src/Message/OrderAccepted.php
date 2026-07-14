<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\MessengerRedis\Message;

use Monadial\Nexus\Serialization\MessageType;

/**
 * Reply published by OrderProcessor on the ask path.
 *
 * When a request arrives carrying an X-Nexus-Correlation-Id / X-Nexus-Reply-To
 * pair, the ReceiverActor delivers it with a MessengerReplyRef as the sender.
 * OrderProcessor answers with this message; it travels back over the reply
 * transport (the "replies" stream) and resolves the asker's Future.
 *
 * Like OrderPlaced it carries a stable #[MessageType] wire name so the same
 * serializer + TypeRegistry can round-trip it on the reply channel.
 */
#[MessageType('order-accepted')]
final readonly class OrderAccepted
{
    public function __construct(
        public string $orderId,
        public string $status,
    ) {}
}
