<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket\Message;

/** @psalm-api */
final readonly class ChannelConnectionClosed
{
    public function __construct(public int $fd, public int $closeCode)
    {
    }
}
