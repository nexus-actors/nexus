<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Protocol;

/** @psalm-api */
final readonly class WorkerAskReply
{
    public function __construct(public string $requestId, public object $payload) {}
}
