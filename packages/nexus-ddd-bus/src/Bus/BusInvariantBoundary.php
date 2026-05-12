<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Bus;

use Closure;
use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Bus\Exception\BusInvariantException;
use Throwable;

/**
 * @psalm-api
 *
 * Shared `try/catch` boundary for the `Sync*Bus::try*` methods. Propagates
 * `BusInvariantException` (boot-time misconfiguration is not a domain
 * failure, per H5) and lifts every other `Throwable` to `Either::left`.
 *
 * Three call sites (`SyncCommandBus::tryDispatch`, `SyncQueryBus::tryAsk`,
 * `SyncEventBus::tryPublish`) previously duplicated this block; centralizing
 * keeps the H5 invariant one-line-fixable.
 */
final class BusInvariantBoundary
{
    /**
     * @template TRight
     *
     * @param Closure(): TRight $run
     * @return Either<Throwable, TRight>
     */
    public static function tryRun(Closure $run): Either
    {
        try {
            /** @var TRight $result */
            $result = $run();

            /** @psalm-suppress InvalidReturnStatement */
            return Either::right($result);
        } catch (Throwable $e) {
            if ($e instanceof BusInvariantException) {
                throw $e;
            }

            /** @psalm-suppress InvalidReturnStatement */
            return Either::left($e);
        }
    }
}
