<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit\Trace;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Otel\Trace\OtelTracer;
use Monadial\Nexus\Observability\Trace\SpanContext;
use Monadial\Nexus\Observability\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtelTracer::class)]
final class OtelTracerTest extends TestCase
{
    private InMemoryExporter $exporter;
    private TracerProvider $provider;
    private OtelTracer $tracer;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $this->tracer = new OtelTracer($this->provider->getTracer('test'));
    }

    #[Test]
    public function startsSpanUnderRemoteParentAndNestsChildren(): void
    {
        $parent = Context::fromSpanContext(new SpanContext(
            traceId: '0af7651916cd43dd8448eb211c80319c',
            spanId: 'b7ad6b7169203331',
            traceFlags: 1,
            remote: true,
        ));

        $consumer = $this->tracer->startSpan('process Greet', SpanKind::Consumer, ['nexus.actor.path' => '/user/g'], $parent);
        $consumerId = $consumer->context()->spanId;
        $child = $this->tracer->startSpan('charge-card', SpanKind::Client);
        $child->end();
        $consumer->end();

        $this->provider->forceFlush();
        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);

        $byName = [];

        foreach ($spans as $span) {
            $byName[$span->getName()] = $span;
        }

        self::assertSame('0af7651916cd43dd8448eb211c80319c', $byName['process Greet']->getTraceId());
        self::assertSame('b7ad6b7169203331', $byName['process Greet']->getParentSpanId());
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $byName['charge-card']->getTraceId());
        self::assertSame($consumerId, $byName['charge-card']->getParentSpanId());
        self::assertSame(4, $byName['process Greet']->getKind());
        self::assertSame(1, $byName['charge-card']->getKind());
    }

    #[Test]
    public function startsNewRootWhenParentInvalid(): void
    {
        $span = $this->tracer->startSpan('root');
        $span->end();

        $this->provider->forceFlush();
        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('0000000000000000', $spans[0]->getParentSpanId());
    }
}
