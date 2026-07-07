<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Support;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\Tracer;
use Override;

final class SpyTracer implements Tracer
{
    /**
     * @var list<array{name: string, kind: SpanKind, attributes: array<string, scalar>, parent: Context|null, span: SpySpan}>
     */
    public array $started = [];

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
        $span = new SpySpan();

        $this->started[] = [
            'attributes' => $attributes,
            'kind' => $kind,
            'name' => $name,
            'parent' => $parent,
            'span' => $span,
        ];

        return $span;
    }

    /**
     * Return the recorded entries whose `name` matches.
     *
     * @return list<array{name: string, kind: SpanKind, attributes: array<string, scalar>, parent: Context|null, span: SpySpan}>
     */
    public function spansNamed(string $name): array
    {
        return array_values(
            array_filter($this->started, static fn(array $e): bool => $e['name'] === $name),
        );
    }
}
