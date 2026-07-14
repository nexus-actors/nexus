<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Support;

use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanContext;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Override;
use Throwable;

final class SpySpan implements Span
{
    public bool $ended = false;

    /** @var list<Throwable> */
    public array $recordedExceptions = [];

    /** @var array<string, scalar> */
    public array $attributes = [];

    /** @var list<StatusCode> */
    public array $statuses = [];

    public function __construct(
        private readonly string $traceId = '00000000000000000000000000000000',
        private readonly string $spanId = '0000000000000000',
    ) {}

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
        // not recorded — tests don't assert on events
    }

    #[Override]
    public function recordException(Throwable $exception): void
    {
        $this->recordedExceptions[] = $exception;
    }

    #[Override]
    public function setStatus(StatusCode $code, ?string $description = null): void
    {
        $this->statuses[] = $code;
    }

    #[Override]
    public function end(): void
    {
        $this->ended = true;
    }

    #[Override]
    public function context(): SpanContext
    {
        return new SpanContext(traceId: $this->traceId, spanId: $this->spanId, traceFlags: 1, remote: false);
    }
}
