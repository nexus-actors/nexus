<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Monadial\Nexus\Observability\Context\Context;
use Override;

/**
 * @psalm-api
 *
 * No-op {@see TraceContextExtractor} — the C1 default. Discards the propagation headers
 * and returns {@see Context::root()} so the `cluster.receive` span has no remote parent.
 */
final readonly class NoopTraceContextExtractor implements TraceContextExtractor
{
    /**
     * @param array<string, string> $trace
     */
    #[Override]
    public function extract(array $trace): Context
    {
        return Context::root();
    }
}
