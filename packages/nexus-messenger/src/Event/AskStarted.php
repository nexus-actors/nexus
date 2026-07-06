<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Event;

/**
 * PSR-14 event dispatched when an ask request is published to the transport.
 *
 * Emitted by {@see \Monadial\Nexus\Messenger\Producer\MessengerActorRef} after
 * the request envelope is successfully handed off to the Symfony Messenger sender.
 *
 * @psalm-api
 */
final readonly class AskStarted
{
    public function __construct(public object $message, public string $correlationId) {}
}
