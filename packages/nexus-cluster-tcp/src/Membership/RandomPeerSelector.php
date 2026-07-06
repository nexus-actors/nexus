<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Override;

use function array_rand;
use function count;

/**
 * @psalm-api
 *
 * Uniform-random gossip peer selection backed by array_rand.
 */
final class RandomPeerSelector implements PeerSelector
{
    /**
     * @param list<string> $peers
     *
     * @return list<string>
     */
    #[Override]
    public function select(array $peers, int $count): array
    {
        $total = count($peers);

        if ($count <= 0 || $peers === []) {
            return [];
        }

        if ($count >= $total) {
            return $peers;
        }

        /** @var array<int, int>|int $keys */
        $keys = array_rand($peers, $count);

        $selected = [];

        foreach ((array) $keys as $key) {
            $selected[] = $peers[$key];
        }

        return $selected;
    }
}
