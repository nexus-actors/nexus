<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Prometheus;

use Monadial\Nexus\Core\Actor\Telemetry\ActorSnapshot;
use Monadial\Nexus\Core\Actor\Telemetry\ActorSystemSnapshot;
use Monadial\Nexus\Observability\Prometheus\PrometheusCollector;
use Monadial\Nexus\Runtime\Swoole\Telemetry\SwooleRuntimeSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrometheusCollector::class)]
final class PrometheusCollectorTest extends TestCase
{
    #[Test]
    public function render_includes_actor_and_runtime_metrics(): void
    {
        $actor   = new ActorSnapshot('/user/orders', true, 3, 1000, true, []);
        $system  = new ActorSystemSnapshot('nexus-0', 'ULID', true, [$actor], 0);
        $runtime = new SwooleRuntimeSnapshot(
            coroutineNum: 12,
            coroutinePeakNum: 20,
            activeTimers: 4,
            memoryBytes: 8_388_608,
            memoryPeakBytes: 12_582_912,
        );

        $collector = new PrometheusCollector();
        $collector->collect($system, $runtime);
        $output = $collector->render();

        self::assertStringContainsString('nexus_actor_mailbox_depth', $output);
        self::assertStringContainsString('/user/orders', $output);
        self::assertStringContainsString('nexus_coroutine_num', $output);
        self::assertStringContainsString('12', $output);
        self::assertStringContainsString('nexus_memory_bytes', $output);
    }

    #[Test]
    public function render_includes_worker_label_when_provided(): void
    {
        $system  = new ActorSystemSnapshot('nexus-0', 'ULID', true, [], 0);
        $runtime = new SwooleRuntimeSnapshot(
            coroutineNum: 10,
            coroutinePeakNum: 15,
            activeTimers: 2,
            memoryBytes: 1_000_000,
            memoryPeakBytes: 2_000_000,
        );

        $collector = new PrometheusCollector();
        $collector->collect($system, $runtime, '0');
        $output = $collector->render();

        self::assertStringContainsString('worker="0"', $output);
    }
}
