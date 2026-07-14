<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

use Closure;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

use function count;

/**
 * @psalm-api
 *
 * Manages one outbound connection to a peer node. Owns a single PeerLink (obtained
 * via MeshTransport::connect()), a bounded outbound frame queue, and an exponential-backoff
 * reconnect loop.
 *
 * Peer-death vs. intentional-close distinction: the `intentionallyClosed` flag is set
 * BEFORE closing the underlying PeerLink so the `onClose` callback never triggers a
 * reconnect when the local node chose to disconnect.
 *
 * Reconnect backoff: starts at `$initialBackoff`, doubles on each failed attempt up to
 * `$maxBackoff`. Resets to `$initialBackoff` after a successful connection is established
 * and then lost (peer death).
 *
 * Outbound queue: frames sent while disconnected are buffered up to `$queueCap`.
 * Frames beyond the cap are silently dropped and counted via `drops()`.
 *
 * Connection preamble: an optional `$preamble` closure produces a frame that is sent FIRST
 * on every freshly established link — the initial connect AND every reconnect. This is what
 * makes peer identity a per-connection property rather than a per-process one: a dropped and
 * reconnected link, or a peer that restarts, re-announces itself so the remote's inbound
 * handler can re-identify it instead of silently dropping every subsequent frame. The closure
 * is re-invoked per connect so time-sensitive payloads (e.g. a freshly signed handshake) are
 * regenerated each time.
 */
final class PeerConnection
{
    private const int DEFAULT_QUEUE_CAP = 100;

    private ?PeerLink $link = null;

    private bool $intentionallyClosed = false;

    private int $drops = 0;

    /** Once-per-episode overflow warning: reset when a fresh connection is established. */
    private bool $overflowWarned = false;

    private int $reconnectAttempts = 0;

    /** @var list<Frame> */
    private array $queue = [];

    /** @var list<Closure(Frame): void> */
    private array $frameHandlers = [];

    /**
     * @param (Closure(): Frame)|null $preamble Frame sent first on every (re)connect — see class docblock.
     */
    public function __construct(
        private readonly NodeEndpoint $endpoint,
        private readonly MeshTransport $transport,
        private readonly Runtime $runtime,
        private readonly Duration $initialBackoff,
        private readonly Duration $maxBackoff,
        private readonly int $queueCap = self::DEFAULT_QUEUE_CAP,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?Closure $preamble = null,
    ) {
        $this->attemptConnect($this->initialBackoff);
    }

    /**
     * Enqueue a frame for delivery. If connected, sends immediately. If reconnecting,
     * buffers up to the queue cap; frames beyond the cap are dropped and counted.
     * Overflow uses a drop-newest strategy: the incoming frame is discarded while
     * already-buffered frames are retained.
     */
    public function sendFrame(Frame $frame): void
    {
        if ($this->intentionallyClosed) {
            return;
        }

        if ($this->link !== null) {
            $this->link->sendFrame($frame);

            return;
        }

        if (count($this->queue) >= $this->queueCap) {
            ++$this->drops;

            if (!$this->overflowWarned) {
                $this->overflowWarned = true;
                $this->safely(fn(): mixed => $this->logger->warning('cluster.send_buffer.overflow', [
                    'peer' => (string) $this->endpoint,
                    'queue_cap' => $this->queueCap,
                ]));
            }

            return;
        }

        $this->queue[] = $frame;
    }

    /**
     * Register a handler invoked for every frame arriving from the peer.
     *
     * @param callable(Frame): void $onFrame
     */
    public function onFrame(callable $onFrame): void
    {
        $this->frameHandlers[] = $onFrame(...);
    }

    /**
     * Intentionally close this connection. Stops the reconnect loop and closes the
     * underlying link. Queued frames are discarded. Idempotent.
     */
    public function close(): void
    {
        $this->intentionallyClosed = true;
        $link = $this->link;
        $this->link = null;
        $this->queue = [];
        $link?->close();
    }

    /**
     * Number of frames dropped due to queue overflow during reconnect.
     */
    public function drops(): int
    {
        return $this->drops;
    }

    private function attemptConnect(Duration $currentBackoff): void
    {
        if ($this->intentionallyClosed) {
            return;
        }

        try {
            $link = $this->transport->connect($this->endpoint);
        } catch (RuntimeException) {
            $attempt = ++$this->reconnectAttempts;
            $this->safely(fn(): mixed => $this->logger->debug('cluster.peer.reconnect_attempt', [
                'attempt' => $attempt,
                'backoff_ms' => $currentBackoff->toMillis(),
                'peer' => (string) $this->endpoint,
            ]));
            $this->runtime->scheduleOnce($currentBackoff, function () use ($currentBackoff): void {
                $this->attemptConnect($this->growBackoff($currentBackoff));
            });

            return;
        }

        $this->link = $link;
        $this->wireLink($link);

        try {
            $this->sendPreamble($link);
            $this->flushQueue($link);
        } catch (RuntimeException) {
            if (!$this->intentionallyClosed) {
                $this->link = null;
                $this->runtime->scheduleOnce($this->initialBackoff, function (): void {
                    $this->attemptConnect($this->initialBackoff);
                });
            }
        }
    }

    private function wireLink(PeerLink $link): void
    {
        // Reset overflow episode flag — a fresh connection is established.
        $this->overflowWarned = false;

        $link->onFrame(function (Frame $frame): void {
            foreach ($this->frameHandlers as $handler) {
                $handler($frame);
            }
        });

        $link->onClose(function (): void {
            if ($this->intentionallyClosed) {
                return;
            }

            $this->link = null;
            $this->runtime->scheduleOnce($this->initialBackoff, function (): void {
                $this->attemptConnect($this->initialBackoff);
            });
        });
    }

    /**
     * Send the introduction frame (when configured) as the FIRST frame on a freshly
     * established link. Invoked on the initial connect and on every reconnect, so peer
     * identity is re-established after any link drop or peer restart. The closure is
     * re-evaluated each time so a freshly signed/timestamped payload is produced per connect.
     */
    private function sendPreamble(PeerLink $link): void
    {
        if ($this->preamble !== null) {
            $link->sendFrame(($this->preamble)());
        }
    }

    private function flushQueue(PeerLink $link): void
    {
        $queued = $this->queue;
        $this->queue = [];

        foreach ($queued as $frame) {
            $link->sendFrame($frame);
        }
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Logging must never break peer connection operations.
        }
    }

    private function growBackoff(Duration $current): Duration
    {
        $doubled = $current->multipliedBy(2);

        return $doubled->isGreaterThan($this->maxBackoff)
            ? $this->maxBackoff
            : $doubled;
    }
}
