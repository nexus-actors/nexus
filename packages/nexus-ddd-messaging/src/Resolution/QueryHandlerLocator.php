<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Resolution;

use Monadial\Nexus\Ddd\Messaging\Exception\HandlerNotFoundException;
use Monadial\Nexus\Ddd\Messaging\Handler\QueryHandler;
use Monadial\Nexus\Ddd\Messaging\Message\Query;

/**
 * @psalm-api
 *
 * @throws HandlerNotFoundException
 */
interface QueryHandlerLocator
{
    /**
     * @template TResult
     * @param Query<TResult> $query
     */
    public function locate(Query $query): QueryHandler;
}
