<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Transport;

use Closure;
use Monadial\Nexus\Cluster\Tcp\Protocol\Frame;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function count;
use function spl_object_id;

/**
 * @psalm-api
 *
 * Accepts inbound {@see PeerLink}s from a mesh transport's `serve()` listener and drives their
 * frame pump: enforces the unauthenticated-link concurrency cap, arms a Slowloris handshake
 * deadline, and forwards frames to the injected `$frameSink` (a bound partial of
 * {@see ClusterNode::handleLinkFrame()}) until the link closes.
 *
 * One acceptor instance is constructed once at boot and reused for every accepted connection —
 * `$inboundLinks` tracks live links across the acceptor's whole lifetime, which is what makes the
 * concurrency cap meaningful.
 *
 * Slowloris deadline ownership: the acceptor owns the per-link {@see Cancellable} timer and
 * cancels it itself the moment a link's `LinkState::$peerAddr` transitions from unset to set
 * (i.e. the link just identified via a valid Handshake) — `$frameSink` never needs to know the
 * deadline exists. It is also cancelled on close, so a link that never identifies cannot leak
 * a timer past its own teardown.
 */
final class InboundLinkAcceptor
{
    /** @var array<int, true> Live accepted inbound links by object id — bounds concurrency. */
    private array $inboundLinks = [];

    /**
     * @param Closure(Frame, LinkState, string): void $frameSink    ClusterNode::handleLinkFrame
     *        partial (router + accepted-callback pre-bound); called for every frame once the
     *        deadline/capacity bookkeeping below has run.
     * @param Closure(LinkState, PeerLink): void $onLinkClosed ClusterNode's close bookkeeping
     *        bundle (accepted-link removal, tombstone, liveness-throttle forget, membership
     *        notification, ask-registry failure).
     */
    public function __construct(
        private readonly Runtime $runtime,
        private readonly int $maxInboundLinks,
        private readonly Duration $handshakeTimeout,
        private readonly Closure $frameSink,
        private readonly Closure $onLinkClosed,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Accept a freshly-inbound PeerLink: enforce the concurrency cap, arm the Slowloris deadline,
     * and wire the frame and close pumps.
     */
    public function accept(PeerLink $link): void
    {
        // Concurrency cap: inbound links are unauthenticated, so refuse new ones once the live
        // ceiling is reached rather than let a peer exhaust memory with endless open sockets.
        if (count($this->inboundLinks) >= $this->maxInboundLinks) {
            $this->safely(fn(): mixed => $this->logger->warning('cluster.inbound.capacity_exceeded', [
                'limit' => $this->maxInboundLinks,
                'peer_endpoint' => $link->remote() !== null ? (string) $link->remote() : 'unknown',
            ]));
            $link->close();

            return;
        }

        $linkId = spl_object_id($link);
        $this->inboundLinks[$linkId] = true;
        $state = new LinkState();
        $state->link = $link;
        $remoteLabel = $link->remote() !== null
            ? (string) $link->remote()
            : 'unknown';

        // Slowloris guard: close the link if it never completes a valid handshake in time. The
        // receive loop tolerates recv timeouts, so an unidentified link would otherwise idle forever.
        $deadline = $this->runtime->scheduleOnce(
            $this->handshakeTimeout,
            function () use ($state, $link, $linkId, $remoteLabel): void {
                if ($state->peerAddr !== null) {
                    return;
                }

                unset($this->inboundLinks[$linkId]);
                $this->safely(fn(): mixed => $this->logger->warning('cluster.handshake.timeout', [
                    'peer_endpoint' => $remoteLabel,
                ]));
                $link->close();
            },
        );

        $link->onFrame(function (Frame $frame) use ($state, $remoteLabel, $deadline): void {
            $wasIdentified = $state->peerAddr !== null;
            ($this->frameSink)($frame, $state, $remoteLabel);

            if (!$wasIdentified && $state->peerAddr !== null) {
                $deadline->cancel();
            }
        });

        $link->onClose(function () use ($link, $state, $linkId, $deadline): void {
            $deadline->cancel();
            unset($this->inboundLinks[$linkId]);

            ($this->onLinkClosed)($state, $link);
        });
    }

    /**
     * Number of currently-live accepted inbound links (introspection — replaces the direct
     * `count($this->inboundLinks)` a caller used when the map lived on ClusterNode).
     */
    public function liveInboundCount(): int
    {
        return count($this->inboundLinks);
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break cluster operations.
        }
    }
}
