<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Protocol;

/**
 * @psalm-api
 *
 * Internal cluster control message for ask replies.
 */
final readonly class RemoteAskReply
{
    public function __construct(public string $requestId, public object $payload) {}
}
