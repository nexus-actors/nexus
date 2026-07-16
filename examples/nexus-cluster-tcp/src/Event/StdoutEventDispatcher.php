<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\ClusterTcp\Event;

use Monadial\Nexus\Cluster\Tcp\Membership\NodeDown;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeSuspected;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeUp;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerConnected;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerDisconnected;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Minimal PSR-14 dispatcher that prints cluster lifecycle events to stdout.
 *
 * Handles:
 *   - {@see NodeUp}          — a peer joined or recovered from Suspect
 *   - {@see NodeDown}        — a peer was declared dead (phi threshold / Leave)
 *   - {@see NodeSuspected}   — a peer missed heartbeats; may recover or go Down
 *   - {@see PeerConnected}   — TCP handshake succeeded with a peer
 *   - {@see PeerDisconnected} — TCP link to a peer closed
 */
final class StdoutEventDispatcher implements EventDispatcherInterface
{
    #[Override]
    public function dispatch(object $event): object
    {
        $time = date('H:i:s');

        if ($event instanceof NodeUp) {
            printf(
                "[%s] [MEMBERSHIP] UP       node=%-42s endpoint=%s\n",
                $time,
                $event->node->toPathPrefix(),
                (string) $event->endpoint,
            );
        } elseif ($event instanceof NodeDown) {
            printf(
                "[%s] [MEMBERSHIP] DOWN     node=%s\n",
                $time,
                $event->node->toPathPrefix(),
            );
        } elseif ($event instanceof NodeSuspected) {
            printf(
                "[%s] [MEMBERSHIP] SUSPECT  node=%-42s reason=%s\n",
                $time,
                $event->node->toPathPrefix(),
                $event->reason->name,
            );
        } elseif ($event instanceof PeerConnected) {
            printf(
                "[%s] [TRANSPORT]  CONNECT  peer=%-42s endpoint=%s\n",
                $time,
                $event->peer->toPathPrefix(),
                (string) $event->endpoint,
            );
        } elseif ($event instanceof PeerDisconnected) {
            printf(
                "[%s] [TRANSPORT]  DISCNNCT peer=%s\n",
                $time,
                $event->peer->toPathPrefix(),
            );
        }

        return $event;
    }
}
