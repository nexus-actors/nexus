<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership\Message;

use Monadial\Nexus\Core\Actor\UntracedMessage;

/**
 * @psalm-api
 *
 * Self-scheduled at the gossip cadence. Drives one gossip round via
 * MembershipService::applyTick, which selects up to three Up peers and returns a
 * SendGossip effect the actor hands to its MembershipEffectInterpreter.
 */
final readonly class GossipTick implements UntracedMessage {}
