<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Dbal;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Monadial\Nexus\Persistence\Locking\PessimisticLockProvider;
use Monadial\Nexus\Persistence\PersistenceId;
use Override;

/**
 * DBAL-based pessimistic lock provider using SELECT ... FOR UPDATE.
 *
 * Uses a dedicated `nexus_persistence_lock` table to acquire exclusive
 * row-level locks per persistence ID within a transaction.
 *
 * On SQLite, the transaction itself provides file-level locking,
 * so SELECT ... FOR UPDATE is skipped (not supported).
 *
 * @psalm-api
 */
final class DbalPessimisticLockProvider implements PessimisticLockProvider
{
    public function __construct(private readonly Connection $connection) {}

    #[Override]
    public function withLock(PersistenceId $id, Closure $callback): mixed
    {
        return $this->connection->transactional(function () use ($id, $callback): mixed {
            // Ensure lock row exists
            try {
                $this->connection->insert('nexus_persistence_lock', [
                    'persistence_id' => $id->toString(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Row already exists — expected on subsequent calls
            }

            // Acquire exclusive row lock (skip on SQLite — transaction provides isolation)
            if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
                $this->connection->fetchOne(
                    'SELECT 1 FROM nexus_persistence_lock WHERE persistence_id = ? FOR UPDATE',
                    [$id->toString()],
                );
            }

            return $callback();
        });
    }
}
