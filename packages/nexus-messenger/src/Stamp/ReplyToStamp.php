<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Reply-to channel stamp: the logical name of the channel where replies should be sent.
 *
 * Attached by MessengerActorRef when a reply-to channel is configured, and
 * round-tripped by NexusMessengerSerializer as the X-Nexus-Reply-To header.
 *
 * Responders resolve the channel name to a transport address via a configured map —
 * the wire header is never interpreted as a transport directly (SSRF hardening).
 *
 * @psalm-api
 */
final readonly class ReplyToStamp implements StampInterface
{
    public function __construct(public string $channel) {}
}
