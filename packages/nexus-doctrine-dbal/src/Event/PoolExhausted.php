<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Event;

use Monadial\Nexus\Doctrine\Dbal\Pool\PoolStats;

/** @psalm-api */
final readonly class PoolExhausted
{
    public function __construct(public string $poolName, public PoolStats $stats) {}
}
