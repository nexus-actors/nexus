<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Tracing;

use Monadial\Nexus\Cluster\Tcp\Tests\Support\FakeObservability;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\SpyTracer;
use Monadial\Nexus\Cluster\Tcp\Tracing\ObservabilityTraceContextInjector;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Trace\SpanContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObservabilityTraceContextInjector::class)]
final class ObservabilityTraceContextInjectorTest extends TestCase
{
    #[Test]
    public function injectWhenObservabilityDisabledReturnsEmpty(): void
    {
        $injector = new ObservabilityTraceContextInjector(new NoopObservability());

        self::assertSame([], $injector->inject());
    }

    #[Test]
    public function injectWhenNoActiveSpanReturnsEmpty(): void
    {
        // FakeObservability defaults to Context::root() — invalid span context.
        $injector = new ObservabilityTraceContextInjector(new FakeObservability(new SpyTracer()));

        self::assertSame([], $injector->inject());
    }

    #[Test]
    public function injectWithValidSpanContextReturnsTraceparentHeader(): void
    {
        $traceId = str_repeat('a', 32);
        $spanId = str_repeat('b', 16);
        $spanContext = new SpanContext($traceId, $spanId, 1, false);
        $context = Context::fromSpanContext($spanContext);

        $obs = new FakeObservability(new SpyTracer(), $context);
        $injector = new ObservabilityTraceContextInjector($obs);

        $headers = $injector->inject();

        self::assertArrayHasKey('traceparent', $headers);
        // W3C format: 00-{traceId}-{spanId}-{flags}
        self::assertSame("00-{$traceId}-{$spanId}-01", $headers['traceparent']);
    }

    #[Test]
    public function injectWithTracestateIncludesTracestateHeader(): void
    {
        $traceId = str_repeat('c', 32);
        $spanId = str_repeat('d', 16);
        $spanContext = new SpanContext($traceId, $spanId, 1, false, 'vendor=value');
        $context = Context::fromSpanContext($spanContext);

        $obs = new FakeObservability(new SpyTracer(), $context);
        $injector = new ObservabilityTraceContextInjector($obs);

        $headers = $injector->inject();

        self::assertArrayHasKey('tracestate', $headers);
        self::assertSame('vendor=value', $headers['tracestate']);
    }

    #[Test]
    public function injectIsIdempotentAcrossMultipleCalls(): void
    {
        $spanContext = new SpanContext(str_repeat('e', 32), str_repeat('f', 16), 1, false);
        $obs = new FakeObservability(new SpyTracer(), Context::fromSpanContext($spanContext));
        $injector = new ObservabilityTraceContextInjector($obs);

        $first = $injector->inject();
        $second = $injector->inject();

        self::assertSame($first, $second);
    }
}
