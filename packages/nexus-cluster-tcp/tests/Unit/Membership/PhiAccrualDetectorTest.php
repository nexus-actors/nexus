<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership;

use DateTimeImmutable;
use InvalidArgumentException;
use Monadial\Nexus\Cluster\Tcp\Membership\PhiAccrualDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhiAccrualDetector::class)]
final class PhiAccrualDetectorTest extends TestCase
{
    private DateTimeImmutable $t0;

    #[Test]
    public function unknownPeerHasZeroPhi(): void
    {
        self::assertSame(0.0, new PhiAccrualDetector()->phi('peer', $this->t0));
    }

    #[Test]
    public function singleHeartbeatHasZeroPhi(): void
    {
        $detector = new PhiAccrualDetector();
        $detector->heartbeat('peer', $this->t0);

        self::assertSame(0.0, $detector->phi('peer', $this->at(500)));
    }

    /**
     * Regression: two nodes that mutually seed each other each hold two connections
     * to the peer at boot, so a Handshake (and every frame) arrives twice within
     * ~1 ms. Recording that near-zero gap as a real interval would poison the window
     * — with a short (fast-detection) minStdDev the first heartbeat tick then reports
     * a high phi and the alive peer is falsely suspected. The duplicate must be
     * coalesced.
     */
    #[Test]
    public function burstHeartbeatsDoNotPoisonPhi(): void
    {
        $detector = new PhiAccrualDetector(minStdDev: 200.0);
        $detector->heartbeat('peer', $this->at(0));
        $detector->heartbeat('peer', $this->at(1)); // duplicate from the second connection

        // At the first heartbeat tick the peer is alive; phi must not indicate failure.
        self::assertSame(0.0, $detector->phi('peer', $this->at(1000)));
    }

    /**
     * The coalescing must not weaken real detection: after a boot burst followed by
     * steady heartbeats, a genuinely silent peer still crosses the high threshold.
     */
    #[Test]
    public function realDetectionSurvivesABootBurst(): void
    {
        $detector = new PhiAccrualDetector(minStdDev: 200.0);
        $detector->heartbeat('peer', $this->at(0));
        $detector->heartbeat('peer', $this->at(1)); // coalesced boot duplicate

        foreach ([1000, 2000, 3000, 4000, 5000] as $ms) {
            $detector->heartbeat('peer', $this->at($ms));
        }

        self::assertLessThan(1.0, $detector->phi('peer', $this->at(5300)));
        self::assertGreaterThan(8.0, $detector->phi('peer', $this->at(9000)));
    }

    #[Test]
    public function steadyArrivalsStayBelowThresholdShortlyAfterLastBeat(): void
    {
        $detector = $this->steadyDetector();

        // 1.5s after the last (5000ms) beat: healthy, phi well below 1.
        self::assertLessThan(1.0, $detector->phi('peer', $this->at(6500)));
    }

    #[Test]
    public function steadyArrivalsCrossHighThresholdAfterSeveralMissedBeats(): void
    {
        $detector = $this->steadyDetector();

        // 4s after the last (5000ms) beat: peer is almost certainly dead, phi > 8.
        self::assertGreaterThan(8.0, $detector->phi('peer', $this->at(9000)));
    }

    #[Test]
    public function jitteryArrivalsToleratePauseBetterThanSteadyArrivals(): void
    {
        $steady = $this->steadyDetector();

        $jittery = new PhiAccrualDetector();

        foreach ([0, 100, 1900, 2000, 3900, 5000] as $ms) {
            $jittery->heartbeat('peer', $this->at($ms));
        }

        // Same 1.5s pause after the same last-beat time: wider variance ⇒ lower suspicion.
        self::assertLessThan(
            $steady->phi('peer', $this->at(6500)),
            $jittery->phi('peer', $this->at(6500)),
        );
    }

    #[Test]
    public function slidingWindowForgetsOldIntervals(): void
    {
        $small = new PhiAccrualDetector(maxWindowSize: 2);
        $large = new PhiAccrualDetector(maxWindowSize: 200);

        // Three slow beats (interval 3000) then two fast beats (interval 200).
        foreach ([0, 3000, 6000, 6200, 6400] as $ms) {
            $small->heartbeat('peer', $this->at($ms));
            $large->heartbeat('peer', $this->at($ms));
        }

        // The small window remembers only the two fast intervals, so a 1s gap
        // looks far more suspicious than it does to the large-window detector.
        self::assertGreaterThan(
            $large->phi('peer', $this->at(7400)),
            $small->phi('peer', $this->at(7400)),
        );
    }

    #[Test]
    public function rejectsANonPositiveWindowSize(): void
    {
        // Boundary: a window of exactly 1 is valid; 0 is not.
        new PhiAccrualDetector(maxWindowSize: 1);

        $this->expectException(InvalidArgumentException::class);
        new PhiAccrualDetector(maxWindowSize: 0);
    }

    #[Test]
    public function rejectsANonPositiveStdDevFloor(): void
    {
        new PhiAccrualDetector(minStdDev: 0.01);

        $this->expectException(InvalidArgumentException::class);
        new PhiAccrualDetector(minStdDev: 0.0);
    }

    #[Test]
    public function recordsAnIntervalExactlyAtTheSampleFloor(): void
    {
        $detector = new PhiAccrualDetector();
        $detector->heartbeat('peer', $this->at(0));
        // Exactly MIN_SAMPLE_INTERVAL_MS (50) apart — inclusive, so it IS recorded.
        $detector->heartbeat('peer', $this->at(50));

        // With that single interval on record, a long silence yields high suspicion;
        // if the boundary were exclusive the window would be empty and phi would be 0.
        self::assertGreaterThan(1.0, $detector->phi('peer', $this->at(5050)));
    }

    /**
     * Golden vector for the Hayashibara math. Window of intervals
     * [400, 1600, 400, 1600] ms → mean 1000, stddev 600 (above the 500 floor, so the
     * computed deviation is what drives phi). Sampled 2200 ms after the last beat puts
     * the elapsed time exactly z = (2200 − 1000) / 600 = 2 standard deviations out, where
     * the standard-normal tail P(Z > 2) ≈ 0.02275 gives phi = −log10(0.02275) ≈ 1.643.
     */
    #[Test]
    public function phiMatchesTheNormalTailForAKnownWindow(): void
    {
        $detector = new PhiAccrualDetector();

        foreach ([0, 400, 2000, 2400, 4000] as $ms) {
            $detector->heartbeat('peer', $this->at($ms));
        }

        self::assertEqualsWithDelta(1.643, $detector->phi('peer', $this->at(6200)), 0.03);
    }

    /**
     * The floor keeps phi finite when arrivals are perfectly periodic (computed stddev
     * would be 0). Steady 1000 ms intervals → floor 500 is used; 2000 ms after the last
     * beat is z = 2, so phi ≈ 1.643 again rather than exploding.
     */
    #[Test]
    public function phiUsesTheStdDevFloorForPeriodicArrivals(): void
    {
        self::assertEqualsWithDelta(1.643, $this->steadyDetector()->phi('peer', $this->at(7000)), 0.03);
    }

    /**
     * An early arrival (elapsed below the mean) is z < 0; the tail is computed by
     * reflection (1 − P(Z > |z|)). For the [400,1600,…] window (mean 1000, stddev 600),
     * sampling 400 ms after the last beat is z = −1, tail ≈ 0.841, phi ≈ 0.075.
     */
    #[Test]
    public function phiReflectsTheNegativeTailForEarlyArrivals(): void
    {
        $detector = new PhiAccrualDetector();

        foreach ([0, 400, 2000, 2400, 4000] as $ms) {
            $detector->heartbeat('peer', $this->at($ms));
        }

        self::assertEqualsWithDelta(0.075, $detector->phi('peer', $this->at(4400)), 0.02);
    }

    #[Test]
    public function millisSinceLastHeartbeatIsNullBeforeAnyHeartbeat(): void
    {
        self::assertNull(new PhiAccrualDetector()->millisSinceLastHeartbeat('peer', $this->t0));
    }

    #[Test]
    public function millisSinceLastHeartbeatTracksElapsedForASilentPeer(): void
    {
        // The #6 gap: a peer that handshakes once then goes silent has an empty phi window, so
        // phi stays 0 forever — but the absolute silence is measurable and grows.
        $detector = new PhiAccrualDetector();
        $detector->heartbeat('peer', $this->at(0));

        self::assertSame(0.0, $detector->phi('peer', $this->at(30_000)), 'phi cannot see the silence');
        self::assertEqualsWithDelta(30_000.0, $detector->millisSinceLastHeartbeat('peer', $this->at(30_000)), 1.0);
    }

    protected function setUp(): void
    {
        $this->t0 = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }

    private function steadyDetector(): PhiAccrualDetector
    {
        $detector = new PhiAccrualDetector();

        foreach ([0, 1000, 2000, 3000, 4000, 5000] as $ms) {
            $detector->heartbeat('peer', $this->at($ms));
        }

        return $detector;
    }

    private function at(int $millis): DateTimeImmutable
    {
        return $this->t0->modify("+{$millis} milliseconds");
    }
}
