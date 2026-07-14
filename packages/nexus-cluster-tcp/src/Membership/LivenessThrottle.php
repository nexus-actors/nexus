<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

/**
 * @psalm-api
 *
 * Coalesces per-frame liveness signals into at most one observation per peer per
 * interval.
 *
 * Every inbound frame proves the peer is alive, but forwarding a
 * {@see Message\PeerLivenessObserved} to the membership actor for EVERY frame is
 * both wasted work and a reliability hazard: under sustained message load the
 * membership mailbox fills with tens of thousands of liveness messages per second,
 * each costing a clock read, a phi-detector update, and a ClusterView rebuild —
 * and gossip/tick processing queues behind them, so the failure detector reads
 * stale arrival times exactly when the cluster is busiest (risking false suspicion
 * under load). The phi detector itself discards inter-arrival samples closer than
 * {@see PhiAccrualDetector::MIN_SAMPLE_INTERVAL_MS} anyway, so beats above that
 * rate carry no detection value at all.
 *
 * The default interval therefore mirrors the detector's sample floor: one
 * observation per peer per 50 ms preserves full failure-detection fidelity while
 * bounding membership-actor traffic to at most 20 messages per peer per second.
 *
 * Pure state machine over caller-supplied monotonic nanoseconds (no clock inside)
 * so it is deterministic to test; {@see \Monadial\Nexus\Cluster\Tcp\ClusterNode}
 * feeds it `hrtime(true)`.
 */
final class LivenessThrottle
{
    private readonly int $minIntervalNs;

    /** @var array<string, int> Monotonic nanos of the last allowed observation, per peer key. */
    private array $lastPassNs = [];

    public function __construct(int $minIntervalMs = (int) PhiAccrualDetector::MIN_SAMPLE_INTERVAL_MS)
    {
        $this->minIntervalNs = $minIntervalMs * 1_000_000;
    }

    /**
     * Whether a liveness observation for `$peer` should be forwarded now. Returns true
     * (and starts a new suppression window) when at least the configured interval has
     * elapsed since the last forwarded observation; false inside the window. The first
     * observation for a peer always passes, so recovery signals are never delayed.
     */
    public function shouldObserve(string $peer, int $nowNs): bool
    {
        $last = $this->lastPassNs[$peer] ?? null;

        if ($last !== null && $nowNs - $last < $this->minIntervalNs) {
            return false;
        }

        $this->lastPassNs[$peer] = $nowNs;

        return true;
    }

    /**
     * Drop the tracked window for `$peer` (e.g. when its link closes), so the map
     * does not grow with departed peers and a reconnecting peer passes immediately.
     */
    public function forget(string $peer): void
    {
        unset($this->lastPassNs[$peer]);
    }
}
