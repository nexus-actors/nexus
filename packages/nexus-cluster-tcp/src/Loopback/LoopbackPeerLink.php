<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Loopback;

use Closure;
use Monadial\Nexus\Cluster\Tcp\DeliveryOutcome;
use Monadial\Nexus\Cluster\Tcp\Frame;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\PeerLink;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Override;

/**
 * @psalm-api
 *
 * One end of an in-process loopback link. Two LoopbackPeerLink instances are
 * created per connection (client end + server end) and wired together via
 * linkPeer(). Frame delivery is asynchronous: sendFrame() spawns a short-lived
 * runtime task so delivery happens on the next event-loop tick rather than
 * inline, matching the semantics of a real TCP socket.
 *
 * receiveFrame() and receiveClose() are public so that the spawned closures
 * (which are static and thus have no $this context) can invoke them on the
 * peer object. They are not part of the PeerLink contract and should not be
 * called by application code.
 */
final class LoopbackPeerLink implements PeerLink
{
    /** @var list<Closure(Frame): void> */
    private array $frameHandlers = [];

    /** @var list<Closure(): void> */
    private array $closeHandlers = [];

    private bool $closed = false;

    private ?self $peer = null;

    public function __construct(private readonly Runtime $runtime, private readonly ?NodeEndpoint $remoteEndpoint) {}

    /**
     * Wire this link to its counterpart. Called once by LoopbackMeshTransport
     * immediately after creating both ends of a connection.
     */
    public function linkPeer(self $peer): void
    {
        $this->peer = $peer;
    }

    /**
     * Deliver $frame to this link's registered onFrame handlers.
     * Called by the peer's sendFrame() via a runtime-spawned task.
     */
    public function receiveFrame(Frame $frame): void
    {
        foreach ($this->frameHandlers as $handler) {
            $handler($frame);
        }
    }

    /**
     * Notify this link that the peer has closed. Marks the link closed and
     * invokes all registered onClose handlers.
     * Called by the peer's close() via a runtime-spawned task.
     */
    public function receiveClose(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        foreach ($this->closeHandlers as $handler) {
            $handler();
        }
    }

    #[Override]
    public function sendFrame(Frame $frame): DeliveryOutcome
    {
        $peer = $this->peer;

        if ($this->closed || $peer === null) {
            return DeliveryOutcome::Dropped;
        }

        $this->runtime->spawn(static function () use ($peer, $frame): void {
            $peer->receiveFrame($frame);
        });

        return DeliveryOutcome::Admitted;
    }

    #[Override]
    public function onFrame(callable $onFrame): void
    {
        $this->frameHandlers[] = $onFrame(...);
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
        $peer = $this->peer;

        if ($peer !== null) {
            $this->runtime->spawn(static function () use ($peer): void {
                $peer->receiveClose();
            });
        }
    }

    #[Override]
    public function remote(): ?NodeEndpoint
    {
        return $this->remoteEndpoint;
    }
}
