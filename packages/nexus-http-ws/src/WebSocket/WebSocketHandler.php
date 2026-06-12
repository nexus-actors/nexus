<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

/**
 * @psalm-api
 *
 * Per-connection POPO base. One instance per WebSocket connection,
 * resolved via PSR-11. Constructor parameters can use #[FromContext]
 * to inject the current WebSocketContext and #[FromActor('name')] to
 * inject any registered ActorRef; other parameters resolve through
 * the container normally.
 */
abstract class WebSocketHandler
{
    abstract public function onMessage(WebSocketFrame $frame): void;

    public function onOpen(): void
    {
        // Default: no-op.
    }

    public function onClose(int $code): void
    {
        // Default: no-op.
    }
}
