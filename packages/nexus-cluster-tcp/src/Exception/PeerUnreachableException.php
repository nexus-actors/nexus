<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Exception;

use Monadial\Nexus\Runtime\Exception\FutureException;
use RuntimeException;

/**
 * @psalm-api
 *
 * Failed into a pending remote ask when the target node's link closes before a reply
 * arrives. The ask can never be answered over the dead connection, so it fails fast
 * with this exception instead of parking the calling coroutine until its own timeout
 * — which also stops a single dead peer from filling the ask registry to capacity and
 * rejecting asks to healthy peers.
 *
 * Implements {@see FutureException} so it can be delivered through a `FutureSlot::fail()`.
 */
final class PeerUnreachableException extends RuntimeException implements FutureException
{
    public function __construct(string $node)
    {
        parent::__construct("Remote ask target node '{$node}' became unreachable before a reply arrived.");
    }
}
