<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Support;

use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanContext;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Override;
use Throwable;

final class RecordingSpan implements Span
{
    public ?StatusCode $status = null;

    public ?Throwable $exception = null;

    public bool $ended = false;

    /**
     * @param array<string, scalar> $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly SpanKind $kind,
        public array $attributes = [],
    ) {
    }

    #[Override]
    public function setAttribute(string $key, string|int|float|bool $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * @param array<string, scalar> $attributes
     */
    #[Override]
    public function setAttributes(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
    }

    /**
     * @param array<string, scalar> $attributes
     */
    #[Override]
    public function addEvent(string $name, array $attributes = []): void
    {
        // No-op recording double — events are not inspected in tests.
    }

    #[Override]
    public function recordException(Throwable $exception): void
    {
        $this->exception = $exception;
    }

    #[Override]
    public function setStatus(StatusCode $code, ?string $description = null): void
    {
        $this->status = $code;
    }

    #[Override]
    public function end(): void
    {
        $this->ended = true;
    }

    #[Override]
    public function context(): SpanContext
    {
        return SpanContext::invalid();
    }
}
