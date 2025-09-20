<?php
declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Step;

use DateTimeImmutable;
use Monadial\Nexus\Core\Duration;
use Override;
use Psr\Clock\ClockInterface;

/** @psalm-api */
final class VirtualClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $start = null)
    {
        $this->now = $start ?? new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(Duration $duration): void
    {
        $microseconds = (int) ($duration->toNanos() / 1000);
        $modified = $this->now->modify("+{$microseconds} microseconds");

        if ($modified !== false) {
            $this->now = $modified;
        }
    }

    public function set(DateTimeImmutable $time): void
    {
        $this->now = $time;
    }
}
