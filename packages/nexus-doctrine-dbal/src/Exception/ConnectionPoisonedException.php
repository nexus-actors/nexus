<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Exception;

use Monadial\Nexus\Core\Exception\NexusException;
use Throwable;

/** @psalm-api */
final class ConnectionPoisonedException extends NexusException
{
    public function __construct(string $reason, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Connection poisoned: %s', $reason), 0, $previous);
    }
}
