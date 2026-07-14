<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Serialization\Tests\Support;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\ContextPropagator;
use Monadial\Nexus\Observability\Context\NoopContextPropagator;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Tracer;
use Override;

final readonly class RecordingObservability implements Observability
{
    public RecordingTracer $tracer;

    public RecordingMeter $meter;

    private ContextPropagator $contextPropagator;

    public function __construct(private bool $enabled = true, ?ContextPropagator $contextPropagator = null)
    {
        $this->tracer = new RecordingTracer();
        $this->meter = new RecordingMeter();
        $this->contextPropagator = $contextPropagator ?? new NoopContextPropagator();
    }

    #[Override]
    public function isEnabled(): bool
    {
        return $this->enabled;
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
        return $this->contextPropagator;
    }

    #[Override]
    public function currentContext(): Context
    {
        return Context::root();
    }

    #[Override]
    public function shutdown(): void
    {
        // No-op recording double — shutdown is not tested.
    }
}
