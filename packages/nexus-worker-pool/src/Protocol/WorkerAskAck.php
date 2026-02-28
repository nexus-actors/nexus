<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Protocol;

/** @psalm-api */
final readonly class WorkerAskAck
{
    public function __construct(public string $requestId) {}
}
