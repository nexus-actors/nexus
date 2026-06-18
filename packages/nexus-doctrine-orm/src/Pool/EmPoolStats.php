<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Pool;

/** @psalm-api */
final readonly class EmPoolStats
{
    public function __construct(
        public int $idle,
        public int $inUse,
        public int $total,
        public int $totalBorrows,
        public int $totalEvictions,
        public int $waitingCoroutines = 0,
        public int $totalWaits = 0,
        public int $totalTimeouts = 0,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0);
    }
}
