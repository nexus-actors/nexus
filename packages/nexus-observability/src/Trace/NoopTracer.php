<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Trace;

use Monadial\Nexus\Observability\Context\Context;

/** @psalm-api */
final class NoopTracer implements Tracer
{
    public function __construct(private readonly Span $span = new NoopSpan()) {}

    public function startSpan(
        string $name,
        SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
        ?Context $parent = null,
    ): Span {
        return $this->span;
    }
}
