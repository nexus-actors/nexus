<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Transport;

use Closure;
use Monadial\Nexus\Cluster\Tcp\Protocol\Frame;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\BackpressureCapable;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function count;
use function hrtime;
use function spl_object_id;

/**
 * @psalm-api
 *
 * Accepts inbound {@see PeerLink}s from a mesh transport's `serve()` listener and, per accepted
 * link, spawns a fresh {@see \Monadial\Nexus\Cluster\Tcp\Connection\InboundLinkActor} (via the
 * injected `$spawner`) to run its Unidentified→Identified frame state machine.
 *
 * What stays here (the pump, not the state machine):
 *  - the unauthenticated-link concurrency cap (`$maxInboundLinks`) — links are pre-auth, so a peer
 *    must not be able to exhaust memory with endless open sockets;
 *  - the C3 ingress stamp: `$clock->now()` and `hrtime(true)` are captured HERE, synchronously with
 *    the transport's frame callback, and carried on the {@see LinkFrame} message rather than
 *    recomputed once the frame reaches the actor's mailbox (which can lag under load) — see
 *    {@see \Monadial\Nexus\Cluster\Tcp\Connection\InboundLinkActor}'s class docblock;
 *  - the pre-auth flood bound: an `EnqueueResult::Dropped` `offer()` (the actor's bounded mailbox
 *    rejected the frame) closes the link outright, rather than let a flooding peer sit on an
 *    accepted-but-unproductive connection.
 *
 * The Slowloris handshake deadline is NOT owned here any more — it is armed by the actor itself
 * (`setReceiveTimeout()` in `Behavior::setup`), since the actor is what knows whether it has
 * identified yet. One acceptor instance is constructed once at boot and reused for every accepted
 * connection — `$inboundLinks` tracks live links across the acceptor's whole lifetime, which is
 * what makes the concurrency cap meaningful.
 */
final class InboundLinkAcceptor
{
    /** @var array<int, true> Live accepted inbound links by object id — bounds concurrency. */
    private array $inboundLinks = [];

    /**
     * @param Closure(PeerLink): ActorRef $spawner Spawns a fresh
     *        {@see \Monadial\Nexus\Cluster\Tcp\Connection\InboundLinkActor} for the accepted link
     *        (injected by `ClusterNode::wireInboundLink()`, which supplies every collaborator the
     *        actor needs plus this specific `$link` and its Slowloris `$handshakeTimeout`).
     */
    public function __construct(
        private readonly int $maxInboundLinks,
        private readonly Closure $spawner,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Accept a freshly-inbound PeerLink: enforce the concurrency cap, spawn its
     * {@see \Monadial\Nexus\Cluster\Tcp\Connection\InboundLinkActor}, and wire the frame/close pumps.
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

        $ref = ($this->spawner)($link);

        $link->onFrame(function (Frame $frame) use ($link, $ref): void {
            // C3: stamp the arrival instant HERE, synchronously with the transport callback — see
            // this class's and InboundLinkActor's docblocks.
            $message = new LinkFrame($frame, $this->clock->now(), hrtime(true));

            if (!$ref instanceof BackpressureCapable) {
                $ref->tell($message);

                return;
            }

            if ($ref->offer($message) === EnqueueResult::Dropped) {
                // Pre-auth flood bound: the actor's bounded mailbox rejected the frame outright.
                $link->close();
            }
        });

        $link->onClose(function () use ($ref, $linkId): void {
            unset($this->inboundLinks[$linkId]);
            $ref->tell(new LinkClosedNotice());
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
