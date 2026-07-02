<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Context;

use Monadial\Nexus\Observability\Context\Baggage;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Trace\SpanContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositePropagator::class)]
final class CompositePropagatorTest extends TestCase
{
    private CompositePropagator $propagator;

    protected function setUp(): void
    {
        $this->propagator = new CompositePropagator([
            new TraceContextPropagator(),
            new BaggagePropagator(),
        ]);
    }

    #[Test]
    public function injectsBothTraceparentAndBaggage(): void
    {
        $context = Context::fromSpanContext(new SpanContext(
            traceId: '0af7651916cd43dd8448eb211c80319c',
            spanId: 'b7ad6b7169203331',
            traceFlags: 1,
            remote: false,
        ))->withBaggage(Baggage::empty()->with('tenant.id', 'acme'));

        $carrier = [];
        $this->propagator->inject($context, $carrier);

        self::assertSame('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01', $carrier['traceparent']);
        self::assertSame('tenant.id=acme', $carrier['baggage']);
    }

    #[Test]
    public function extractsBothIntoOneContext(): void
    {
        $context = $this->propagator->extract([
            'baggage' => 'tenant.id=acme',
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);

        self::assertTrue($context->spanContext->isValid());
        self::assertSame('acme', $context->baggage->get('tenant.id'));
    }

    #[Test]
    public function extractWithOnlyBaggageHeaderSetsBaggageAndLeavesSpanInvalid(): void
    {
        $context = $this->propagator->extract(['baggage' => 'tenant.id=acme']);

        self::assertSame('acme', $context->baggage->get('tenant.id'));
        self::assertFalse($context->spanContext->isValid());
    }

    #[Test]
    public function roundTripThroughCarrierPreservesBoth(): void
    {
        $original = Context::fromSpanContext(new SpanContext(
            traceId: '4bf92f3577b34da6a3ce929d0e0e4736',
            spanId: '00f067aa0ba902b7',
            traceFlags: 1,
            remote: false,
        ))->withBaggage(Baggage::empty()->with('user.tier', 'gold'));

        $carrier = [];
        $this->propagator->inject($original, $carrier);
        $extracted = $this->propagator->extract($carrier);

        self::assertSame($original->spanContext->traceId, $extracted->spanContext->traceId);
        self::assertSame('gold', $extracted->baggage->get('user.tier'));
    }
}
