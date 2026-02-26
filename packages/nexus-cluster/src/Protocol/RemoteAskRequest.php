<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Protocol;

use Monadial\Nexus\Core\Actor\ActorPath;

/**
 * @psalm-api
 *
 * Internal cluster control message for cross-worker ask requests.
 */
final readonly class RemoteAskRequest
{
    public function __construct(
        public string $requestId,
        public string $correlationId,
        public string $causationId,
        public ActorPath $targetPath,
        public int $replyToWorker,
        public ActorPath $replyToPath,
        public object $payload,
    ) {}
}
