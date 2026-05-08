<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Staging;

/**
 * @psalm-api
 *
 * Coordinates a transaction boundary with outbox-style message staging.
 * `begin()` opens the boundary; `commit()` flushes staged messages then
 * commits; `rollback()` discards staged messages without dispatching.
 *
 * The staging() accessor lets domain code append messages during the
 * open transaction without knowing whether the outer scope will commit
 * or roll back.
 */
interface UnitOfWork
{
    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    public function staging(): MessageStaging;
}
