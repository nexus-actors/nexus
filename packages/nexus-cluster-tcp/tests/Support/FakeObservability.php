<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Support;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\ContextPropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Tracer;
use Override;

/**
 * Test double for {@see Observability}.
 *
 * Uses the real W3C {@see TraceContextPropagator} so inject/extract round-trips
 * produce valid `traceparent` headers. The current context is settable via
 * {@see withContext()}.
 */
final class FakeObservability implements Observability
{
    private Context $context;

    public function __construct(private readonly Tracer $tracer, ?Context $context = null)
    {
        $this->context = $context ?? Context::root();
    }

    public function withContext(Context $context): void
    {
        $this->context = $context;
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
        return new NoopMeter();
    }

    #[Override]
    public function propagator(): ContextPropagator
    {
        return new TraceContextPropagator();
    }

    #[Override]
    public function currentContext(): Context
    {
        return $this->context;
    }

    #[Override]
    public function shutdown(): void
    {
        // no-op test double
    }
}
