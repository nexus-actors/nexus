<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\WebSocket;

use Monadial\Nexus\Http\Server\Swoole\Threads\WebSocket\Message\WebSocketFramePush;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\WebSocket\Server;

use const WEBSOCKET_OPCODE_BINARY;
use const WEBSOCKET_OPCODE_PING;

/**
 * @psalm-api
 *
 * Thread-mode WebSocket context. Each connection is "owned" by the thread
 * that originally accepted the upgrade (Swoole assigns the fd to that
 * worker thread). The owning thread is the only one whose
 * Swoole\WebSocket\Server::push($fd, ...) will succeed.
 *
 * When the channel actor handling this connection lives on the SAME thread
 * as the owning fd, send() pushes directly via the local server. When the
 * actor lives on a DIFFERENT thread (because channel actors are hash-routed
 * across threads via WorkerNode), send() routes a WebSocketFramePush
 * message through the supplied per-thread router senders — one callable per
 * worker thread, addressing the router actor on that thread.
 *
 * The current thread id (the thread running this context's call site) and
 * the owning thread id (where the fd lives) drive the routing decision.
 *
 * Construction: see SwooleThreadHttpServer for how the per-thread router
 * senders are pre-resolved at WorkerStart.
 */
final readonly class ThreadAwareWebSocketContext implements WebSocketContext
{
    /**
     * @param array<int, callable(WebSocketFramePush): void> $routerSenders
     *   Indexed by thread id; each callable forwards the message to the
     *   router actor on that thread.
     */
    public function __construct(
        private Server $server,
        private int $currentThreadId,
        private int $ownerThreadId,
        private int $fd,
        private ServerRequestInterface $request,
        private array $routerSenders,
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
        if ($this->currentThreadId === $this->ownerThreadId) {
            $this->server->push($this->fd, $text);

            return;
        }

        $this->dispatchRemote(new WebSocketFramePush(
            threadId: $this->ownerThreadId,
            fd: $this->fd,
            payload: $text,
            kind: WebSocketFramePush::KIND_TEXT,
        ));
    }

    #[Override]
    public function sendBinary(string $data): void
    {
        if ($this->currentThreadId === $this->ownerThreadId) {
            $this->server->push($this->fd, $data, WEBSOCKET_OPCODE_BINARY);

            return;
        }

        $this->dispatchRemote(new WebSocketFramePush(
            threadId: $this->ownerThreadId,
            fd: $this->fd,
            payload: $data,
            kind: WebSocketFramePush::KIND_BINARY,
        ));
    }

    #[Override]
    public function sendPing(): void
    {
        if ($this->currentThreadId === $this->ownerThreadId) {
            $this->server->push($this->fd, '', WEBSOCKET_OPCODE_PING);

            return;
        }

        $this->dispatchRemote(new WebSocketFramePush(
            threadId: $this->ownerThreadId,
            fd: $this->fd,
            payload: '',
            kind: WebSocketFramePush::KIND_PING,
        ));
    }

    #[Override]
    public function close(int $code = 1000, string $reason = ''): void
    {
        if ($this->currentThreadId === $this->ownerThreadId) {
            $this->server->disconnect($this->fd, $code, $reason);

            return;
        }

        $this->dispatchRemote(new WebSocketFramePush(
            threadId: $this->ownerThreadId,
            fd: $this->fd,
            payload: '',
            kind: WebSocketFramePush::KIND_CLOSE,
            closeCode: $code,
            closeReason: $reason,
        ));
    }

    private function dispatchRemote(WebSocketFramePush $message): void
    {
        $sender = $this->routerSenders[$this->ownerThreadId] ?? null;

        if ($sender === null) {
            return;
        }

        $sender($message);
    }
}
