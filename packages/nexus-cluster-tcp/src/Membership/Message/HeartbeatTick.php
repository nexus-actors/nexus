<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership\Message;

use Monadial\Nexus\Core\Actor\UntracedMessage;

/**
 * @psalm-api
 *
 * Self-scheduled at the heartbeat cadence. Drives one failure-detection round
 * via MembershipService::applyTick (phi evaluation + give-up window). The pure
 * C1.6c tick couples failure detection and gossip fan-out; the actor interprets
 * whichever effects the transition returns.
 */
final readonly class HeartbeatTick implements UntracedMessage {}
