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
 *   - Silence    — absolute silence: nothing was heard from the peer for the whole
 *                  no-heartbeat window, so the local detector gives up on it directly
 *                  (distinct from Gossip, which is a peer's opinion, and from Phi,
 *                  which is a statistical threshold crossing).
 */
enum SuspicionReason
{
    case Connection;
    case Gossip;
    case Phi;
    case Silence;
}
