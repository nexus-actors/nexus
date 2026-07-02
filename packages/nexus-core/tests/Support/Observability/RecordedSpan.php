<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support\Observability;

use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanContext;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Throwable;

final class RecordedSpan implements Span
{
    public bool $ended = false;

    public StatusCode $status = StatusCode::Unset;

    public ?string $statusDescription = null;

    public ?Throwable $exception = null;

    /**
     * @param array<string, scalar> $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly SpanKind $kind,
        public array $attributes,
        private readonly SpanContext $context,
    ) {}

    public function setAttribute(string $key, string|int|float|bool $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * @param array<string, scalar> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
    }

    /**
     * @param array<string, scalar> $attributes
     */
    public function addEvent(string $name, array $attributes = []): void
    {
        // no-op: events are not recorded by this test double
    }

    public function recordException(Throwable $exception): void
    {
        $this->exception = $exception;
    }

    public function setStatus(StatusCode $code, ?string $description = null): void
    {
        $this->status = $code;
        $this->statusDescription = $description;
    }

    public function end(): void
    {
        $this->ended = true;
    }

    public function context(): SpanContext
    {
        return $this->context;
    }
}
