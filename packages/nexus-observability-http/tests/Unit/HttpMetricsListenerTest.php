<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Http\Tests\Unit;

use Monadial\Nexus\Http\Event\RequestCompleted;
use Monadial\Nexus\Http\Event\RequestStarted;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Http\HttpMetricsListener;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Sum;
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

#[CoversClass(HttpMetricsListener::class)]
final class HttpMetricsListenerTest extends TestCase
{
    #[Test]
    public function recordsDurationAndActiveRequests(): void
    {
        $metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($metricExporter);
        $observability = new OtelObservability(
            new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter())),
            MeterProvider::builder()->addReader($reader)->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $listener = new HttpMetricsListener($observability);
        $request = new ServerRequest('GET', 'https://api.test/orders');

        $listener->onRequestStarted(new RequestStarted($request, 1_000));
        $listener->onRequestCompleted(new RequestCompleted($request, new Response(200), 5_000_000));

        $reader->collect();
        $metrics = $metricExporter->collect();
        $names = array_map(static fn ($metric): string => $metric->name, $metrics);

        self::assertContains('http.server.request.duration', $names);
        self::assertContains('http.server.active_requests', $names);

        $durationMetric = null;
        $activeRequestsMetric = null;

        foreach ($metrics as $metric) {
            if ($metric->name === 'http.server.request.duration') {
                $durationMetric = $metric;
            }

            if ($metric->name === 'http.server.active_requests') {
                $activeRequestsMetric = $metric;
            }
        }

        self::assertNotNull($durationMetric);
        $histogramData = $durationMetric->data;
        self::assertInstanceOf(Histogram::class, $histogramData);
        $durationPoints = [];

        foreach ($histogramData->dataPoints as $point) {
            $durationPoints[] = $point;
        }

        self::assertCount(1, $durationPoints);
        self::assertEqualsWithDelta(0.005, $durationPoints[0]->sum, 1e-9);
        self::assertSame('GET', $durationPoints[0]->attributes->get('http.request.method'));
        self::assertSame(200, $durationPoints[0]->attributes->get('http.response.status_code'));

        self::assertNotNull($activeRequestsMetric);
        $sumData = $activeRequestsMetric->data;
        self::assertInstanceOf(Sum::class, $sumData);
        $activePoints = [];

        foreach ($sumData->dataPoints as $point) {
            $activePoints[] = $point;
        }

        self::assertCount(1, $activePoints);
        self::assertSame(0, $activePoints[0]->value);
    }

    #[Test]
    public function disabledObservabilityRecordsNothing(): void
    {
        $listener = new HttpMetricsListener(new ThrowingWhenDisabledObservability());
        $request = new ServerRequest('GET', 'https://api.test/orders');

        $listener->onRequestStarted(new RequestStarted($request, 1_000));
        $listener->onRequestCompleted(new RequestCompleted($request, new Response(200), 5_000_000));

        self::assertTrue(true);
    }
}
