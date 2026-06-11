<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket\Message;

use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketFrame;

/** @psalm-api */
final readonly class ChannelMessageReceived
{
    public function __construct(public int $fd, public WebSocketFrame $frame)
    {
    }
}
