<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Outbox;

/**
 * @psalm-api
 *
 * Coordinates a transaction boundary with the outbox. `begin()` opens the
 * boundary; `commit()` flushes outbox messages then commits; `rollback()`
 * discards outbox messages without dispatching.
 *
 * The outbox() accessor lets domain code append messages during the open
 * transaction without knowing whether the outer scope will commit or
 * roll back.
 */
interface UnitOfWork
{
    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    public function outbox(): Outbox;
}
