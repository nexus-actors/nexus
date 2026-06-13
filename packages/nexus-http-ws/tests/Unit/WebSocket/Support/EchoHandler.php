<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support;

use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class EchoHandler extends WebSocketHandler
{
    public LoggerInterface $log;

    public function __construct(#[FromContext] public readonly WebSocketContext $ctx, ?LoggerInterface $log = null)
    {
        $this->log = $log ?? new NullLogger();
    }

    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('echo:' . $frame->text);
    }
}
