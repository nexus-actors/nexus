<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

/**
 * @psalm-api
 *
 * Why a member was moved to Suspect status.
 *
 *   - Phi        — phi-accrual failure detector crossed the configured threshold
 *                  (heartbeats stopped arriving on schedule).
 *   - Connection — the peer's link closed unexpectedly (peer-initiated disconnect),
 *                  not an intentional local close/Leave.
 */
enum SuspicionReason
{
    case Connection;
    case Phi;
}
