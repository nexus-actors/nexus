<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Monadial\Nexus\Cluster\NodeAddress;
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
    public function send(NodeAddress $target, MessagePayload $payload): void;
}
