<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Support;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\ContextPropagator;
use Monadial\Nexus\Observability\Context\NoopContextPropagator;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\NoopTracer;
use Monadial\Nexus\Observability\Trace\Tracer;
use Override;

/**
 * Test double for {@see Observability} that records metric calls.
 *
 * Uses a {@see NoopTracer} by default — metrics tests do not need tracing.
 * Pass an explicit {@see Tracer} when a test also needs to assert span behaviour.
 */
final readonly class RecordingObservability implements Observability
{
    public RecordingMeter $meter;

    private Tracer $tracerInstance;

    public function __construct(Tracer $tracer = new NoopTracer(), private bool $enabled = true)
    {
        $this->tracerInstance = $tracer;
        $this->meter = new RecordingMeter();
    }

    #[Override]
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    #[Override]
    public function tracer(): Tracer
    {
        return $this->tracerInstance;
    }

    #[Override]
    public function meter(): Meter
    {
        return $this->meter;
    }

    #[Override]
    public function propagator(): ContextPropagator
    {
        return new NoopContextPropagator();
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
