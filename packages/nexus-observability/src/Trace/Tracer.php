<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Trace;

use Monadial\Nexus\Observability\Context\Context;

/** @psalm-api */
interface Tracer
{
    /**
     * @param array<string, scalar> $attributes
     */
    public function startSpan(
        string $name,
        SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
        ?Context $parent = null,
    ): Span;
}
