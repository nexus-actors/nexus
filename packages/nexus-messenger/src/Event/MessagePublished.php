<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Event;

/**
 * PSR-14 event dispatched after a message was successfully handed to a
 * Messenger sender by {@see \Monadial\Nexus\Messenger\Producer\MessengerActorRef}
 * or {@see \Monadial\Nexus\Messenger\Producer\MessengerGateway}.
 *
 * @psalm-api
 */
final readonly class MessagePublished
{
    public function __construct(public object $message, public string $senderName) {}
}
