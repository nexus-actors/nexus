<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Connection\Message;

/**
 * @psalm-api
 *
 * Ask query: request a {@see \Monadial\Nexus\Cluster\Tcp\Connection\ConnectionReport} snapshot of
 * {@see \Monadial\Nexus\Cluster\Tcp\Connection\ConnectionSupervisor}'s current routing state.
 * Introspection seam for tests — replaces reflecting into actor state directly, which is not
 * possible once the state lives inside a `BehaviorWithState` closure rather than a plain object.
 */
final readonly class LinkReport
{
}
