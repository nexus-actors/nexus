<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Bus;

use Monadial\Nexus\Ddd\Messaging\Message\Query;

/**
 * @psalm-api
 *
 * Public query-dispatch contract. Returns the typed result of the
 * matching `QueryHandler<TResult>` implementation.
 */
interface QueryBus
{
    /**
     * @template TResult
     * @param Query<TResult> $query
     * @return TResult
     */
    public function dispatchQuery(Query $query): mixed;
}
