<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Psr\Clock\ClockInterface;

/**
 * Test fixture: a `ClockInterface` that always returns the configured
 * instant. Smoke tests use this so root MessageMetadata is deterministic.
 *
 * @psalm-api
 */
final class FixedClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new DateTimeImmutable('2026-05-10T00:00:00', new DateTimeZone('UTC'));
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
