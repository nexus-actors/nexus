<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Protocol;

/**
 * @psalm-api
 *
 * Internal cluster control ack for dedup/retry coordination.
 */
final readonly class RemoteAskAck
{
    public function __construct(public string $requestId) {}
}
