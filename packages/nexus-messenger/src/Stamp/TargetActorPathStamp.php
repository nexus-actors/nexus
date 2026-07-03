<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Routing stamp: the path of the Nexus actor an inbound message is addressed to.
 *
 * Read by StampMessageRouter. Reserved as the seam for a future cluster
 * transport over Messenger; round-tripped as the X-Nexus-Target-Path header.
 *
 * @psalm-api
 */
final readonly class TargetActorPathStamp implements StampInterface
{
    public function __construct(public string $path) {}
}
