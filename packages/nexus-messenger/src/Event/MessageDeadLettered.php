<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Event;

/**
 * PSR-14 event dispatched when the ReceiverActor forwarded an unroutable
 * broker message to dead letters and acked it with the transport.
 *
 * @psalm-api
 */
final readonly class MessageDeadLettered
{
    public function __construct(public object $message)
    {
    }
}
