<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support\Observability;

use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\ContextPropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Tracer;

final readonly class RecordingObservability implements Observability
{
    private RecordingTracer $recordingTracer;

    private RecordingMeter $recordingMeter;

    private ContextPropagator $propagator;

    public function __construct()
    {
        $this->recordingTracer = new RecordingTracer();
        $this->recordingMeter = new RecordingMeter();
        $this->propagator = new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]);
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function tracer(): Tracer
    {
        return $this->recordingTracer;
    }

    public function meter(): Meter
    {
        return $this->recordingMeter;
    }

    public function propagator(): ContextPropagator
    {
        return $this->propagator;
    }

    public function currentContext(): Context
    {
        $spanContext = $this->recordingTracer->currentSpanContext();

        return $spanContext->isValid()
            ? Context::fromSpanContext($spanContext)
            : Context::root();
    }

    public function shutdown(): void
    {
        // no-op
    }

    /** @return list<RecordedSpan> */
    public function spans(): array
    {
        return $this->recordingTracer->spans;
    }

    /** @return list<RecordedMetric> */
    public function metrics(): array
    {
        return $this->recordingMeter->metrics;
    }
}
