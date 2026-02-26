<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Protocol;

/**
 * @psalm-api
 *
 * Internal cluster control message signalling remote ask cancellation.
 */
final readonly class RemoteAskCancelled
{
    public function __construct(public string $requestId) {}
}
