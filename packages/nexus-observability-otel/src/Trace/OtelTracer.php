<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Trace;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\Tracer;
use OpenTelemetry\API\Trace\Span as OtelApiSpan;
use OpenTelemetry\API\Trace\SpanContext as OtelSpanContext;
use OpenTelemetry\API\Trace\SpanKind as OtelSpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context as OtelContext;
use Override;

/**
 * @psalm-api
 *
 * Adapts an OpenTelemetry {@see TracerInterface} to the Nexus {@see Tracer}
 * contract. Started spans are activated so nested spans chain via OTEL context
 * storage; the returned {@see OtelSpan} detaches the scope on end.
 */
final class OtelTracer implements Tracer
{
    public function __construct(
        private readonly TracerInterface $tracer,
    ) {}

    #[Override]
    public function startSpan(
        string $name,
        SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
        ?Context $parent = null,
    ): Span {
        $builder = $this->tracer
            ->spanBuilder($name)
            ->setSpanKind($this->mapKind($kind))
            ->setAttributes($attributes);

        if ($parent !== null && $parent->spanContext->isValid()) {
            $remote = OtelSpanContext::createFromRemoteParent(
                $parent->spanContext->traceId,
                $parent->spanContext->spanId,
                $parent->spanContext->traceFlags,
            );
            $builder = $builder->setParent(
                OtelContext::getRoot()->withContextValue(OtelApiSpan::wrap($remote)),
            );
        }

        $span = $builder->startSpan();

        return new OtelSpan($span, $span->activate());
    }

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
