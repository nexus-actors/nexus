<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Swoole;

use Closure;
use Monadial\Nexus\Cluster\Tcp\Exception\ProtocolException;
use Monadial\Nexus\Cluster\Tcp\Frame;
use Monadial\Nexus\Cluster\Tcp\FrameCodec;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\PeerLink;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Override;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Client;
use Swoole\Coroutine\Socket;

/**
 * @psalm-api
 *
 * Swoole coroutine-backed PeerLink. One instance wraps a single TCP socket
 * (either the server side accepted from `Swoole\Coroutine\Server` or the
 * client side created by `Swoole\Coroutine\Client`). Frames are length-prefixed
 * on the wire using `FrameCodec`; the receive loop accumulates partial reads
 * in a buffer and emits frames as they complete.
 *
 * Receive loop: started in the constructor via `Runtime::spawn()`. Reads raw
 * bytes from the socket, feeds them to `FrameCodec::decodeStream()`, and
 * dispatches complete frames to registered `onFrame` handlers. The loop exits
 * when the socket is closed by either end.
 *
 * Client lifetime: `Swoole\Coroutine\Client` has a `__destruct` that closes
 * the underlying socket. For client-side links, the Client must be kept alive
 * as long as the PeerLink is alive — pass it as `$clientOwner` so PHP's
 * reference counting keeps it alive.
 *
 * Thread-safety: not required — Swoole coroutines are cooperative and all
 * access to this object happens within the same coroutine scheduler.
 */
final class SwoolePeerLink implements PeerLink
{
    private bool $closed = false;

    /** @var list<Closure(Frame): void> */
    private array $frameHandlers = [];

    /** @var list<Closure(): void> */
    private array $closeHandlers = [];

    /**
     * Frames received before the first onFrame handler is registered.
     * Flushed synchronously when onFrame() is first called.
     *
     * Race condition: in TLS mode, the client-side TLS handshake may complete
     * and the first frame may be sent *before* the server's accept callback
     * (onAccept) has had a chance to call onFrame(). The receive loop is started
     * in the constructor (before onAccept runs), so frames can arrive and be
     * dispatched to an empty frameHandlers list. Buffering them here and flushing
     * on the first onFrame() call resolves the race without requiring callers
     * to delay sending until after handler registration.
     *
     * @var list<Frame>
     */
    private array $pendingFrames = [];

    private readonly FrameCodec $codec;

    /**
     * Capacity-1 channel used as a coroutine mutex to serialise socket writes.
     * Swoole forbids two coroutines writing the same socket concurrently, and
     * {@see Socket::sendAll()} suspends the caller when the send buffer fills — so
     * under load a stalled app-send and a gossip/heartbeat send from another
     * coroutine would otherwise collide and crash. Holding the single token across
     * the write makes every send to this link mutually exclusive.
     */
    private readonly Channel $writeLock;

    public function __construct(
        private readonly Socket $socket,
        private readonly Runtime $runtime,
        private readonly ?NodeEndpoint $remoteEndpoint = null,
        /**
         * Holds the Swoole\Coroutine\Client alive for client-side links.
         * Swoole\Coroutine\Client::__destruct() closes the underlying socket,
         * so without this reference the socket would be closed when connect()
         * returns and the $client local variable goes out of scope.
         */
        private readonly ?Client $clientOwner = null,
    ) {
        $this->codec = new FrameCodec();
        $this->writeLock = new Channel(1);
        $this->writeLock->push(true);
        $this->startReceiveLoop();
    }

    /**
     * Send raw bytes directly to the socket without frame encoding.
     * Useful for testing split/partial-frame scenarios. No-op when closed.
     */
    public function sendRaw(string $bytes): void
    {
        if ($this->closed) {
            return;
        }

        $this->write($bytes);
    }

    #[Override]
    public function sendFrame(Frame $frame): void
    {
        if ($this->closed) {
            return;
        }

        $this->write($this->codec->encode($frame));
    }

    #[Override]
    public function onFrame(callable $onFrame): void
    {
        $this->frameHandlers[] = $onFrame(...);

        // Flush frames buffered before this handler was registered (see $pendingFrames).
        if ($this->pendingFrames !== []) {
            $pending = $this->pendingFrames;
            $this->pendingFrames = [];

            foreach ($pending as $frame) {
                $onFrame($frame);
            }
        }
    }

    #[Override]
    public function onClose(callable $onClose): void
    {
        $this->closeHandlers[] = $onClose(...);
    }

    #[Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->socket->close();
        // onClose handlers are NOT fired here — they fire when the REMOTE end
        // closes (detected by the receive loop). This matches the LoopbackPeerLink
        // semantics: close() notifies the peer; the peer's handlers fire, not ours.
    }

    #[Override]
    public function remote(): ?NodeEndpoint
    {
        return $this->remoteEndpoint;
    }

    /**
     * Spawn the receive loop in a new runtime coroutine. Reads raw bytes from
     * the socket, decodes complete frames via FrameCodec, and dispatches them
     * to registered handlers. Exits when the socket closes or errors.
     */
    private function startReceiveLoop(): void
    {
        $this->runtime->spawn(function (): void {
            $buffer = '';

            while (true) {
                $data = $this->socket->recv(65536);

                if ($data === false) {
                    // A recv timeout is NOT a disconnect. Swoole coroutine sockets carry a finite
                    // default recv timeout, and in a mutual-seed mesh each TCP connection is used
                    // unidirectionally (a node sends to a peer over its own outbound link and
                    // receives over the peer's) — so a link legitimately receives nothing for long
                    // stretches. Treating that timeout as EOF tore the link down every few seconds,
                    // driving perpetual reconnect churn that starved the phi detector into false
                    // Suspect/Down. Keep waiting on a timeout; only a genuine peer close (empty read)
                    // or a hard socket error ends the loop.
                    if (self::isRecvTimeout($this->socket->errCode)) {
                        continue;
                    }

                    $this->notifyClose();

                    return;
                }

                if ($data === '') {
                    $this->notifyClose();

                    return;
                }

                $buffer .= $data;

                try {
                    $result = $this->codec->decodeStream($buffer);
                } catch (ProtocolException) {
                    $this->notifyClose();

                    return;
                }

                $buffer = $result['rest'];

                foreach ($result['frames'] as $frame) {
                    if ($this->frameHandlers === []) {
                        // No handler registered yet — buffer the frame until onFrame() is called.
                        $this->pendingFrames[] = $frame;
                    } else {
                        foreach ($this->frameHandlers as $handler) {
                            $handler($frame);
                        }
                    }
                }
            }
        });
    }

    /**
     * Notify registered onClose handlers that this link has been closed by the
     * remote end. Idempotent — safe to call multiple times.
     */
    private function notifyClose(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        foreach ($this->closeHandlers as $handler) {
            $handler();
        }
    }

    /**
     * Write bytes to the socket under the per-link write mutex so concurrent
     * senders (e.g. an app tell and a gossip frame) never write it at the same
     * time. The token is always returned, even if the write throws.
     */
    private function write(string $bytes): void
    {
        $this->writeLock->pop();

        try {
            $this->socket->sendAll($bytes);
        } finally {
            $this->writeLock->push(true);
        }
    }

    /**
     * Whether a failed recv is a benign timeout (deadline elapsed / would block)
     * rather than a real disconnect. Swoole sets the socket errCode to the POSIX
     * errno; ETIMEDOUT (110) and EAGAIN/EWOULDBLOCK (11) both mean "no data yet,
     * the connection is still open".
     */
    private static function isRecvTimeout(int $errCode): bool
    {
        return $errCode === SOCKET_ETIMEDOUT || $errCode === SOCKET_EAGAIN;
    }
}
