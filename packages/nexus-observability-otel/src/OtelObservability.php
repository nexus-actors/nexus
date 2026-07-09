<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\ContextPropagator;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Otel\Metric\OtelMeter;
use Monadial\Nexus\Observability\Otel\Trace\OtelTracer;
use Monadial\Nexus\Observability\Trace\SpanContext;
use Monadial\Nexus\Observability\Trace\Tracer;
use OpenTelemetry\API\Trace\Span as OtelApiSpan;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @psalm-api
 *
 * OpenTelemetry-backed {@see Observability} provider. Owns the SDK providers so
 * telemetry can be flushed/shut down (wired into the actor-system lifecycle by a
 * later plan).
 */
final readonly class OtelObservability implements Observability
{
    private Tracer $tracer;

    private Meter $meter;

    private string $scope;

    public function __construct(
        private TracerProviderInterface $tracerProvider,
        private MeterProviderInterface $meterProvider,
        private ContextPropagator $propagator,
        string $instrumentationScope = 'nexus',
        private ?LoggerProviderInterface $loggerProvider = null,
    ) {
        $this->tracer = new OtelTracer($tracerProvider->getTracer($instrumentationScope));
        $this->meter = new OtelMeter($meterProvider->getMeter($instrumentationScope));
        $this->scope = $instrumentationScope;
    }

    /**
     * A PSR-3 logger that exports records over OTLP (correlated with the active trace).
     * Returns a no-op logger when logs are not configured. Not part of the {@see Observability}
     * contract — callers opt in by depending on the concrete OTel provider.
     */
    public function psrLogger(?string $name = null): LoggerInterface
    {
        if ($this->loggerProvider === null) {
            return new NullLogger();
        }

        return new OtelPsrLogger($this->loggerProvider->getLogger($name ?? $this->scope));
    }

    #[Override]
    public function isEnabled(): bool
    {
        return true;
    }

    #[Override]
    public function tracer(): Tracer
    {
        return $this->tracer;
    }

    #[Override]
    public function meter(): Meter
    {
        return $this->meter;
    }

    #[Override]
    public function propagator(): ContextPropagator
    {
        return $this->propagator;
    }

    #[Override]
    public function currentContext(): Context
    {
        $spanContext = OtelApiSpan::getCurrent()->getContext();

        if (!$spanContext->isValid()) {
            return Context::root();
        }

        $traceState = $spanContext->getTraceState();

        return Context::fromSpanContext(new SpanContext(
            traceId: $spanContext->getTraceId(),
            spanId: $spanContext->getSpanId(),
            traceFlags: $spanContext->getTraceFlags(),
            remote: $spanContext->isRemote(),
            traceState: $traceState !== null
                ? (string) $traceState
                : '',
        ));
    }

    public function forceFlush(): void
    {
        $this->tracerProvider->forceFlush();
        $this->meterProvider->forceFlush();
        $this->loggerProvider?->forceFlush();
    }

    #[Override]
    public function shutdown(): void
    {
        $this->tracerProvider->shutdown();
        $this->meterProvider->shutdown();
        $this->loggerProvider?->shutdown();
    }
}
