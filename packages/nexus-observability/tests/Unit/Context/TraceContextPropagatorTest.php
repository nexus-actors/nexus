<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Context;

use Monadial\Nexus\Observability\Context\Baggage;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Trace\SpanContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TraceContextPropagator::class)]
final class TraceContextPropagatorTest extends TestCase
{
    private TraceContextPropagator $propagator;

    protected function setUp(): void
    {
        $this->propagator = new TraceContextPropagator();
    }

    #[Test]
    public function injectsValidContextAsTraceparent(): void
    {
        $context = Context::fromSpanContext(new SpanContext(
            traceId: '0af7651916cd43dd8448eb211c80319c',
            spanId: 'b7ad6b7169203331',
            traceFlags: 1,
            remote: false,
        ));

        $carrier = [];
        $this->propagator->inject($context, $carrier);

        self::assertSame(
            '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            $carrier['traceparent'],
        );
    }

    #[Test]
    public function injectDoesNothingForInvalidContext(): void
    {
        $carrier = [];
        $this->propagator->inject(Context::root(), $carrier);

        self::assertSame([], $carrier);
    }

    #[Test]
    public function extractsValidTraceparentAsRemoteContext(): void
    {
        $context = $this->propagator->extract([
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);

        self::assertTrue($context->spanContext->isValid());
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $context->spanContext->traceId);
        self::assertSame('b7ad6b7169203331', $context->spanContext->spanId);
        self::assertSame(1, $context->spanContext->traceFlags);
        self::assertTrue($context->spanContext->remote);
    }

    #[Test]
    public function extractPreservesTracestate(): void
    {
        $context = $this->propagator->extract([
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            'tracestate' => 'vendor=value',
        ]);

        self::assertSame('vendor=value', $context->spanContext->traceState);
    }

    #[Test]
    public function extractPreservesIncomingBaggage(): void
    {
        $incoming = Context::root()->withBaggage(
            Baggage::empty()->with('tenant.id', 'acme'),
        );

        $context = $this->propagator->extract(
            ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'],
            $incoming,
        );

        self::assertTrue($context->spanContext->isValid());
        self::assertSame('acme', $context->baggage->get('tenant.id'));
    }

    #[Test]
    public function extractReturnsRootWhenHeaderMissing(): void
    {
        self::assertFalse($this->propagator->extract([])->spanContext->isValid());
    }

    /**
     * @param non-empty-string $traceparent
     */
    #[Test]
    #[DataProvider('malformedHeaders')]
    public function extractReturnsInvalidSpanForMalformedHeader(string $traceparent): void
    {
        $context = $this->propagator->extract(['traceparent' => $traceparent]);

        self::assertFalse($context->spanContext->isValid());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedHeaders(): iterable
    {
        yield 'too few parts' => ['00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331'];
        yield 'bad version' => ['ff-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'];
        yield 'short trace id' => ['00-0af7-b7ad6b7169203331-01'];
        yield 'non-hex span id' => ['00-0af7651916cd43dd8448eb211c80319c-zzzzzzzzzzzzzzzz-01'];
        yield 'all-zero trace id' => ['00-00000000000000000000000000000000-b7ad6b7169203331-01'];
        yield 'all-zero span id' => ['00-0af7651916cd43dd8448eb211c80319c-0000000000000000-01'];
    }

    #[Test]
    public function roundTripPreservesContext(): void
    {
        $original = new SpanContext(
            traceId: '4bf92f3577b34da6a3ce929d0e0e4736',
            spanId: '00f067aa0ba902b7',
            traceFlags: 1,
            remote: false,
        );

        $carrier = [];
        $this->propagator->inject(Context::fromSpanContext($original), $carrier);
        $extracted = $this->propagator->extract($carrier)->spanContext;

        self::assertSame($original->traceId, $extracted->traceId);
        self::assertSame($original->spanId, $extracted->spanId);
        self::assertSame($original->traceFlags, $extracted->traceFlags);
    }

    #[Test]
    public function injectsFullTraceFlagsByteAndRoundTrips(): void
    {
        $original = new SpanContext(
            traceId: '4bf92f3577b34da6a3ce929d0e0e4736',
            spanId: '00f067aa0ba902b7',
            traceFlags: 3,
            remote: false,
        );

        $carrier = [];
        $this->propagator->inject(Context::fromSpanContext($original), $carrier);

        self::assertStringEndsWith('-03', $carrier['traceparent']);

        $extracted = $this->propagator->extract($carrier)->spanContext;

        self::assertSame(3, $extracted->traceFlags);
    }
}
