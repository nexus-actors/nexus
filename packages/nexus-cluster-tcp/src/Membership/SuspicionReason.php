<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

/**
 * @psalm-api
 *
 * Why a member was moved to Suspect status.
 *
 *   - Connection — the peer's link closed unexpectedly (peer-initiated disconnect),
 *                  not an intentional local close/Leave.
 *   - Gossip     — status propagated from a remote node's view via gossip or
 *                  handshake; the local detectors have not yet confirmed the
 *                  determination independently.
 *   - Phi        — phi-accrual failure detector crossed the configured threshold
 *                  (heartbeats stopped arriving on schedule).
 */
enum SuspicionReason
{
    case Connection;
    case Gossip;
    case Phi;
}
