<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit\Metric;

use Monadial\Nexus\Observability\Otel\Metric\OtelMeter;
use Monadial\Nexus\Observability\Otel\Metric\OtelObservableGauge;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtelMeter::class)]
final class OtelMeterTest extends TestCase
{
    private InMemoryExporter $exporter;
    private ExportingReader $reader;
    private MeterProvider $provider;
    private OtelMeter $meter;

    #[Test]
    public function recordsCounterHistogramAndUpDown(): void
    {
        $this->meter->counter('nexus.messages.processed', '{message}', 'processed')->add(
            2,
            ['nexus.message.type' => 'Greet'],
        );
        $this->meter->histogram('nexus.msg.duration', 'ms')->record(12.5);
        $this->meter->upDownCounter('nexus.actor.mailbox.size')->add(3);

        $this->reader->collect();
        $names = array_map(static fn($metric): string => $metric->name, $this->exporter->collect());

        self::assertContains('nexus.messages.processed', $names);
        self::assertContains('nexus.msg.duration', $names);
        self::assertContains('nexus.actor.mailbox.size', $names);
    }

    #[Test]
    public function observableGaugeReportsCallbackValue(): void
    {
        $gauge = $this->meter->observableGauge('nexus.runtime.coroutines', static fn(): int => 7);

        $this->reader->collect();
        $names = array_map(static fn($metric): string => $metric->name, $this->exporter->collect());

        self::assertContains('nexus.runtime.coroutines', $names);
        self::assertInstanceOf(OtelObservableGauge::class, $gauge);
    }

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->reader = new ExportingReader($this->exporter);
        $this->provider = MeterProvider::builder()->addReader($this->reader)->build();
        $this->meter = new OtelMeter($this->provider->getMeter('test'));
    }
}
