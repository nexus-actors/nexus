<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket\Message;

use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;

/** @internal */
final readonly class ChannelMessageReceived
{
    public function __construct(public int $fd, public WebSocketFrame $frame) {}
}
