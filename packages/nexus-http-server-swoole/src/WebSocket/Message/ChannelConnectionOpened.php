<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket\Message;

use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;
use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
final readonly class ChannelConnectionOpened
{
    public function __construct(public int $fd, public WebSocketContext $ctx, public ServerRequestInterface $request)
    {
    }
}
