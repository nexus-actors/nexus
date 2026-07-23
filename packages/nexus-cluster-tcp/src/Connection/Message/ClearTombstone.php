<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Connection\Message;

/**
 * @psalm-api
 *
 * Clear a departed-peer tombstone for `$prefix` — the peer is (re)joining, so a later graceful
 * Leave from it must not be silently deduped against a stale tombstone. {@see
 * \Monadial\Nexus\Cluster\Tcp\Connection\ConnectionSupervisor} also clears the matching tombstone
 * inline as part of {@see RegisterIdentifiedLink} handling; this message exists as the same
 * operation's standalone entry point in the actor's protocol.
 */
final readonly class ClearTombstone
{
    public function __construct(public string $prefix) {}
}
