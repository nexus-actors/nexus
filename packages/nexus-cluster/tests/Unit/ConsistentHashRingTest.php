<?php
declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit;

use Monadial\Nexus\Cluster\ConsistentHashRing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsistentHashRing::class)]
final class ConsistentHashRingTest extends TestCase
{
    #[Test]
    public function sameNameAlwaysReturnsSameWorker(): void
    {
        $ring = new ConsistentHashRing(8);

        $first = $ring->getWorker('orders');
        $second = $ring->getWorker('orders');
        $third = $ring->getWorker('orders');

        self::assertSame($first, $second);
        self::assertSame($second, $third);
    }

    #[Test]
    public function returnedWorkerIsWithinRange(): void
    {
        $ring = new ConsistentHashRing(4);

        for ($i = 0; $i < 100; $i++) {
            $worker = $ring->getWorker("actor-{$i}");
            self::assertGreaterThanOrEqual(0, $worker);
            self::assertLessThan(4, $worker);
        }
    }

    #[Test]
    public function allWorkersAreReachable(): void
    {
        $ring = new ConsistentHashRing(4);
        $seen = [];

        for ($i = 0; $i < 1000; $i++) {
            $seen[$ring->getWorker("test-actor-{$i}")] = true;
        }

        self::assertCount(4, $seen, 'All 4 workers should be reachable');
    }

    #[Test]
    public function singleWorkerAlwaysReturnsZero(): void
    {
        $ring = new ConsistentHashRing(1);

        self::assertSame(0, $ring->getWorker('anything'));
        self::assertSame(0, $ring->getWorker('something-else'));
    }

    #[Test]
    public function twoRingsWithSameConfigProduceSameResults(): void
    {
        $ring1 = new ConsistentHashRing(8);
        $ring2 = new ConsistentHashRing(8);

        for ($i = 0; $i < 100; $i++) {
            $name = "actor-{$i}";
            self::assertSame(
                $ring1->getWorker($name),
                $ring2->getWorker($name),
                "Rings should agree on placement for {$name}",
            );
        }
    }

    #[Test]
    public function distributionIsReasonablyEven(): void
    {
        $ring = new ConsistentHashRing(4);
        $counts = array_fill(0, 4, 0);

        for ($i = 0; $i < 10000; $i++) {
            $counts[$ring->getWorker("actor-{$i}")]++;
        }

        // Each worker should get at least 15% of actors (ideal is 25%)
        foreach ($counts as $count) {
            self::assertGreaterThan(1500, $count, 'Distribution too skewed: ' . implode(', ', $counts));
        }
    }
}
