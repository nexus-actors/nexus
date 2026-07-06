<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

/**
 * @psalm-api
 *
 * Inbound trace-propagation seam. `InboxRouter` passes the deserialized `trace` map to
 * this hook so the inbound span can be linked to the caller's trace. The C1 default is a
 * no-op; C1.7 plugs in real W3C extract.
 */
interface TraceContextExtractor
{
    /**
     * @param array<string, string> $trace W3C propagation headers carried on the payload.
     */
    public function extract(array $trace): void;
}
