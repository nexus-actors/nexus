<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;

/**
 * @psalm-api
 *
 * Test helper — sugar over the verbose `FiniteDuration::fromTimeUnit(N, TimeUnit::Seconds())` form.
 */
final class Durations
{
    public static function seconds(int $n): FiniteDuration
    {
        return FiniteDuration::fromTimeUnit($n, TimeUnit::Seconds());
    }

    public static function millis(int $n): FiniteDuration
    {
        return FiniteDuration::fromTimeUnit($n, TimeUnit::Milliseconds());
    }

    public static function gt(FiniteDuration $a, FiniteDuration $b): bool
    {
        return $a->toNanos() > $b->toNanos();
    }

    public static function gte(FiniteDuration $a, FiniteDuration $b): bool
    {
        return $a->toNanos() >= $b->toNanos();
    }

    public static function lte(FiniteDuration $a, FiniteDuration $b): bool
    {
        return $a->toNanos() <= $b->toNanos();
    }
}
