<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Locking;

use Closure;
use Monadial\Nexus\Persistence\PersistenceId;

/** @psalm-api */
interface PessimisticLockProvider
{
    /**
     * Execute callback while holding an exclusive lock on the persistence ID.
     *
     * The lock is acquired before the callback starts and released after it
     * returns (or throws). Implementations typically use a database transaction
     * with SELECT ... FOR UPDATE.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function withLock(PersistenceId $id, Closure $callback): mixed;
}
