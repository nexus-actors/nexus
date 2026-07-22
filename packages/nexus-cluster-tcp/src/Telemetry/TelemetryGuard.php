<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Telemetry;

use Monadial\Nexus\Observability\Trace\NoopSpan;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\Tracer;
use Throwable;

/**
 * Swallow-safe telemetry helper: a broken tracer or meter must never disrupt
 * cluster operations (spec §3.5). Replaces the per-class safely()/safeSpan
 * copies.
 */
final readonly class TelemetryGuard
{
    /**
     * @param callable(): mixed $fn
     */
    public function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break cluster operations.
        }
    }

    /**
     * @param array<string, scalar> $attributes
     */
    public function startSpan(
        Tracer $tracer,
        string $name,
        SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
    ): Span
    {
        try {
            return $tracer->startSpan($name, $kind, $attributes);
        } catch (Throwable) {
            return new NoopSpan();
        }
    }

    public function attribute(Span $span, string $key, string|int|float|bool $value): void
    {
        try {
            $span->setAttribute($key, $value);
        } catch (Throwable) {
        }
    }

    public function recordException(Span $span, Throwable $exception): void
    {
        try {
            $span->recordException($exception);
        } catch (Throwable) {
        }
    }

    public function end(Span $span): void
    {
        try {
            $span->end();
        } catch (Throwable) {
        }
    }
}
