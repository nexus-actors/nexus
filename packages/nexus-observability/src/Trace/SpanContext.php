<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Trace;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Minimal, transport-agnostic identity of a span for context propagation.
 *
 * `traceId` is a 32-char lowercase hex string, `spanId` a 16-char lowercase hex
 * string, `traceFlags` the W3C trace-flags byte (only bit 0, "sampled", is used).
 */
final readonly class SpanContext
{
    public function __construct(
        public string $traceId,
        public string $spanId,
        public int $traceFlags,
        public bool $remote,
        public string $traceState = '',
    ) {}

    /** An unset/invalid context (all-zero ids). */
    public static function invalid(): self
    {
        return new self(
            traceId: str_repeat('0', 32),
            spanId: str_repeat('0', 16),
            traceFlags: 0,
            remote: false,
        );
    }

    /** True when both ids are non-zero (a real, propagatable context). */
    public function isValid(): bool
    {
        return $this->traceId !== str_repeat('0', 32)
            && $this->spanId !== str_repeat('0', 16);
    }
}
