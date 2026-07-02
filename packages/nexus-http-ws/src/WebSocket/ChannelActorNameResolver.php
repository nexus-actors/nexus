<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use function hash;

/**
 * @psalm-api
 *
 * Maps a path-param value (key) to a deterministic URL-safe actor name.
 * Uses xxh3 to keep the actor name short and collision-resistant while
 * accepting arbitrary key bytes (including multi-byte chars and reserved
 * URL chars).
 */
final class ChannelActorNameResolver
{
    public static function resolve(string $key): string
    {
        return 'ws-channel-' . hash('xxh3', $key);
    }
}
