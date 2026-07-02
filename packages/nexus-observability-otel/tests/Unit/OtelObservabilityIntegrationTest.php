<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit;

use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class OtelObservabilityIntegrationTest extends TestCase
{
    #[Test]
    public function propagatesTraceAcrossBoundaryAndRecordsMetrics(): void
    {
        $spanExporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($spanExporter));

        $metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($metricExporter);
        $meterProvider = MeterProvider::builder()->addReader($reader)->build();

        $observability = new OtelObservability(
            $tracerProvider,
            $meterProvider,
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        // Inbound carrier (e.g. HTTP headers / envelope metadata) → context.
        $parent = $observability->propagator()->extract([
            'baggage' => 'tenant.id=acme',
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);
        self::assertSame('acme', $parent->baggage->get('tenant.id'));

        $consumer = $observability->tracer()->startSpan('process PlaceOrder', SpanKind::Consumer, ['nexus.actor.path' => '/user/orders'], $parent);
        $current = $observability->currentContext();
        self::assertTrue($current->spanContext->isValid());
        self::assertSame($consumer->context()->spanId, $current->spanContext->spanId);
        $child = $observability->tracer()->startSpan('charge-card', SpanKind::Client);
        $child->end();
        $consumer->setStatus(StatusCode::Ok);
        $consumer->end();

        $observability->meter()->counter('nexus.messages.processed')->add(1, ['nexus.message.type' => 'PlaceOrder']);

        $tracerProvider->forceFlush();
        $reader->collect();

        $spans = $spanExporter->getSpans();
        self::assertCount(2, $spans);

        foreach ($spans as $span) {
            self::assertSame('0af7651916cd43dd8448eb211c80319c', $span->getTraceId());
        }

        $metricNames = array_map(static fn ($metric): string => $metric->name, $metricExporter->collect());
        self::assertContains('nexus.messages.processed', $metricNames);
    }
}
