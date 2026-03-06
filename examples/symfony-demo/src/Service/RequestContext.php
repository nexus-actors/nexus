<?php

declare(strict_types=1);

namespace App\Service;

use Monadial\Nexus\Symfony\Attribute\CoroutineScoped;
use Symfony\Component\Uid\Ulid;

#[CoroutineScoped]
final class RequestContext
{
    public readonly string $requestId;
    public readonly float $startedAt;

    public function __construct()
    {
        $this->requestId = (string) new Ulid();
        $this->startedAt = microtime(true);
    }

    /**
     * @psalm-suppress InvalidOperand
     */
    public function elapsedMs(): float
    {
        return (microtime(true) - $this->startedAt) * 1000;
    }
}
