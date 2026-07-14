<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Trace;

use Override;
use Throwable;

/**
 * @psalm-api
 *
 * Do-nothing span. Its context is always invalid, so downstream
 * propagation injects nothing.
 */
final class NoopSpan implements Span
{
    #[Override]
    public function setAttribute(string $key, string|int|float|bool $value): void
    {
        // no-op — this span records nothing when observability is disabled.
    }

    /**
     * @param array<string, scalar> $attributes
     */
    #[Override]
    public function setAttributes(array $attributes): void
    {
        // no-op — this span records nothing when observability is disabled.
    }

    /**
     * @param array<string, scalar> $attributes
     */
    #[Override]
    public function addEvent(string $name, array $attributes = []): void
    {
        // no-op — this span records nothing when observability is disabled.
    }

    #[Override]
    public function recordException(Throwable $exception): void
    {
        // no-op — this span records nothing when observability is disabled.
    }

    #[Override]
    public function setStatus(StatusCode $code, ?string $description = null): void
    {
        // no-op — this span records nothing when observability is disabled.
    }

    #[Override]
    public function end(): void
    {
        // no-op — this span records nothing when observability is disabled.
    }

    #[Override]
    public function context(): SpanContext
    {
        return SpanContext::invalid();
    }
}
