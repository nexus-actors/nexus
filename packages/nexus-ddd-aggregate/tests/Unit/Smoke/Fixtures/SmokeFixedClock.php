<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * @psalm-api
 *
 * Frozen-clock test double for the smoke pipeline. Production wires a
 * real `\DateTimeImmutable`-backed clock; smoke tests pin time so
 * stored-event timestamps are deterministic across reruns.
 */
final readonly class SmokeFixedClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now) {}

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
