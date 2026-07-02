<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Actor\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Observability\Actor\ActorSystemMetrics;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
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

#[CoversClass(ActorSystemMetrics::class)]
final class ActorSystemMetricsTest extends TestCase
{
    #[Test]
    public function registersActorSystemGauges(): void
    {
        $metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($metricExporter);
        $observability = new OtelObservability(
            new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter())),
            MeterProvider::builder()->addReader($reader)->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $system = ActorSystem::create('metrics-test', new FiberRuntime());
        $behavior = Behavior::receive(static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same());
        $system->spawn(Props::fromBehavior($behavior), 'a');
        $system->spawn(Props::fromBehavior($behavior), 'b');

        (new ActorSystemMetrics($observability, $system))->register();

        $reader->collect();
        $metrics = $metricExporter->collect();
        $names = array_map(static fn ($metric): string => $metric->name, $metrics);

        self::assertContains('nexus.actor_system.live_actors', $names);
        self::assertContains('nexus.actor_system.dead_letters', $names);
        self::assertContains('nexus.actor_system.running', $names);

        // live_actors should read 2
        $live = null;

        foreach ($metrics as $metric) {
            if ($metric->name === 'nexus.actor_system.live_actors' && $metric->data instanceof Gauge) {
                $live = $metric->data->dataPoints[0]->value ?? null;
            }
        }

        self::assertSame(2, $live);
    }

    #[Test]
    public function disabledObservabilityRegistersNothing(): void
    {
        $system = ActorSystem::create('metrics-disabled', new FiberRuntime());
        (new ActorSystemMetrics(new NoopObservability(), $system))->register();

        self::expectNotToPerformAssertions();
    }
}
