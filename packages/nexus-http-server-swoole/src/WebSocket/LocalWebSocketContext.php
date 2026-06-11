<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\WebSocket\Server;

use const WEBSOCKET_OPCODE_BINARY;
use const WEBSOCKET_OPCODE_PING;

/**
 * @psalm-api
 *
 * Same-process WebSocket context. send() pushes directly via the local
 * Swoole\WebSocket\Server. Used in worker mode and in thread mode for
 * same-thread fds.
 */
final readonly class LocalWebSocketContext implements WebSocketContext
{
    public function __construct(private Server $server, private int $fd, private ServerRequestInterface $request,) {
    }

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
}
