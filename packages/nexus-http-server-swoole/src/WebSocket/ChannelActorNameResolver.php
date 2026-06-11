<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

use function bin2hex;
use function hash;
use function substr;

/**
 * @psalm-api
 *
 * Stable per-key actor name. Uses xxh3 (PHP's hash() supports it as of 8.1)
 * truncated to 16 hex chars — collision-resistant enough for in-process
 * actor naming, URL-character safe.
 */
final class ChannelActorNameResolver
{
    public static function resolve(string $rawKey): string
    {
        $hash = bin2hex(substr(hash('xxh3', $rawKey, true), 0, 8));

        return "ws-channel-{$hash}";
    }
}
