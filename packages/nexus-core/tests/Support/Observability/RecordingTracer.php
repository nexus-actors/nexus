<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support\Observability;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanContext;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\Tracer;

use function sprintf;

final class RecordingTracer implements Tracer
{
    /** @var list<RecordedSpan> */
    public array $spans = [];

    /** @var list<RecordedSpan> */
    public array $active = [];

    private int $counter = 0;

    /**
     * @param array<string, scalar> $attributes
     */
    public function startSpan(
        string $name,
        SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
        ?Context $parent = null,
    ): Span {
        ++$this->counter;
        $traceId = $parent !== null && $parent->spanContext->isValid()
            ? $parent->spanContext->traceId
            : sprintf('%032x', $this->counter);
        $spanId = sprintf('%016x', $this->counter);

        $span = new RecordedSpan(
            $name,
            $kind,
            $attributes,
            new SpanContext($traceId, $spanId, 1, false),
        );
        $this->spans[] = $span;
        $this->active[] = $span;

        return $span;
    }

    public function currentSpanContext(): SpanContext
    {
        for ($i = count($this->active) - 1; $i >= 0; --$i) {
            if (!$this->active[$i]->ended) {
                return $this->active[$i]->context();
            }
        }

        return SpanContext::invalid();
    }
}
