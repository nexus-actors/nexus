<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Tests\Unit\Support;

final readonly class Ping
{
    public function __construct(public string $payload = 'ping') {}
}
