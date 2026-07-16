<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Trace;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Otel\AttributeKeys;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\Tracer;
use OpenTelemetry\API\Trace\Span as OtelApiSpan;
use OpenTelemetry\API\Trace\SpanContext as OtelSpanContext;
use OpenTelemetry\API\Trace\SpanKind as OtelSpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context as OtelContext;
use OpenTelemetry\Context\ContextInterface;
use Override;

/**
 * @psalm-api
 *
 * Adapts an OpenTelemetry {@see TracerInterface} to the Nexus {@see Tracer}
 * contract. Started spans are activated so nested spans chain via OTEL context
 * storage; the returned {@see OtelSpan} detaches the scope on end.
 */
final readonly class OtelTracer implements Tracer
{
    /**
     * Captured once at construction (bootstrap, before any actor fiber runs): the
     * ambient OTEL context — the root context in practice — used as the base when
     * linking a remote parent. Avoids OtelContext::getRoot(), which the SDK marks
     * `@internal`, and avoids per-span OtelContext::getCurrent() calls, which warn
     * under the SDK's fiber-bound storage when made from unattached fibers.
     */
    private ContextInterface $base;

    public function __construct(private TracerInterface $tracer)
    {
        $this->base = OtelContext::getCurrent();
    }

    #[Override]
    public function startSpan(
        string $name,
        SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
        ?Context $parent = null,
    ): Span {
        // The OTel SDK requires a non-empty span name; per the OTel spec, spans
        // without a name receive a placeholder.
        $spanName = $name === ''
            ? 'unnamed'
            : $name;

        $builder = $this->tracer
            ->spanBuilder($spanName)
            ->setSpanKind($this->mapKind($kind))
            ->setAttributes(AttributeKeys::nonEmpty($attributes));

        if ($parent !== null && $parent->spanContext->isValid()) {
            $remote = OtelSpanContext::createFromRemoteParent(
                $parent->spanContext->traceId,
                $parent->spanContext->spanId,
                $parent->spanContext->traceFlags,
            );
            $builder = $builder->setParent(
                $this->base->withContextValue(OtelApiSpan::wrap($remote)),
            );
        }

        $span = $builder->startSpan();

        return new OtelSpan($span, $span->activate());
    }

    /**
     * @return 0|1|2|3|4
     */
    private function mapKind(SpanKind $kind): int
    {
        return match ($kind) {
            SpanKind::Client => OtelSpanKind::KIND_CLIENT,
            SpanKind::Consumer => OtelSpanKind::KIND_CONSUMER,
            SpanKind::Internal => OtelSpanKind::KIND_INTERNAL,
            SpanKind::Producer => OtelSpanKind::KIND_PRODUCER,
            SpanKind::Server => OtelSpanKind::KIND_SERVER,
        };
    }
}
