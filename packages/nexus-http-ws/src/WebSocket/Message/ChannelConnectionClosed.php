<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket\Message;

/** @internal */
final readonly class ChannelConnectionClosed
{
    public function __construct(public int $fd, public int $code) {}
}
