<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Metrics;

use Monadial\Nexus\Ddd\Bus\Metrics\MetricsCollector;
use Monadial\Nexus\Ddd\Bus\Metrics\NoOpMetricsCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoOpMetricsCollector::class)]
final class NoOpMetricsCollectorTest extends TestCase
{
    #[Test]
    public function implementsCollectorInterface(): void
    {
        self::assertInstanceOf(MetricsCollector::class, new NoOpMetricsCollector());
    }

    #[Test]
    public function countHistogramAndGaugeAreNoOps(): void
    {
        $collector = new NoOpMetricsCollector();

        $collector->count('any', 1, ['tag' => 'v']);
        $collector->histogram('any', 1.0, ['tag' => 'v']);
        $collector->gauge('any', 1.0, ['tag' => 'v']);

        self::assertTrue(true);
    }
}
