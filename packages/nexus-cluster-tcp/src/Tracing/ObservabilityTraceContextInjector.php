<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tracing;

use Monadial\Nexus\Cluster\Tcp\Messaging\TraceContextInjector;
use Monadial\Nexus\Observability\Observability;
use Override;

/**
 * @psalm-api
 *
 * W3C trace-context injector backed by the Nexus Observability propagator.
 *
 * Serializes the current active span's context into W3C `traceparent`
 * (and `tracestate` when non-empty) headers so the receiver can parent its
 * `cluster.receive` span to the sender's trace. Returns `[]` when no valid
 * context is active or observability is disabled.
 */
final readonly class ObservabilityTraceContextInjector implements TraceContextInjector
{
    public function __construct(private Observability $observability) {}

    /**
     * @return array<string, string>
     */
    #[Override]
    public function inject(): array
    {
        if (!$this->observability->isEnabled()) {
            return [];
        }

        $carrier = [];

        $this->observability->propagator()->inject(
            $this->observability->currentContext(),
            $carrier,
        );

        return $carrier;
    }
}
