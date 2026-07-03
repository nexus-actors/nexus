<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Event;

/**
 * PSR-14 event dispatched when the ReceiverActor rejected an unroutable
 * broker message back to the transport.
 *
 * @psalm-api
 */
final readonly class MessageRejected
{
    public function __construct(public object $message)
    {
    }
}
