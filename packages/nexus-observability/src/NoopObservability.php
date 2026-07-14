<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\ContextPropagator;
use Monadial\Nexus\Observability\Context\NoopContextPropagator;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Observability\Trace\NoopTracer;
use Monadial\Nexus\Observability\Trace\Tracer;
use Override;

/**
 * @psalm-api
 *
 * Zero-overhead default provider. Wires up no-op collaborators via constructor
 * injection; all method calls cost nothing when observability is disabled.
 */
final readonly class NoopObservability implements Observability
{
    public function __construct(
        private Tracer $tracer = new NoopTracer(),
        private Meter $meter = new NoopMeter(),
        private ContextPropagator $propagator = new NoopContextPropagator(),
    ) {}

    #[Override]
    public function isEnabled(): bool
    {
        return false;
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
        return Context::root();
    }

    #[Override]
    public function shutdown(): void
    {
        // no-op — there is nothing to flush or close when observability is disabled.
    }
}
