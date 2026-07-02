<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Context;

use Monadial\Nexus\Observability\Context\Baggage;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Trace\SpanContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BaggagePropagator::class)]
final class BaggagePropagatorTest extends TestCase
{
    private BaggagePropagator $propagator;

    protected function setUp(): void
    {
        $this->propagator = new BaggagePropagator();
    }

    #[Test]
    public function injectsBaggageHeader(): void
    {
        $context = Context::root()->withBaggage(
            Baggage::empty()->with('tenant.id', 'acme')->with('user.tier', 'gold'),
        );

        $carrier = [];
        $this->propagator->inject($context, $carrier);

        self::assertSame('tenant.id=acme,user.tier=gold', $carrier['baggage']);
    }

    #[Test]
    public function injectDoesNothingForEmptyBaggage(): void
    {
        $carrier = [];
        $this->propagator->inject(Context::root(), $carrier);

        self::assertSame([], $carrier);
    }

    #[Test]
    public function injectPercentEncodesValues(): void
    {
        $context = Context::root()->withBaggage(Baggage::empty()->with('note', 'a b,c'));

        $carrier = [];
        $this->propagator->inject($context, $carrier);

        self::assertSame('note=a%20b%2Cc', $carrier['baggage']);
    }

    #[Test]
    public function injectPercentEncodesKeyAndRoundTrips(): void
    {
        $context = Context::root()->withBaggage(Baggage::empty()->with('user id', 'x'));

        $carrier = [];
        $this->propagator->inject($context, $carrier);

        self::assertSame('user%20id=x', $carrier['baggage']);

        $extracted = $this->propagator->extract($carrier);

        self::assertSame('x', $extracted->baggage->get('user id'));
    }

    #[Test]
    public function extractsBaggageHeader(): void
    {
        $context = $this->propagator->extract(['baggage' => 'tenant.id=acme,user.tier=gold']);

        self::assertSame('acme', $context->baggage->get('tenant.id'));
        self::assertSame('gold', $context->baggage->get('user.tier'));
    }

    #[Test]
    public function extractDecodesValuesAndIgnoresProperties(): void
    {
        $context = $this->propagator->extract(['baggage' => 'note=a%20b;meta=1']);

        self::assertSame('a b', $context->baggage->get('note'));
    }

    #[Test]
    public function extractPreservesIncomingSpanContext(): void
    {
        $incoming = Context::fromSpanContext(new SpanContext(
            traceId: '0af7651916cd43dd8448eb211c80319c',
            spanId: 'b7ad6b7169203331',
            traceFlags: 1,
            remote: true,
        ));

        $context = $this->propagator->extract(['baggage' => 'k=v'], $incoming);

        self::assertTrue($context->spanContext->isValid());
        self::assertSame('v', $context->baggage->get('k'));
    }

    #[Test]
    public function extractReturnsEmptyBaggageWhenHeaderMissing(): void
    {
        self::assertTrue($this->propagator->extract([])->baggage->isEmpty());
    }

    #[Test]
    public function extractSkipsMembersWithEmptyKey(): void
    {
        $context = $this->propagator->extract(['baggage' => '=orphan,k=v']);

        self::assertSame('v', $context->baggage->get('k'));
        self::assertNull($context->baggage->get(''));
    }
}
