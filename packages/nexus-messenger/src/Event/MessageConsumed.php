<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Event;

/**
 * PSR-14 event dispatched after the ReceiverActor delivered a broker message
 * to its target actor and acked it with the transport.
 *
 * @psalm-api
 */
final readonly class MessageConsumed
{
    public function __construct(public object $message, public string $targetPath) {}
}
