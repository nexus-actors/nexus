<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Override;

/**
 * @psalm-api
 *
 * No-op {@see TraceContextInjector} — the C1 default. Emits no propagation headers.
 */
final readonly class NoopTraceContextInjector implements TraceContextInjector
{
    /**
     * @return array<string, string>
     */
    #[Override]
    public function inject(): array
    {
        return [];
    }
}
