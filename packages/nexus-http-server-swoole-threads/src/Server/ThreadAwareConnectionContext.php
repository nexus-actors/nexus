<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\WebSocket\Server as WebSocketServer;

use const WEBSOCKET_OPCODE_BINARY;
use const WEBSOCKET_OPCODE_PING;

/**
 * @psalm-api
 *
 * Thread-mode WebSocket context. Each connection is owned by the Swoole
 * worker thread that accepted the upgrade — Swoole assigns the fd to that
 * thread, so push() works unconditionally on the local server handle.
 *
 * Channel-actor routes are rejected at boot (SwooleThreadServer calls
 * WebSocketRouter::assertNoChannelRoutes()), so all WebSocket traffic is
 * handler-mode and remains on the same thread; no cross-thread dispatch is
 * required here (v1).
 */
final class ThreadAwareConnectionContext implements WebSocketContext
{
    public function __construct(
        private readonly WebSocketServer $server,
        private readonly int $fd,
        private ServerRequestInterface $request,
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
    public function withRequest(ServerRequestInterface $request): WebSocketContext
    {
        $clone = clone $this;
        $clone->request = $request;

        return $clone;
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

    #[Override]
    public function isAlive(): bool
    {
        // exist() returns bool at runtime; the base Swoole\Server class is not
        // stubbed, so the comparison narrows the untyped return deterministically.
        return $this->server->exist($this->fd) === true;
    }
}
