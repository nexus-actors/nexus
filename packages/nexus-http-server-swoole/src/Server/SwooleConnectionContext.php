<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\WebSocket\Server as WebSocketServer;

use const WEBSOCKET_OPCODE_BINARY;
use const WEBSOCKET_OPCODE_PING;

/**
 * @psalm-api
 *
 * Worker-mode WebSocketContext — pushes directly to the local Swoole
 * WebSocket server. One instance per connection.
 */
final class SwooleConnectionContext implements WebSocketContext
{
    public function __construct(
        private readonly WebSocketServer $server,
        private readonly int $fd,
        private readonly ServerRequestInterface $request,
    ) {}

    #[Override]
    public function id(): int
    {
        return $this->fd;
    }

    #[Override]
    public function request(): ServerRequestInterface
    {
        return $this->request;
    }

    #[Override]
    public function send(string $text): void
    {
        $this->server->push($this->fd, $text);
    }

    #[Override]
    public function sendBinary(string $data): void
    {
        $this->server->push($this->fd, $data, WEBSOCKET_OPCODE_BINARY);
    }

    #[Override]
    public function sendPing(): void
    {
        $this->server->push($this->fd, '', WEBSOCKET_OPCODE_PING);
    }

    #[Override]
    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->server->disconnect($this->fd, $code, $reason);
    }

    /**
     * @psalm-suppress MixedReturnStatement,MixedInferredReturnType
     */
    #[Override]
    public function isAlive(): bool
    {
        return $this->server->exist($this->fd);
    }
}
