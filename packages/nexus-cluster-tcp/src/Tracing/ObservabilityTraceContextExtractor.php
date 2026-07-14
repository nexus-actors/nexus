<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tracing;

use Monadial\Nexus\Cluster\Tcp\Messaging\TraceContextExtractor;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Observability;
use Override;

/**
 * @psalm-api
 *
 * W3C trace-context extractor backed by the Nexus Observability propagator.
 *
 * Parses W3C `traceparent`/`tracestate` headers from the inbound payload's
 * trace map into a {@see Context} that the `cluster.receive` span uses as its
 * remote parent, linking the receiver's work to the sender's trace. Returns
 * {@see Context::root()} when headers are absent or observability is disabled.
 */
final readonly class ObservabilityTraceContextExtractor implements TraceContextExtractor
{
    public function __construct(private Observability $observability) {}

    /**
     * @param array<string, string> $trace
     */
    #[Override]
    public function extract(array $trace): Context
    {
        if (!$this->observability->isEnabled()) {
            return Context::root();
        }

        return $this->observability->propagator()->extract($trace);
    }
}
