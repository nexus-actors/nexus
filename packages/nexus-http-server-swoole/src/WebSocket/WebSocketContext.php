<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Per-connection context handed to WebSocketHandler factories AND to channel
 * actors via ChannelConnectionOpened messages.
 *
 * Two impls: LocalWebSocketContext (worker mode + same-thread thread mode),
 * ThreadAwareWebSocketContext (thread mode, cross-thread send via WorkerTransport).
 */
interface WebSocketContext
{
    public function id(): int;

    public function request(): ServerRequestInterface;

    public function send(string $text): void;

    public function sendBinary(string $data): void;

    public function sendPing(): void;

    public function close(int $code = 1000, string $reason = ''): void;
}
