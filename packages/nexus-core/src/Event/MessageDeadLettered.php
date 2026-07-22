<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Event;

/**
 * PSR-14 event dispatched whenever a message reaches the dead-letter sink —
 * an undeliverable, unhandled, or dropped message. Subscribe to observe delivery
 * failures without polling the bounded sample buffer.
 *
 * @psalm-api
 * @psalm-immutable
 */
final readonly class MessageDeadLettered
{
    public function __construct(public object $message) {}
}
