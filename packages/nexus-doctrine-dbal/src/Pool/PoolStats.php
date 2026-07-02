<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool;

/**
 * @psalm-api
 */
final readonly class PoolStats
{
    public function __construct(
        public int $idle,
        public int $inUse,
        public int $total,
        public int $waitingCoroutines,
        public int $totalBorrows,
        public int $totalWaits,
        public int $totalTimeouts,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0);
    }
}
