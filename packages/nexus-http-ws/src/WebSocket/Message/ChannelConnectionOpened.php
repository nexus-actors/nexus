<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket\Message;

use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Sent by the dispatcher to a channel actor when a new connection joins.
 * Translated into the actor's onOpened() hook by WebSocketChannelActor.
 *
 * @psalm-api
 */
final readonly class ChannelConnectionOpened
{
    public function __construct(
        public int $fd,
        public WebSocketContext $ctx,
        public ServerRequestInterface $upgradeRequest,
    ) {}
}
