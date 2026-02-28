<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Consistent hash ring mapping actor names to cluster NodeAddresses.
 * Same algorithm as WorkerPool's ConsistentHashRing but maps to nodes, not worker IDs.
 */
final readonly class NodeHashRing
{
    private const int VIRTUAL_NODES = 150;

    /** @var list<int> */
    private array $hashes;

    /** @var array<int, int> hash → node index */
    private array $mapping;

    /** @param list<NodeAddress> $nodes */
    public function __construct(private array $nodes, int $virtualNodes = self::VIRTUAL_NODES)
    {
        $hashes = [];
        $mapping = [];

        $nodeCount = count($nodes);

        for ($index = 0; $index < $nodeCount; $index++) {
            for ($vnode = 0; $vnode < $virtualNodes; $vnode++) {
                $hash = crc32("n{$index}v{$vnode}");
                $hashes[] = $hash;
                $mapping[$hash] = $index;
            }
        }

        sort($hashes);
        $this->hashes = $hashes;
        $this->mapping = $mapping;
    }

    public function getNode(string $name): NodeAddress
    {
        $hash = crc32($name);
        $count = count($this->hashes);
        $lo = 0;
        $hi = $count - 1;

        while ($lo < $hi) {
            $mid = $lo + ($hi - $lo >> 1);

            if ($this->hashes[$mid] < $hash) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        if ($this->hashes[$lo] < $hash) {
            $lo = 0;
        }

        return $this->nodes[$this->mapping[$this->hashes[$lo]]];
    }
}
