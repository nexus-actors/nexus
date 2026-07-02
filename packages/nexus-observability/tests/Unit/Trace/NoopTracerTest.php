<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Trace;

use Monadial\Nexus\Observability\Trace\NoopSpan;
use Monadial\Nexus\Observability\Trace\NoopTracer;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(NoopTracer::class)]
#[CoversClass(NoopSpan::class)]
final class NoopTracerTest extends TestCase
{
    #[Test]
    public function startSpanReturnsAnInvalidNoopSpan(): void
    {
        $span = (new NoopTracer())->startSpan('op', SpanKind::Consumer, ['nexus.actor.path' => '/user/a']);

        self::assertInstanceOf(NoopSpan::class, $span);
        self::assertFalse($span->context()->isValid());
    }

    #[Test]
    public function spanMethodsDoNotThrow(): void
    {
        $span = (new NoopTracer())->startSpan('op');

        $span->setAttribute('key', 'value');
        $span->setAttributes(['a' => 1, 'b' => true]);
        $span->addEvent('event', ['x' => 'y']);
        $span->recordException(new RuntimeException('boom'));
        $span->setStatus(StatusCode::Error, 'failed');
        $span->end();

        self::assertFalse($span->context()->isValid());
    }
}
