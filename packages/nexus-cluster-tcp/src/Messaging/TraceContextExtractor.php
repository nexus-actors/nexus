<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Monadial\Nexus\Observability\Context\Context;

/**
 * @psalm-api
 *
 * Inbound trace-propagation seam. `InboxRouter` passes the deserialized `trace` map to
 * this hook and receives the parsed propagation {@see Context} back, so the inbound
 * `cluster.receive` span can be parented to the caller's trace. The C1 default is the
 * {@see NoopTraceContextExtractor} which always returns {@see Context::root()}.
 */
interface TraceContextExtractor
{
    /**
     * @param array<string, string> $trace W3C propagation headers carried on the payload.
     */
    public function extract(array $trace): Context;
}
