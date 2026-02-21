<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\SwooleThread;

use InvalidArgumentException;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class ThreadClusterConfig
{
    private function __construct(public int $workerCount, public int $directorySize) {}

    public static function withWorkers(int $workerCount, int $directorySize = 65536): self
    {
        if ($workerCount < 1) {
            throw new InvalidArgumentException('Worker count must be at least 1');
        }

        return new self($workerCount, $directorySize);
    }
}
