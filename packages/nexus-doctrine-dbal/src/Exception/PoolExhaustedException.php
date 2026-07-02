<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Exception;

use Monadial\Nexus\Core\Exception\NexusException;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolStats;

/** @psalm-api */
final class PoolExhaustedException extends NexusException
{
    private function __construct(public readonly string $poolName, public readonly PoolStats $stats, string $message)
    {
        parent::__construct($message);
    }

    public static function after(string $poolName, PoolStats $stats): self
    {
        return new self(
            $poolName,
            $stats,
            sprintf(
                'Connection pool "%s" exhausted: %d in-use of %d (waiting=%d)',
                $poolName,
                $stats->inUse,
                $stats->total,
                $stats->waitingCoroutines,
            ),
        );
    }
}
