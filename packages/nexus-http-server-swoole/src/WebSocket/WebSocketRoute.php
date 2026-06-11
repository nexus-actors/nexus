<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

use Closure;
use Monadial\Nexus\Core\Actor\Props;

/**
 * @psalm-api
 *
 * Immutable WebSocket route. Two flavours by mode:
 *   - HANDLER mode: $factory is a Closure(WebSocketContext): WebSocketHandler
 *   - CHANNEL mode: $props is a Props for the channel actor; $keyFrom names
 *     the path parameter that drives actor identity.
 */
final readonly class WebSocketRoute
{
    public const string MODE_HANDLER = 'handler';
    public const string MODE_CHANNEL = 'channel';

    public function __construct(
        public string $mode,
        public string $path,
        public ?Closure $factory,
        public ?Props $props,
        public ?string $keyFrom,
    ) {
    }

    public static function handler(string $path, Closure $factory): self
    {
        return new self(self::MODE_HANDLER, $path, $factory, null, null);
    }

    public static function channel(string $path, Props $props, string $keyFrom): self
    {
        return new self(self::MODE_CHANNEL, $path, null, $props, $keyFrom);
    }
}
