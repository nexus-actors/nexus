<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

use function implode;

/** @psalm-api */
final class PoolSingletonRequiresSpawnerException extends NexusException
{
    /** @param list<string> $names */
    public function __construct(array $names)
    {
        $list = implode(', ', $names);

        parent::__construct(
            "Pool-singleton actor(s) [{$list}] declared, but no PoolSingletonSpawner "
            . 'was attached to HttpApp. Wire a server-package spawner, or change '
            . 'the mode to workerLocal().',
        );
    }
}
