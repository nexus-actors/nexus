<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 *
 * Thrown when a new WebSocket channel actor would exceed the configured
 * cardinality cap. Bounds actor/ref/mailbox/memory growth from an attacker
 * churning unique channel keys; the dispatcher translates it into a
 * connection close (1013 "try again later") rather than spawning without limit.
 */
final class ChannelCapacityExceededException extends NexusException
{
    public function __construct(int $cap)
    {
        parent::__construct("WebSocket channel cardinality cap ({$cap}) reached; refusing to spawn a new channel.");
    }
}
