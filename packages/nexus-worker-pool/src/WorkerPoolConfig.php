<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool;

use InvalidArgumentException;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class WorkerPoolConfig
{
    private function __construct(public int $workerCount) {}

    public static function withThreads(int $workerCount): self
    {
        if ($workerCount < 1) {
            throw new InvalidArgumentException('Worker count must be at least 1');
        }

        return new self($workerCount);
    }
}
