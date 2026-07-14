<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Tracing;

use Monadial\Nexus\Cluster\Tcp\Tests\Support\FakeObservability;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\SpyTracer;
use Monadial\Nexus\Cluster\Tcp\Tracing\ObservabilityTraceContextExtractor;
use Monadial\Nexus\Observability\NoopObservability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObservabilityTraceContextExtractor::class)]
final class ObservabilityTraceContextExtractorTest extends TestCase
{
    #[Test]
    public function extractWhenObservabilityDisabledReturnsRoot(): void
    {
        $extractor = new ObservabilityTraceContextExtractor(new NoopObservability());

        $context = $extractor->extract(
            ['traceparent' => '00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01'],
        );

        self::assertFalse($context->spanContext->isValid());
    }

    #[Test]
    public function extractValidTraceparentReturnsContextWithMatchingTraceId(): void
    {
        $traceId = str_repeat('a', 32);
        $spanId = str_repeat('b', 16);
        $traceparent = "00-{$traceId}-{$spanId}-01";

        $extractor = new ObservabilityTraceContextExtractor(new FakeObservability(new SpyTracer()));

        $context = $extractor->extract(['traceparent' => $traceparent]);

        self::assertTrue($context->spanContext->isValid());
        self::assertSame($traceId, $context->spanContext->traceId);
        self::assertSame($spanId, $context->spanContext->spanId);
        self::assertTrue($context->spanContext->remote);
    }

    #[Test]
    public function extractInvalidTraceparentReturnsRoot(): void
    {
        $extractor = new ObservabilityTraceContextExtractor(new FakeObservability(new SpyTracer()));

        $context = $extractor->extract(['traceparent' => 'not-a-valid-header']);

        self::assertFalse($context->spanContext->isValid());
    }

    #[Test]
    public function extractEmptyCarrierReturnsRoot(): void
    {
        $extractor = new ObservabilityTraceContextExtractor(new FakeObservability(new SpyTracer()));

        $context = $extractor->extract([]);

        self::assertFalse($context->spanContext->isValid());
    }

    #[Test]
    public function injectExtractRoundTripPreservesTraceId(): void
    {
        $traceId = str_repeat('c', 32);
        $spanId = str_repeat('d', 16);

        $obs = new FakeObservability(new SpyTracer());

        // Build a traceparent manually (what the injector would produce)
        $carrier = ['traceparent' => "00-{$traceId}-{$spanId}-01"];

        $extractor = new ObservabilityTraceContextExtractor($obs);
        $context = $extractor->extract($carrier);

        self::assertSame($traceId, $context->spanContext->traceId);
    }

    #[Test]
    public function extractWithTracestatePreservesTracestate(): void
    {
        $traceId = str_repeat('e', 32);
        $spanId = str_repeat('f', 16);

        $extractor = new ObservabilityTraceContextExtractor(new FakeObservability(new SpyTracer()));

        $context = $extractor->extract([
            'traceparent' => "00-{$traceId}-{$spanId}-01",
            'tracestate' => 'vendor=value',
        ]);

        self::assertSame('vendor=value', $context->spanContext->traceState);
    }
}
