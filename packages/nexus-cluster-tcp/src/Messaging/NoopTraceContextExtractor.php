<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Override;

/**
 * @psalm-api
 *
 * No-op {@see TraceContextExtractor} — the C1 default. Discards the propagation headers.
 */
final readonly class NoopTraceContextExtractor implements TraceContextExtractor
{
    /**
     * @param array<string, string> $trace
     */
    #[Override]
    public function extract(array $trace): void
    {
        // no-op: C1.7 supplies the real W3C extract implementation.
    }
}
