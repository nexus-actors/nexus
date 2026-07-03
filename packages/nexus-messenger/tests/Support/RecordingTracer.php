<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Support;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\Tracer;
use Override;

final class RecordingTracer implements Tracer
{
    /** @var list<RecordingSpan> */
    public array $spans = [];

    /**
     * @param array<string, scalar> $attributes
     */
    #[Override]
    public function startSpan(
        string $name,
        SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
        ?Context $parent = null,
    ): Span {
        $span = new RecordingSpan($name, $kind, $attributes);
        $this->spans[] = $span;

        return $span;
    }
}
