<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership;

use DateTimeImmutable;
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
