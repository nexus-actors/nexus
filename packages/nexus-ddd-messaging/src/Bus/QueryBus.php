<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Bus;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Messaging\Message\Query;
use NoDiscard;
use Throwable;

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

    /**
     * Lifts query failures into Either::left instead of throwing. Boot-time
     * invariants (BusInvariantException) still propagate.
     *
     * @template TResult
     * @param Query<TResult> $query
     * @return Either<Throwable, TResult>
     */
    #[NoDiscard('tryAsk returns Either; ignoring the result discards both the value and the error')]
    public function tryAsk(Query $query): Either;
}
