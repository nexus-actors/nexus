<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership;

use Monadial\Nexus\Cluster\Tcp\Membership\ShuffledCycleSelector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_fill_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function ceil;
use function count;
use function in_array;
use function range;
use function sprintf;

#[CoversClass(ShuffledCycleSelector::class)]
final class ShuffledCycleSelectorTest extends TestCase
{
    #[Test]
    public function returnsEmptyForNonPositiveCount(): void
    {
        self::assertSame([], new ShuffledCycleSelector()->select(['a', 'b'], 0));
    }

    #[Test]
    public function returnsEmptyForEmptyPool(): void
    {
        self::assertSame([], new ShuffledCycleSelector()->select([], 3));
    }

    #[Test]
    public function returnsAllWhenCountExceedsPool(): void
    {
        self::assertSame(['a', 'b'], new ShuffledCycleSelector()->select(['a', 'b'], 5));
    }

    #[Test]
    public function returnsACopyNotTheInputArrayWhenCountExceedsPool(): void
    {
        $pool = ['a', 'b'];
        $selector = new ShuffledCycleSelector();

        $selected = $selector->select($pool, 5);

        self::assertSame($pool, $selected);
        // Mutating the result must not touch the caller's array.
        $selected[] = 'c';
        self::assertSame(['a', 'b'], $pool);
    }

    #[Test]
    public function returnsRequestedCountOfDistinctPeersFromPool(): void
    {
        $pool = ['a', 'b', 'c', 'd', 'e'];

        $selected = new ShuffledCycleSelector()->select($pool, 3);

        self::assertCount(3, $selected);
        self::assertSame($selected, array_values(array_unique($selected)));

        foreach ($selected as $peer) {
            self::assertTrue(in_array($peer, $pool, true));
        }
    }

    /**
     * The property RandomPeerSelector cannot provide and the reason this class exists:
     * every peer must be selected at least once per pass of ceil(N/count) consecutive
     * selects, so a healthy idle link's heartbeat gap is deterministically bounded.
     */
    #[Test]
    public function everyPeerIsSelectedAtLeastOncePerPass(): void
    {
        $pool = [];

        foreach (range(1, 15) as $i) {
            $pool[] = "peer-{$i}";
        }

        $count = 3;
        $selectsPerPass = (int) ceil(count($pool) / $count);
        $selector = new ShuffledCycleSelector();

        // Repeat over many passes: the bound must hold for every pass, not just the first.
        foreach (range(1, 20) as $pass) {
            $seen = [];

            foreach (range(1, $selectsPerPass) as $_) {
                $seen = array_merge($seen, $selector->select($pool, $count));
            }

            $unique = array_unique($seen);

            foreach ($pool as $peer) {
                self::assertTrue(
                    in_array($peer, $unique, true),
                    sprintf('peer %s missed in pass %d', $peer, $pass),
                );
            }
        }
    }

    #[Test]
    public function neverReturnsDuplicatesWithinOneSelectAcrossThePassBoundary(): void
    {
        // Pool of 5 with count 3: every second select straddles a reshuffle.
        $pool = ['a', 'b', 'c', 'd', 'e'];
        $selector = new ShuffledCycleSelector();

        foreach (range(1, 50) as $_) {
            $selected = $selector->select($pool, 3);

            self::assertCount(3, $selected);
            self::assertSame($selected, array_values(array_unique($selected)));
        }
    }

    #[Test]
    public function departedPeersAreDroppedFromTheCurrentPass(): void
    {
        $pool = ['a', 'b', 'c', 'd', 'e', 'f'];
        $selector = new ShuffledCycleSelector();

        // Start a pass, then shrink the pool: departed peers must never be returned.
        $selector->select($pool, 2);
        $shrunk = ['a', 'b', 'c'];

        foreach (range(1, 20) as $_) {
            foreach ($selector->select($shrunk, 2) as $peer) {
                self::assertTrue(in_array($peer, $shrunk, true));
            }
        }
    }

    #[Test]
    public function newPeersJoinTheRotationWithinOnePass(): void
    {
        $pool = ['a', 'b', 'c', 'd'];
        $selector = new ShuffledCycleSelector();
        $selector->select($pool, 2);

        $grown = [...$pool, 'e'];
        $selectsPerPass = (int) ceil(count($grown) / 2);
        $seen = [];

        // Within two passes after joining, the new peer must have been selected.
        foreach (range(1, 2 * $selectsPerPass) as $_) {
            $seen = array_merge($seen, $selector->select($grown, 2));
        }

        self::assertTrue(in_array('e', $seen, true));
    }

    #[Test]
    public function selectionConvergesToUniformCoverageOverManyPasses(): void
    {
        $pool = ['a', 'b', 'c', 'd', 'e', 'f'];
        $selector = new ShuffledCycleSelector();
        /** @var array<string, int> $hits */
        $hits = array_fill_keys($pool, 0);

        // 30 selects x 2 = 60 picks over a 6-peer pool = exactly 10 full passes,
        // so cycling guarantees exactly 10 hits per peer.
        foreach (range(1, 30) as $_) {
            foreach ($selector->select($pool, 2) as $peer) {
                ++$hits[$peer];
            }
        }

        foreach ($pool as $peer) {
            self::assertSame(10, $hits[$peer], "peer {$peer} not uniformly covered");
        }
    }
}
