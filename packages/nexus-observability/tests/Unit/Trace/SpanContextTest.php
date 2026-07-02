<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Trace;

use Monadial\Nexus\Observability\Trace\SpanContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpanContext::class)]
final class SpanContextTest extends TestCase
{
    #[Test]
    public function invalidContextIsNotValid(): void
    {
        $context = SpanContext::invalid();

        self::assertFalse($context->isValid());
        self::assertFalse($context->remote);
        self::assertSame('', $context->traceState);
    }

    #[Test]
    public function populatedContextIsValid(): void
    {
        $context = new SpanContext(
            traceId: '0af7651916cd43dd8448eb211c80319c',
            spanId: 'b7ad6b7169203331',
            traceFlags: 1,
            remote: true,
        );

        self::assertTrue($context->isValid());
        self::assertTrue($context->remote);
    }

    #[Test]
    public function allZeroIdsAreNotValid(): void
    {
        $context = new SpanContext(
            traceId: str_repeat('0', 32),
            spanId: str_repeat('0', 16),
            traceFlags: 0,
            remote: false,
        );

        self::assertFalse($context->isValid());
    }
}
