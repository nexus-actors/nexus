<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support;

use DateTimeImmutable;
use Monadial\Nexus\Runtime\Duration;
use Psr\Clock\ClockInterface;

final class TestClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $start = null)
    {
        $this->now = $start ?? new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(Duration $duration): void
    {
        $microseconds = (int) ($duration->toNanos() / 1000);
        $this->now = $this->now->modify("+{$microseconds} microseconds");
    }

    public function set(DateTimeImmutable $time): void
    {
        $this->now = $time;
    }
}
