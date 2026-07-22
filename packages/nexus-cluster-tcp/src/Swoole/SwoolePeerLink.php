<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Swoole;

use Closure;
use Monadial\Nexus\Cluster\Tcp\DeliveryOutcome;
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
use Throwable;

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
    /** Bytes read per recv() call; also the reassembly-buffer slack over one max frame. */
    private const int RECV_CHUNK = 65536;

    /** Max frames buffered before onFrame() registration — bounds a pre-handshake frame flood. */
    private const int PENDING_FRAME_LIMIT = 1024;

    /**
     * Send deadline (seconds). {@see Socket::sendAll()} suspends the caller while the peer's receive
     * buffer is full; without a deadline a single stalled or dead peer would block the coroutine that
     * drives gossip/heartbeats to EVERY peer (head-of-line blocking), so the whole cluster suspects a
     * node that is merely stuck behind one slow link. On deadline (or any short write) the link is torn
     * down and its outbound PeerConnection reconnects with a clean frame boundary. Generous enough not
     * to trip on transient backpressure, far below the default `maxNoHeartbeat` (10 s).
     */
    private const float SEND_TIMEOUT_SECONDS = 5.0;

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
        private readonly int $maxFrameSize = 8 * 1024 * 1024,
        /**
         * Invoked (isolated) when a frame handler throws — lets the transport's owner record a
         * metric/log for an otherwise-silent dropped frame. Null = no reporting (still swallowed).
         *
         * @var (Closure(Throwable): void)|null
         */
        private readonly ?Closure $onHandlerError = null,
    ) {
        $this->codec = new FrameCodec($this->maxFrameSize);
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
    public function sendFrame(Frame $frame): DeliveryOutcome
    {
        if ($this->closed) {
            return DeliveryOutcome::Dropped;
        }

        return $this->write($this->codec->encode($frame))
            ? DeliveryOutcome::Admitted
            : DeliveryOutcome::Dropped;
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
                $this->dispatchFrame($frame);
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
                $data = $this->socket->recv(self::RECV_CHUNK);

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

                // Bound the reassembly buffer. A well-formed peer never buffers more than one
                // in-flight frame (decodeStream trims completed frames and rejects any declared
                // length over maxFrameSize at the 4-byte prefix), so anything beyond one max frame
                // plus a recv chunk is a misbehaving peer pinning memory with a partial frame —
                // close the link rather than let it grow. Operators cap the per-link bound itself
                // via ClusterTopology::withMaxFrameSize().
                if (strlen($buffer) > $this->maxFrameSize + self::RECV_CHUNK) {
                    $this->notifyClose();

                    return;
                }

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
                        // Cap the backlog so a peer that floods frames before a handler is wired
                        // cannot pin unbounded memory.
                        if (count($this->pendingFrames) >= self::PENDING_FRAME_LIMIT) {
                            $this->notifyClose();

                            return;
                        }

                        $this->pendingFrames[] = $frame;
                    } else {
                        $this->dispatchFrame($frame);
                    }
                }
            }
        });
    }

    /**
     * Dispatch a decoded frame to every registered handler, isolating each call so a
     * throwing handler cannot escape the receive-loop coroutine. An unhandled exception
     * here (e.g. a bounded mailbox rejecting delivery, or a codec edge in a downstream
     * handler) would otherwise terminate the recv loop, leaving the socket open but never
     * read again — the peer keeps sending into a dead pipe and we falsely Suspect a healthy
     * node. Swallowing per-frame keeps the link alive; a genuinely broken link still ends
     * via EOF / socket error / the reassembly bound.
     */
    private function dispatchFrame(Frame $frame): void
    {
        foreach ($this->frameHandlers as $handler) {
            try {
                $handler($frame);
            } catch (Throwable $e) {
                // A handler failure must never kill the receive loop (see method docblock), but a
                // silently-dropped frame is undiagnosable — surface it via the reporting hook.
                if ($this->onHandlerError !== null) {
                    try {
                        ($this->onHandlerError)($e);
                    } catch (Throwable) {
                        // Reporting must never break the loop either.
                    }
                }
            }
        }
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
     *
     * @return bool `true` when the full frame was written (admitted), `false` on a short
     *   write — the caller reports that as a {@see DeliveryOutcome::Dropped}. A short write is
     *   never silently swallowed as success.
     */
    private function write(string $bytes): bool
    {
        if ($this->closed) {
            return false;
        }

        $this->writeLock->pop();

        try {
            $sent = $this->socket->sendAll($bytes, self::SEND_TIMEOUT_SECONDS);
        } finally {
            $this->writeLock->push(true);
        }

        if ($sent === strlen($bytes)) {
            return true;
        }

        // Short write: the peer did not accept the full frame within the deadline (stalled/dead peer)
        // or the socket errored, and the wire may be mid-frame. Tear the link down rather than keep
        // blocking the loop on it — the outbound PeerConnection reconnects with a clean boundary, and
        // gossip/heartbeats to every other peer keep flowing. notifyClose() fires our onClose handlers
        // (which drive that reconnect); it is idempotent with the receive loop's own close detection.
        $this->socket->close();
        $this->notifyClose();

        return false;
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
