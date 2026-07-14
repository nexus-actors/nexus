<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership;

use Monadial\Nexus\Cluster\Tcp\Membership\LivenessThrottle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LivenessThrottle::class)]
final class LivenessThrottleTest extends TestCase
{
    private const int MS = 1_000_000; // ns per millisecond

    #[Test]
    public function firstObservationAlwaysPasses(): void
    {
        $throttle = new LivenessThrottle(minIntervalMs: 50);

        self::assertTrue($throttle->shouldObserve('peer-a', 1_000));
    }

    #[Test]
    public function observationsInsideTheWindowAreSuppressed(): void
    {
        $throttle = new LivenessThrottle(minIntervalMs: 50);

        self::assertTrue($throttle->shouldObserve('peer-a', 0));
        self::assertFalse($throttle->shouldObserve('peer-a', 1 * self::MS));
        self::assertFalse($throttle->shouldObserve('peer-a', 25 * self::MS));
        self::assertFalse($throttle->shouldObserve('peer-a', 49 * self::MS));
    }

    #[Test]
    public function observationAfterTheWindowPassesAndStartsANewWindow(): void
    {
        $throttle = new LivenessThrottle(minIntervalMs: 50);

        self::assertTrue($throttle->shouldObserve('peer-a', 0));
        self::assertTrue($throttle->shouldObserve('peer-a', 50 * self::MS));
        self::assertFalse($throttle->shouldObserve('peer-a', 99 * self::MS));
        self::assertTrue($throttle->shouldObserve('peer-a', 100 * self::MS));
    }

    #[Test]
    public function peersAreThrottledIndependently(): void
    {
        $throttle = new LivenessThrottle(minIntervalMs: 50);

        self::assertTrue($throttle->shouldObserve('peer-a', 0));
        self::assertTrue($throttle->shouldObserve('peer-b', 1 * self::MS));
        self::assertFalse($throttle->shouldObserve('peer-a', 2 * self::MS));
        self::assertFalse($throttle->shouldObserve('peer-b', 2 * self::MS));
    }

    #[Test]
    public function forgetResetsThePeerWindow(): void
    {
        $throttle = new LivenessThrottle(minIntervalMs: 50);

        self::assertTrue($throttle->shouldObserve('peer-a', 0));
        $throttle->forget('peer-a');

        // Immediately after forget, the peer passes again — a reconnecting peer's
        // first liveness signal must never be delayed.
        self::assertTrue($throttle->shouldObserve('peer-a', 1 * self::MS));
    }

    #[Test]
    public function suppressedObservationsDoNotExtendTheWindow(): void
    {
        $throttle = new LivenessThrottle(minIntervalMs: 50);

        self::assertTrue($throttle->shouldObserve('peer-a', 0));

        // A storm of suppressed beats must not push the window forward: the next
        // pass is measured from the last ALLOWED observation, not the last attempt.
        for ($ns = 1 * self::MS; $ns < 50 * self::MS; $ns += 10 * self::MS) {
            self::assertFalse($throttle->shouldObserve('peer-a', $ns));
        }

        self::assertTrue($throttle->shouldObserve('peer-a', 50 * self::MS));
    }
}
