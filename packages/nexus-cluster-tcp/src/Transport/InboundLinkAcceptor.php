<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Transport;

use Closure;
use Monadial\Nexus\Cluster\Tcp\Protocol\Frame;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\BackpressureCapable;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Monadial\Nexus\Runtime\Runtime\Runtime;
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
 *    accepted-but-unproductive connection;
 *  - the OUT-OF-BAND Slowloris backstop (below).
 *
 * Slowloris deadline, two layers: the actor self-schedules a hard in-band {@see HandshakeDeadline}
 * in `Behavior::setup` (immune to intervening junk traffic, unlike a receive-timeout — see that
 * class's docblock) — the graceful primary path, stopping the actor through its normal message
 * loop. But an in-band deadline travels through the actor's bounded DropNewest mailbox, and an
 * attacker who fills that mailbox EXACTLY to capacity with junk and then goes silent starves it:
 * the deadline self-tell arrives at a full mailbox and is dropped, the junk drains to no-ops, no
 * further `offer()` ever returns `Dropped` (the peer is silent), and the unidentified link would
 * hold a `$maxInboundLinks` slot forever. So the acceptor ALSO arms an out-of-band
 * `runtime->scheduleOnce` backstop at `handshakeTimeout + BACKSTOP_GRACE_SECONDS` that closes the
 * raw link directly — never through a mailbox — unless the per-link `$onIdentified` seam (handed to
 * the spawner, invoked by the actor at identification) disarmed it first. The socket close then
 * drives the normal {@see LinkClosedNotice} → actor-stop path. The grace offset keeps the in-band
 * deadline the winner whenever it is deliverable; the backstop only catches the starved case —
 * restoring the pre-actorization acceptor-owned timer's immunity exactly.
 *
 * One acceptor instance is constructed once at boot and reused for every accepted connection —
 * `$inboundLinks` tracks live links across the acceptor's whole lifetime, which is what makes the
 * concurrency cap meaningful.
 */
final class InboundLinkAcceptor
{
    /**
     * Grace added on top of `$handshakeTimeout` before the out-of-band backstop fires, so the
     * in-band {@see HandshakeDeadline} always wins when it is deliverable (see class docblock).
     */
    private const int BACKSTOP_GRACE_SECONDS = 1;

    /** @var array<int, true> Live accepted inbound links by object id — bounds concurrency. */
    private array $inboundLinks = [];

    /**
     * @param Closure(PeerLink, Closure(): void): ActorRef $spawner Spawns a fresh
     *        {@see \Monadial\Nexus\Cluster\Tcp\Connection\InboundLinkActor} for the accepted link
     *        (injected by `ClusterNode::wireInboundLink()`, which supplies every collaborator the
     *        actor needs plus this specific `$link` and its Slowloris `$handshakeTimeout`). The
     *        second argument is the per-link `$onIdentified` seam the actor must invoke at
     *        identification — it disarms this acceptor's out-of-band Slowloris backstop.
     */
    public function __construct(
        private readonly Runtime $runtime,
        private readonly int $maxInboundLinks,
        private readonly Duration $handshakeTimeout,
        private readonly Closure $spawner,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Accept a freshly-inbound PeerLink: enforce the concurrency cap, arm the out-of-band Slowloris
     * backstop, spawn the link's {@see \Monadial\Nexus\Cluster\Tcp\Connection\InboundLinkActor}, and
     * wire the frame/close pumps.
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
        $remoteLabel = $link->remote() !== null
            ? (string) $link->remote()
            : 'unknown';

        // Out-of-band Slowloris backstop: closes the raw link directly — never through the actor's
        // mailbox, which a full-to-capacity junk fill can starve (see class docblock). The
        // {@see LinkIdentity} flag check covers a timer that fires between the deadline elapsing
        // and a same-instant identification's cancel being processed.
        $identity = new LinkIdentity();
        $backstop = $this->runtime->scheduleOnce(
            $this->handshakeTimeout->plus(Duration::seconds(self::BACKSTOP_GRACE_SECONDS)),
            function () use ($identity, $link, $remoteLabel): void {
                if ($identity->identified) {
                    return;
                }

                $this->safely(fn(): mixed => $this->logger->warning('cluster.handshake.timeout', [
                    'path' => 'backstop',
                    'peer_endpoint' => $remoteLabel,
                ]));
                $link->close();
            },
        );

        $onIdentified = static function () use ($identity, $backstop): void {
            $identity->identified = true;
            $backstop->cancel();
        };

        $ref = ($this->spawner)($link, $onIdentified);

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

        $link->onClose(function () use ($ref, $linkId, $backstop): void {
            // A closed link needs no backstop — cancelling here (rather than letting the flag
            // check no-op it) avoids a dead timer per short-lived connection.
            $backstop->cancel();
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
