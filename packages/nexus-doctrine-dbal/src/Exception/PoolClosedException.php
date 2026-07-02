<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/** @psalm-api */
final class PoolClosedException extends NexusException
{
    public function __construct(string $poolName)
    {
        parent::__construct(sprintf('Connection pool "%s" is closed', $poolName));
    }
}
