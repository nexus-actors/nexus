<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use DateTimeImmutable;
use InvalidArgumentException;

use function array_shift;
use function array_sum;
use function count;
use function exp;
use function log10;
use function max;
use function sqrt;

use const M_PI;

/**
 * @psalm-api
 *
 * Hayashibara phi-accrual failure detector. Tracks a per-peer sliding window of
 * heartbeat inter-arrival intervals (in milliseconds) and, on demand, reports a
 * continuous suspicion level `phi = -log10(P(elapsed))` where `P` is the tail
 * probability of the elapsed time under a normal distribution fitted to the
 * window. A higher phi means the peer is more likely dead; callers compare it to
 * a threshold (default 8.0).
 *
 * The normal tail is computed directly (Abramowitz & Stegun 26.2.17) rather than
 * as `1 - CDF`, avoiding catastrophic cancellation deep in the tail so phi keeps
 * climbing past the threshold instead of saturating.
 *
 * A standard-deviation floor (`minStdDev`, default 500 ms) keeps phi bounded when
 * arrivals are near-perfectly periodic (otherwise stddev → 0 and phi explodes on
 * the first late beat).
 */
final class PhiAccrualDetector
{
    /**
     * Minimum interval (ms) recorded into the window. Heartbeats arriving closer
     * together than this are treated as a single observation: their near-zero gap
     * is not a real heartbeat cadence and would poison the window's mean/stddev,
     * spuriously inflating phi. This happens legitimately at boot when two nodes
     * mutually seed each other — each side then holds two connections to the peer,
     * so a Handshake (and every frame) arrives twice within microseconds. The
     * duplicate still refreshes liveness (`lastArrivalMs`) but does not record a
     * degenerate interval. Well below any realistic heartbeat interval.
     */
    public const float MIN_SAMPLE_INTERVAL_MS = 50.0;

    /** @var array<string, list<float>> */
    private array $windows = [];

    /** @var array<string, float> */
    private array $lastArrivalMs = [];

    public function __construct(private readonly int $maxWindowSize = 200, private readonly float $minStdDev = 500.0)
    {
        if ($maxWindowSize < 1) {
            throw new InvalidArgumentException('PhiAccrualDetector maxWindowSize must be at least 1.');
        }

        if ($minStdDev <= 0.0) {
            throw new InvalidArgumentException('PhiAccrualDetector minStdDev must be positive.');
        }
    }

    /**
     * Drop all failure-detection state for a peer that has left the cluster (Down/Leave).
     * Without this the stale window lingers: on rejoin the first heartbeat records one enormous
     * inter-arrival sample (the whole downtime), inflating mean/stddev and desensitising phi for
     * up to a full window of beats — and per-peer state would grow unbounded under name churn.
     */
    public function forget(string $peer): void
    {
        unset($this->windows[$peer], $this->lastArrivalMs[$peer]);
    }

    public function heartbeat(string $peer, DateTimeImmutable $now): void
    {
        $nowMs = self::toMillis($now);

        if (isset($this->lastArrivalMs[$peer])) {
            $interval = $nowMs - $this->lastArrivalMs[$peer];

            if ($interval >= self::MIN_SAMPLE_INTERVAL_MS) {
                $this->windows[$peer][] = $interval;

                if (count($this->windows[$peer]) > $this->maxWindowSize) {
                    array_shift($this->windows[$peer]);
                }
            }
        }

        $this->lastArrivalMs[$peer] = $nowMs;
    }

    /**
     * Suspicion level for `$peer` at `$now`. Returns 0.0 for an unknown peer or
     * before at least one interval has been observed.
     *
     * @psalm-suppress InvalidOperand int/float mixing in the statistical math is intentional.
     */
    public function phi(string $peer, DateTimeImmutable $now): float
    {
        if (!isset($this->lastArrivalMs[$peer])) {
            return 0.0;
        }

        $window = $this->windows[$peer] ?? [];
        $sampleCount = count($window);

        if ($sampleCount === 0) {
            return 0.0;
        }

        $mean = array_sum($window) / $sampleCount;

        $variance = 0.0;

        foreach ($window as $interval) {
            $delta = $interval - $mean;
            $variance += $delta * $delta;
        }

        $stdDev = max(sqrt($variance / $sampleCount), $this->minStdDev);

        $elapsed = self::toMillis($now) - $this->lastArrivalMs[$peer];
        $z = ($elapsed - $mean) / $stdDev;

        return -log10(max(self::tailProbability($z), 1e-300));
    }

    /**
     * Milliseconds since the last heartbeat from `$peer`, or null if none has ever been recorded.
     * Unlike {@see self::phi()} this is defined even before any inter-arrival interval exists — so a
     * peer that handshakes once and then goes permanently silent (empty phi window ⇒ phi stays 0.0)
     * is still detectable via an absolute-silence threshold.
     */
    public function millisSinceLastHeartbeat(string $peer, DateTimeImmutable $now): ?float
    {
        if (!isset($this->lastArrivalMs[$peer])) {
            return null;
        }

        return self::toMillis($now) - $this->lastArrivalMs[$peer];
    }

    private static function toMillis(DateTimeImmutable $time): float
    {
        return (float) $time->format('U.u') * 1000.0;
    }

    /**
     * P(Z > z) for the standard normal, computed directly to stay accurate and
     * strictly positive in the far tail.
     *
     * @psalm-suppress InvalidOperand int/float mixing in the statistical math is intentional.
     */
    private static function tailProbability(float $z): float
    {
        if ($z < 0.0) {
            return 1.0 - self::tailProbability(-$z);
        }

        $t = 1.0 / (1.0 + 0.2316419 * $z);
        $poly = $t * (0.319381530
            + $t * (-0.356563782
            + $t * (1.781477937
            + $t * (-1.821255978
            + $t * 1.330274429))));
        $pdf = exp(-0.5 * $z * $z) / sqrt(2.0 * M_PI);

        return $pdf * $poly;
    }
}
