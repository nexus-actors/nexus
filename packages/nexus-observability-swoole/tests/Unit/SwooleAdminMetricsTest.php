<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Swoole\Tests\Unit;

use LogicException;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\ContextPropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Observability\Swoole\SwooleAdminMetrics;
use Monadial\Nexus\Observability\Trace\Tracer;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function Swoole\Coroutine\run;

#[CoversClass(SwooleAdminMetrics::class)]
final class SwooleAdminMetricsTest extends TestCase
{
    #[Test]
    public function registersCoroutineGauges(): void
    {
        $names = [];

        run(static function () use (&$names): void {
            $metricExporter = new MetricInMemoryExporter();
            $reader = new ExportingReader($metricExporter);
            $observability = new OtelObservability(
                new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter())),
                MeterProvider::builder()->addReader($reader)->build(),
                new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
            );

            (new SwooleAdminMetrics($observability))->registerCoroutineGauges();

            $reader->collect();
            $names = array_map(static fn ($metric): string => $metric->name, $metricExporter->collect());
        });

        self::assertContains('swoole.coroutine.count', $names);
        self::assertContains('swoole.coroutine.peak', $names);
    }

    #[Test]
    public function disabledObservabilityRegistersNothing(): void
    {
        // The spy's meter() throws — if the isEnabled() guard were removed,
        // the test would fail with LogicException, proving the guard is intact.
        run(static function (): void {
            $spy = new class implements Observability {
                public function isEnabled(): bool
                {
                    return false;
                }

                public function meter(): Meter
                {
                    throw new LogicException('meter() must not be called when disabled');
                }

                public function tracer(): Tracer
                {
                    throw new LogicException('unreachable');
                }

                public function propagator(): ContextPropagator
                {
                    throw new LogicException('unreachable');
                }

                public function currentContext(): Context
                {
                    throw new LogicException('unreachable');
                }

                public function shutdown(): void {}
            };

            (new SwooleAdminMetrics($spy))->registerCoroutineGauges();
        });

        self::assertTrue(true); // reached only if meter() was never invoked
    }
}
