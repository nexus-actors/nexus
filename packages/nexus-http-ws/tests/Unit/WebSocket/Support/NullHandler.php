<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support;

use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;

final class NullHandler extends WebSocketHandler
{
    public function onMessage(WebSocketFrame $frame): void
    {
        // no-op test double
    }
}
