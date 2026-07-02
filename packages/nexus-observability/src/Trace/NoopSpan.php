<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Trace;

use Throwable;

/**
 * @psalm-api
 *
 * Do-nothing span. Its context is always invalid, so downstream
 * propagation injects nothing.
 */
final class NoopSpan implements Span
{
    public function setAttribute(string $key, string|int|float|bool $value): void {}

    /**
     * @param array<string, scalar> $attributes
     */
    public function setAttributes(array $attributes): void {}

    /**
     * @param array<string, scalar> $attributes
     */
    public function addEvent(string $name, array $attributes = []): void {}

    public function recordException(Throwable $exception): void {}

    public function setStatus(StatusCode $code, ?string $description = null): void {}

    public function end(): void {}

    public function context(): SpanContext
    {
        return SpanContext::invalid();
    }
}
