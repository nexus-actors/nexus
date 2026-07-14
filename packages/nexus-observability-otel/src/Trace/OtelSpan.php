<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Trace;

use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanContext;
use Monadial\Nexus\Observability\Trace\StatusCode;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode as OtelStatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Override;
use Throwable;

/**
 * @psalm-api
 *
 * Adapts an OpenTelemetry {@see SpanInterface} to the Nexus {@see Span} contract.
 * If constructed with the activation {@see ScopeInterface}, {@see self::end()}
 * detaches it before ending the span.
 */
final readonly class OtelSpan implements Span
{
    public function __construct(private SpanInterface $span, private ?ScopeInterface $scope = null,) {}

    /**
     * @psalm-suppress ArgumentTypeCoercion the OTel SDK requires a non-empty-string key; the
     *                 framework Span contract accepts any string, so the key is forwarded as-is.
     */
    #[Override]
    public function setAttribute(string $key, string|int|float|bool $value): void
    {
        $this->span->setAttribute($key, $value);
    }

    /**
     * @psalm-suppress InvalidArgument the OTel SDK requires non-empty-string keys; the framework
     *                 Span contract accepts any string key, so keys are forwarded as-is.
     */
    #[Override]
    public function setAttributes(array $attributes): void
    {
        $this->span->setAttributes($attributes);
    }

    #[Override]
    public function addEvent(string $name, array $attributes = []): void
    {
        $this->span->addEvent($name, $attributes);
    }

    #[Override]
    public function recordException(Throwable $exception): void
    {
        $this->span->recordException($exception);
    }

    #[Override]
    public function setStatus(StatusCode $code, ?string $description = null): void
    {
        $otelCode = match ($code) {
            StatusCode::Error => OtelStatusCode::STATUS_ERROR,
            StatusCode::Ok => OtelStatusCode::STATUS_OK,
            StatusCode::Unset => OtelStatusCode::STATUS_UNSET,
        };

        $this->span->setStatus($otelCode, $description);
    }

    #[Override]
    public function end(): void
    {
        $this->scope?->detach();
        $this->span->end();
    }

    #[Override]
    public function context(): SpanContext
    {
        $context = $this->span->getContext();
        $traceState = $context->getTraceState();

        return new SpanContext(
            traceId: $context->getTraceId(),
            spanId: $context->getSpanId(),
            traceFlags: $context->getTraceFlags(),
            remote: $context->isRemote(),
            traceState: $traceState !== null
                ? (string) $traceState
                : '',
        );
    }
}
