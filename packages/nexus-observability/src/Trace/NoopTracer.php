<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Trace;

use Monadial\Nexus\Observability\Context\Context;
use Override;

/** @psalm-api */
final readonly class NoopTracer implements Tracer
{
    public function __construct(private Span $span = new NoopSpan()) {}

    #[Override]
    public function startSpan(
        string $name,
        SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
        ?Context $parent = null,
    ): Span {
        return $this->span;
    }
}
