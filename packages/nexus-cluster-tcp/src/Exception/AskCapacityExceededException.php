<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Exception;

use RuntimeException;

/**
 * @psalm-api
 *
 * Thrown when the {@see \Monadial\Nexus\Cluster\Tcp\Messaging\TcpAskRegistry} is at its
 * pending-ask capacity and cannot register another outstanding ask.
 */
final class AskCapacityExceededException extends RuntimeException
{
    public function __construct(int $capacity, int $pending)
    {
        parent::__construct("TCP ask registry at capacity: {$pending} pending asks, maximum {$capacity}.");
    }
}
