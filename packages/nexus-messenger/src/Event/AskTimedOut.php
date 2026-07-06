<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Event;

/**
 * PSR-14 event dispatched when a pending ask expires without receiving a reply.
 *
 * Emitted by {@see \Monadial\Nexus\Messenger\Ask\AskSupport} when the scheduled
 * timeout fires and the correlation ID is still present in the registry (i.e. no
 * reply arrived before the deadline).
 *
 * @psalm-api
 */
final readonly class AskTimedOut
{
    public function __construct(public string $correlationId) {}
}
