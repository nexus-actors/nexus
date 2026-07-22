<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\DeliveryOutcome;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;

/**
 * @psalm-api
 *
 * Egress seam for cluster messages. `ClusterRef` and inbound reply senders depend on
 * this interface rather than a concrete transport so the same-thread `PeerConnection`
 * send (C1 default) and the future gateway-thread queue egress can be swapped in with
 * zero rework.
 */
interface OutboundSink
{
    /**
     * Send one message to a peer under at-most-once semantics.
     *
     * @return DeliveryOutcome the admission outcome — {@see DeliveryOutcome::Admitted} (written
     *   to a live link), {@see DeliveryOutcome::Buffered} (queued for reconnect), or
     *   {@see DeliveryOutcome::Dropped} (no route, buffer full, or write failed). Never a
     *   delivery receipt; see {@see DeliveryOutcome}.
     */
    public function send(NodeAddress $target, MessagePayload $payload): DeliveryOutcome;
}
