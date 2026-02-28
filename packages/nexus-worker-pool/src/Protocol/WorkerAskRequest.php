<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Protocol;

use Monadial\Nexus\Core\Actor\ActorPath;

/** @psalm-api */
final readonly class WorkerAskRequest
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
