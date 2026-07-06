<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Event;

/**
 * PSR-14 event dispatched when a pending ask is resolved by an incoming reply.
 *
 * Emitted by {@see \Monadial\Nexus\Messenger\Ask\ReplyConsumer} after
 * successfully matching a reply envelope to a pending future slot via its
 * correlation ID.
 *
 * @psalm-api
 */
final readonly class AskResolved
{
    public function __construct(public string $correlationId, public object $reply) {}
}
