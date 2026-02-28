<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Protocol;

/** @psalm-api */
final readonly class WorkerAskCancel
{
    public function __construct(public string $requestId) {}
}
