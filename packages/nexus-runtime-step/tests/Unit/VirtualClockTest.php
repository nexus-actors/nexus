<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Step\Tests\Unit;

use DateTimeImmutable;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Step\VirtualClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VirtualClockTest extends TestCase
{
    #[Test]
    public function defaults_to_fixed_start_time(): void
    {
        $clock = new VirtualClock();

        self::assertSame('2026-01-01T00:00:00+00:00', $clock->now()->format('c'));
    }

    #[Test]
    public function accepts_custom_start_time(): void
    {
        $start = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $clock = new VirtualClock($start);

        self::assertSame('2025-06-15T12:00:00+00:00', $clock->now()->format('c'));
    }

    #[Test]
    public function advance_moves_time_forward(): void
    {
        $clock = new VirtualClock();
        $before = $clock->now();

        $clock->advance(Duration::seconds(5));

        self::assertGreaterThan($before, $clock->now());
        self::assertSame(5, $clock->now()->getTimestamp() - $before->getTimestamp());
    }

    #[Test]
    public function advance_accumulates(): void
    {
        $clock = new VirtualClock();
        $start = $clock->now();

        $clock->advance(Duration::seconds(3));
        $clock->advance(Duration::seconds(7));

        self::assertSame(10, $clock->now()->getTimestamp() - $start->getTimestamp());
    }

    #[Test]
    public function set_replaces_time(): void
    {
        $clock = new VirtualClock();
        $target = new DateTimeImmutable('2030-12-31T23:59:59+00:00');

        $clock->set($target);

        self::assertSame('2030-12-31T23:59:59+00:00', $clock->now()->format('c'));
    }

    #[Test]
    public function now_is_deterministic(): void
    {
        $clock = new VirtualClock();

        $a = $clock->now();
        $b = $clock->now();

        self::assertEquals($a, $b);
    }
}
