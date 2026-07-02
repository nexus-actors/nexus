<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Logger\Tests\Unit;

use LogicException;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Record;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\ContextPropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Logger\TraceCorrelationProcessor;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Observability\Trace\SpanKind;
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
use RuntimeException;

use function microtime;

#[CoversClass(TraceCorrelationProcessor::class)]
final class TraceCorrelationProcessorTest extends TestCase
{
    private function observability(): OtelObservability
    {
        return new OtelObservability(
            new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter())),
            MeterProvider::builder()->addReader(new ExportingReader(new MetricInMemoryExporter()))->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );
    }

    private function record(): Record
    {
        return new Record(Level::Info, 'hello', [], 'app', microtime(true));
    }

    #[Test]
    public function stampsActiveSpanIdsIntoExtra(): void
    {
        $observability = $this->observability();
        $processor = new TraceCorrelationProcessor($observability);

        $span = $observability->tracer()->startSpan('op', SpanKind::Internal);
        $processed = $processor->process($this->record());
        $expected = $span->context();
        $span->end();

        self::assertSame($expected->traceId, $processed->extra['trace_id']);
        self::assertSame($expected->spanId, $processed->extra['span_id']);
        self::assertSame($expected->traceFlags, $processed->extra['trace_flags']);
    }

    #[Test]
    public function neverThrowsWhenTelemetryFails(): void
    {
        $broken = new class implements Observability {
            public function isEnabled(): bool
            {
                return true;
            }

            public function currentContext(): Context
            {
                throw new RuntimeException('boom');
            }

            public function tracer(): Tracer
            {
                throw new LogicException('not called');
            }

            public function meter(): Meter
            {
                throw new LogicException('not called');
            }

            public function propagator(): ContextPropagator
            {
                throw new LogicException('not called');
            }

            public function shutdown(): void {}
        };

        $record = $this->record();
        $processed = (new TraceCorrelationProcessor($broken))->process($record);

        self::assertInstanceOf(Record::class, $processed);
        self::assertArrayNotHasKey('trace_id', $processed->extra);
    }

    #[Test]
    public function noOpWhenNoActiveSpan(): void
    {
        $processed = (new TraceCorrelationProcessor($this->observability()))->process($this->record());

        self::assertArrayNotHasKey('trace_id', $processed->extra);
    }

    #[Test]
    public function noOpWhenDisabled(): void
    {
        $processed = (new TraceCorrelationProcessor(new NoopObservability()))->process($this->record());

        self::assertArrayNotHasKey('trace_id', $processed->extra);
    }
}
