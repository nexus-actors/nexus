<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Metrics;

use Monadial\Nexus\Ddd\Bus\Metrics\MetricsTimingStamp;
use Monadial\Nexus\Ddd\Messaging\Envelope\Stamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricsTimingStamp::class)]
final class MetricsTimingStampTest extends TestCase
{
    #[Test]
    public function constructsWithStartMicros(): void
    {
        $stamp = new MetricsTimingStamp(1234.5678);

        self::assertSame(1234.5678, $stamp->startMicros);
    }

    #[Test]
    public function implementsStamp(): void
    {
        self::assertInstanceOf(Stamp::class, new MetricsTimingStamp(0.0));
    }
}
