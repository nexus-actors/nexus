<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Context;

use Monadial\Nexus\Observability\Context\Baggage;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Trace\SpanContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Baggage::class)]
#[CoversClass(Context::class)]
final class BaggageTest extends TestCase
{
    #[Test]
    public function emptyBaggageIsEmpty(): void
    {
        $baggage = Baggage::empty();

        self::assertTrue($baggage->isEmpty());
        self::assertNull($baggage->get('missing'));
        self::assertSame([], $baggage->all());
    }

    #[Test]
    public function withAddsImmutably(): void
    {
        $base = Baggage::empty();
        $updated = $base->with('tenant.id', 'acme');

        self::assertTrue($base->isEmpty());
        self::assertSame('acme', $updated->get('tenant.id'));
        self::assertFalse($updated->isEmpty());
    }

    #[Test]
    public function rootContextHasEmptyBaggageAndInvalidSpan(): void
    {
        $context = Context::root();

        self::assertFalse($context->spanContext->isValid());
        self::assertTrue($context->baggage->isEmpty());
    }

    #[Test]
    public function withSameKeyOverwritesPreviousValue(): void
    {
        $baggage = Baggage::empty()->with('k', 'v1')->with('k', 'v2');

        self::assertSame('v2', $baggage->get('k'));
    }

    #[Test]
    public function withersReplaceOnlyTheTargetField(): void
    {
        $span = new SpanContext(
            traceId: '0af7651916cd43dd8448eb211c80319c',
            spanId: 'b7ad6b7169203331',
            traceFlags: 1,
            remote: false,
        );
        $context = Context::root()
            ->withSpanContext($span)
            ->withBaggage(Baggage::empty()->with('user.tier', 'gold'));

        self::assertTrue($context->spanContext->isValid());
        self::assertSame('gold', $context->baggage->get('user.tier'));
    }
}
