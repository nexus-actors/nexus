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

/**
 * @psalm-api
 *
 * Zero-overhead default provider. Wires up no-op collaborators via constructor
 * injection; all method calls cost nothing when observability is disabled.
 */
final class NoopObservability implements Observability
{
    public function __construct(
        private readonly Tracer $tracer = new NoopTracer(),
        private readonly Meter $meter = new NoopMeter(),
        private readonly ContextPropagator $propagator = new NoopContextPropagator(),
    ) {}

    public function isEnabled(): bool
    {
        return false;
    }

    public function tracer(): Tracer
    {
        return $this->tracer;
    }

    public function meter(): Meter
    {
        return $this->meter;
    }

    public function propagator(): ContextPropagator
    {
        return $this->propagator;
    }

    public function currentContext(): Context
    {
        return Context::root();
    }

    public function shutdown(): void {}
}
