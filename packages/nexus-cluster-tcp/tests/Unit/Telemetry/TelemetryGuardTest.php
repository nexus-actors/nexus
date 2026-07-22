<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Telemetry;

use Monadial\Nexus\Cluster\Tcp\Telemetry\TelemetryGuard;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Trace\NoopSpan;
use Monadial\Nexus\Observability\Trace\NoopTracer;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanContext;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Monadial\Nexus\Observability\Trace\Tracer;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(TelemetryGuard::class)]
final class TelemetryGuardTest extends TestCase
{
    #[Test]
    public function safelySwallowsThrowables(): void
    {
        $guard = new TelemetryGuard();

        $guard->safely(static fn() => throw new RuntimeException('telemetry broke'));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function safelyRunsTheCallable(): void
    {
        $guard = new TelemetryGuard();
        $ran = false;

        $guard->safely(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertTrue($ran);
    }

    #[Test]
    public function startSpanDelegatesToTracer(): void
    {
        $guard = new TelemetryGuard();

        $span = $guard->startSpan(new NoopTracer(), 'cluster.handshake');

        self::assertInstanceOf(NoopSpan::class, $span);
    }

    #[Test]
    public function spanHelpersSwallowThrowables(): void
    {
        $guard = new TelemetryGuard();
        $span = new NoopSpan();

        $guard->attribute($span, 'nexus.cluster.peer', 'node-1');
        $guard->recordException($span, new RuntimeException('x'));
        $guard->end($span);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function startSpanReturnsNoopSpanWhenTracerThrows(): void
    {
        $guard = new TelemetryGuard();
        $throwingTracer = new class implements Tracer {
            #[Override]
            public function startSpan(
                string $name,
                SpanKind $kind = SpanKind::Internal,
                array $attributes = [],
                ?Context $parent = null,
            ): Span {
                throw new RuntimeException('tracer broken');
            }
        };

        $span = $guard->startSpan($throwingTracer, 'cluster.handshake');

        self::assertInstanceOf(NoopSpan::class, $span);
    }

    #[Test]
    public function spanHelpersSwallowThrowablesWhenSpanThrows(): void
    {
        $guard = new TelemetryGuard();
        $throwingSpan = new class implements Span {
            #[Override]
            public function setAttribute(string $key, string|int|float|bool $value): void
            {
                throw new RuntimeException('span broken');
            }

            /**
             * @param array<string, scalar> $attributes
             */
            #[Override]
            public function setAttributes(array $attributes): void
            {
                // unused by this double — only setAttribute is exercised
            }

            /**
             * @param array<string, scalar> $attributes
             */
            #[Override]
            public function addEvent(string $name, array $attributes = []): void
            {
                // unused by this double — only setAttribute is exercised
            }

            #[Override]
            public function recordException(Throwable $exception): void
            {
                throw new RuntimeException('span broken');
            }

            #[Override]
            public function setStatus(StatusCode $code, ?string $description = null): void
            {
                // unused by this double — only setAttribute is exercised
            }

            #[Override]
            public function end(): void
            {
                throw new RuntimeException('span broken');
            }

            #[Override]
            public function context(): SpanContext
            {
                return SpanContext::invalid();
            }
        };

        $guard->attribute($throwingSpan, 'nexus.cluster.peer', 'node-1');
        $guard->recordException($throwingSpan, new RuntimeException('x'));
        $guard->end($throwingSpan);

        $this->addToAssertionCount(1);
    }
}
