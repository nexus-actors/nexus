<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

/**
 * @psalm-api
 *
 * Handler-mode WebSocket interface. The factory passed to $app->webSocket()
 * is called once per connection on Open; returns an instance of this.
 */
interface WebSocketHandler
{
    public function onMessage(WebSocketFrame $frame): void;

    public function onClose(int $closeCode): void;
}
