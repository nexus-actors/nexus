<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Protocol;

/**
 * @psalm-api
 *
 * Internal cluster control message to cancel an in-flight remote ask.
 */
final readonly class RemoteAskCancel
{
    public function __construct(public string $requestId) {}
}
