<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool;

/** @psalm-api */
final class Evictor
{
    public function tick(ConnectionPool $pool, ?int $now = null): void
    {
        $pool->evictIdleOlderThan($now ?? hrtime(true));
        $pool->warmToMinIdle();
    }
}
