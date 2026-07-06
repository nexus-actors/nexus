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

        $this->socket->sendAll($bytes);
    }

    #[Override]
    public function sendFrame(Frame $frame): void
    {
        if ($this->closed) {
            return;
        }

        $codec = new FrameCodec();
        $this->socket->sendAll($codec->encode($frame));
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
            $codec = new FrameCodec();
            $buffer = '';

            while (true) {
                $data = $this->socket->recv(65536);

                if ($data === false || $data === '') {
                    $this->notifyClose();

                    return;
                }

                $buffer .= $data;

                try {
                    $result = $codec->decodeStream($buffer);
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
}
