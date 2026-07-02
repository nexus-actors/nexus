<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit\Trace;

use Monadial\Nexus\Observability\Otel\Trace\OtelSpan;
use Monadial\Nexus\Observability\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(OtelSpan::class)]
final class OtelSpanTest extends TestCase
{
    #[Test]
    public function recordsAttributesStatusAndExposesContext(): void
    {
        $exporter = new InMemoryExporter();
        $provider = new TracerProvider(new SimpleSpanProcessor($exporter));
        $otelSpan = $provider->getTracer('test')->spanBuilder('op')->startSpan();

        $span = new OtelSpan($otelSpan);
        $span->setAttribute('nexus.actor.path', '/user/a');
        $span->setAttributes(['nexus.mailbox.depth' => 3]);
        $span->addEvent('stashed', ['count' => 1]);
        $span->recordException(new RuntimeException('boom'));
        $span->setStatus(StatusCode::Error, 'failed');

        $context = $span->context();
        self::assertTrue($context->isValid());
        self::assertSame($otelSpan->getContext()->getTraceId(), $context->traceId);
        self::assertSame($otelSpan->getContext()->getSpanId(), $context->spanId);

        $span->end();
        $provider->forceFlush();

        $exported = $exporter->getSpans();
        self::assertCount(1, $exported);
        self::assertSame('/user/a', $exported[0]->getAttributes()->get('nexus.actor.path'));
        self::assertSame(3, $exported[0]->getAttributes()->get('nexus.mailbox.depth'));
        self::assertSame('Error', $exported[0]->getStatus()->getCode());
    }
}
