<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Serialization\MessageType;

/**
 * @psalm-api
 *
 * Graceful departure notice. A node sends this before closing its connections
 * so peers can remove it from their view immediately rather than waiting for
 * phi-accrual failure detection. `node` is the NodeAddress path-prefix of the
 * departing node.
 */
#[MessageType('cluster.leave')]
final readonly class LeavePayload
{
    public function __construct(public string $node) {}
}
