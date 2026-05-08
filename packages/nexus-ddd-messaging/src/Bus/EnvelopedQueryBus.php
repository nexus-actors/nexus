<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Bus;

use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Message\Query;

/**
 * @psalm-api
 *
 * @internal Framework-facing — used by `MessageStaging` flush, DLQ replay,
 *           and transport recovery. Domain code uses `QueryBus` directly.
 */
interface EnvelopedQueryBus extends QueryBus
{
    /**
     * @template TResult
     * @param Envelope<Query<TResult>> $envelope
     * @return TResult
     */
    public function dispatchEnveloped(Envelope $envelope): mixed;
}
