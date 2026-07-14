<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

/**
 * @psalm-api
 *
 * Marker for an outbound action produced by a membership transition. Concrete
 * effects: HandshakeResponse (reply to a connecting peer), SendGossip
 * (anti-entropy gossip round). The owning actor (C1.6d) executes effects; the
 * transition only returns them — no I/O is performed inside the transition
 * functions.
 */
interface MembershipEffect {}
