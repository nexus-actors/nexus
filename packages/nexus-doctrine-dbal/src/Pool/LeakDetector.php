<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool;

/** @psalm-api */
final class LeakDetector
{
    public function tick(ConnectionPool $pool, ?int $now = null): void
    {
        $pool->warnOnLeaks($now ?? hrtime(true));
    }
}
