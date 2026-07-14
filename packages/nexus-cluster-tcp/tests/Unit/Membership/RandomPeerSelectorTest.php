<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership;

use Monadial\Nexus\Cluster\Tcp\Membership\RandomPeerSelector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_unique;
use function array_values;
use function in_array;

#[CoversClass(RandomPeerSelector::class)]
final class RandomPeerSelectorTest extends TestCase
{
    #[Test]
    public function returnsEmptyForNonPositiveCount(): void
    {
        self::assertSame([], new RandomPeerSelector()->select(['a', 'b'], 0));
    }

    #[Test]
    public function returnsEmptyForEmptyPool(): void
    {
        self::assertSame([], new RandomPeerSelector()->select([], 3));
    }

    #[Test]
    public function returnsAllWhenCountExceedsPool(): void
    {
        self::assertSame(['a', 'b'], new RandomPeerSelector()->select(['a', 'b'], 5));
    }

    #[Test]
    public function returnsACopyNotTheInputArrayWhenCountExceedsPool(): void
    {
        $pool = ['a', 'b'];

        $selected = new RandomPeerSelector()->select($pool, 5);

        self::assertSame($pool, $selected);
        // Mutating the result must not touch the caller's array.
        $selected[] = 'c';
        self::assertSame(['a', 'b'], $pool);
    }

    #[Test]
    public function returnsRequestedCountOfDistinctPeersFromPool(): void
    {
        $pool = ['a', 'b', 'c', 'd', 'e'];

        $selected = new RandomPeerSelector()->select($pool, 3);

        self::assertCount(3, $selected);
        self::assertSame($selected, array_values(array_unique($selected)));

        foreach ($selected as $peer) {
            self::assertTrue(in_array($peer, $pool, true));
        }
    }
}
