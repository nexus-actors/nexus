<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

/**
 * @psalm-api
 *
 * Outbound trace-propagation seam. `ClusterRef` populates `MessagePayload.trace` from
 * this source so a Symfony → broker → NexusA → TCP → NexusB call is one trace. The C1
 * default is a no-op returning `[]`; C1.7 plugs in real W3C inject.
 */
interface TraceContextInjector
{
    /**
     * @return array<string, string> W3C propagation headers (traceparent/tracestate).
     */
    public function inject(): array;
}
