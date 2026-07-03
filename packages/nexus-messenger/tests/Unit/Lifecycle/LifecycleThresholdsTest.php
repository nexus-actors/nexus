<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Lifecycle;

use Monadial\Nexus\Messenger\Lifecycle\LifecycleThresholds;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LifecycleThresholds::class)]
final class LifecycleThresholdsTest extends TestCase
{
    #[Test]
    public function noneNeverBreaches(): void
    {
        $thresholds = LifecycleThresholds::none();

        self::assertNull($thresholds->breachReason(PHP_INT_MAX, Duration::seconds(86400), PHP_INT_MAX));
    }

    #[Test]
    public function memoryLimitBreachesAtOrAboveBudget(): void
    {
        $thresholds = LifecycleThresholds::none()->withMemoryLimit(1024);

        self::assertNull($thresholds->breachReason(1023, Duration::zero(), 0));
        self::assertNotNull($thresholds->breachReason(1024, Duration::zero(), 0));
        self::assertNotNull($thresholds->breachReason(4096, Duration::zero(), 0));
    }

    #[Test]
    public function messageLimitBreachesAtOrAboveCount(): void
    {
        $thresholds = LifecycleThresholds::none()->withMessageLimit(100);

        self::assertNull($thresholds->breachReason(0, Duration::zero(), 99));
        self::assertNotNull($thresholds->breachReason(0, Duration::zero(), 100));
    }

    #[Test]
    public function timeLimitBreachesAtOrAboveUptime(): void
    {
        $thresholds = LifecycleThresholds::none()->withTimeLimit(Duration::seconds(60));

        self::assertNull($thresholds->breachReason(0, Duration::seconds(59), 0));
        self::assertNotNull($thresholds->breachReason(0, Duration::seconds(60), 0));
    }

    #[Test]
    public function breachReasonNamesTheThreshold(): void
    {
        $memory = LifecycleThresholds::none()->withMemoryLimit(1)->breachReason(2, Duration::zero(), 0);
        $count = LifecycleThresholds::none()->withMessageLimit(1)->breachReason(0, Duration::zero(), 1);
        $time = LifecycleThresholds::none()->withTimeLimit(Duration::seconds(1))->breachReason(
            0,
            Duration::seconds(2),
            0,
        );

        self::assertStringContainsString('memory', (string) $memory);
        self::assertStringContainsString('message', (string) $count);
        self::assertStringContainsString('uptime', (string) $time);
    }
}
