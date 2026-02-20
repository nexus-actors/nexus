<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Deterministic consistent hash ring for mapping actor names to worker IDs.
 * Uses crc32 with virtual nodes for even distribution.
 * Same output on all workers — no coordination needed.
 */
final readonly class ConsistentHashRing
{
    private const int VIRTUAL_NODES = 150;

    /** @var list<int> Sorted hash values */
    private array $hashes;

    /** @var array<int, int> Hash → worker ID */
    private array $mapping;

    public function __construct(int $workerCount, int $virtualNodes = self::VIRTUAL_NODES)
    {
        $hashes = [];
        $mapping = [];

        for ($worker = 0; $worker < $workerCount; $worker++) {
            for ($vnode = 0; $vnode < $virtualNodes; $vnode++) {
                $hash = crc32("w{$worker}v{$vnode}");
                $hashes[] = $hash;
                $mapping[$hash] = $worker;
            }
        }

        sort($hashes);
        $this->hashes = $hashes;
        $this->mapping = $mapping;
    }

    /**
     * Returns the worker ID that owns the given actor name.
     */
    public function getWorker(string $name): int
    {
        $hash = crc32($name);
        $count = count($this->hashes);

        // Binary search for first hash >= $hash
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

        // Wrap around if hash is greater than all ring entries
        if ($this->hashes[$lo] < $hash) {
            $lo = 0;
        }

        return $this->mapping[$this->hashes[$lo]];
    }
}
