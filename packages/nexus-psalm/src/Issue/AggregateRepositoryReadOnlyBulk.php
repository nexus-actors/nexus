<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class AggregateRepositoryReadOnlyBulk extends PluginIssue
{
    public function __construct(string $repositoryClass, string $methodName, CodeLocation $location)
    {
        parent::__construct(
            sprintf(
                'AggregateRepository method %s::%s() returns iterable but is not marked '
                . '#[BulkCommand(...)]. Repositories serve command-side loading only — read-only '
                . 'collection queries must go through QueryBus + projection tables. If this is an '
                . 'intentional command-side bulk loader (e.g. inBatch(BatchId)), annotate it with '
                . '#[BulkCommand("…justification…")].',
                $repositoryClass,
                $methodName,
            ),
            $location,
        );
    }
}
