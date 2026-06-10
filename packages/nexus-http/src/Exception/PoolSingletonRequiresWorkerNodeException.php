<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

use function implode;

/**
 * @psalm-api
 *
 * Thrown at compile time when an actor is registered as PoolSingleton but
 * no WorkerNode was attached to HttpApp. Lists the offending actor names so
 * the user can either wire a worker pool or downgrade them to workerLocal().
 */
final class PoolSingletonRequiresWorkerNodeException extends NexusException
{
    /** @param list<string> $names */
    public function __construct(array $names)
    {
        $list = implode(', ', $names);

        parent::__construct(
            "Pool-singleton actor(s) [{$list}] declared, but no WorkerNode "
            . 'was attached to HttpApp. Wire nexus-worker-pool-swoole, or '
            . 'change the mode to workerLocal().',
        );
    }
}
