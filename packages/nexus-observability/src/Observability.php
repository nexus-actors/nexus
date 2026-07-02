<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\ContextPropagator;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Trace\Tracer;

/**
 * @psalm-api
 *
 * Entry point bundling the three telemetry providers. Passed into the actor
 * system and satellite instrumentation; defaults to {@see NoopObservability}.
 */
interface Observability
{
    /**
     * Whether a real telemetry backend is wired. When false, instrumentation
     * call sites should skip work entirely (zero overhead).
     */
    public function isEnabled(): bool;

    public function tracer(): Tracer;

    public function meter(): Meter;

    public function propagator(): ContextPropagator;

    /**
     * Returns the ambient active context — the span currently in scope — for
     * injection into outgoing carriers (message metadata / HTTP headers).
     */
    public function currentContext(): Context;

    /**
     * Flush pending telemetry and stop the provider. Called once during
     * application shutdown; a no-op provider does nothing.
     */
    public function shutdown(): void;
}
