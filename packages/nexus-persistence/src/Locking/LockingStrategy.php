<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Locking;

use Closure;
use Monadial\Nexus\Persistence\PersistenceId;

/**
 * Configures concurrency control for persistent actors.
 *
 * - **Optimistic** (default) — Relies on version checks at write time.
 *   ConcurrentModificationException is thrown on conflict.
 * - **Pessimistic** — Acquires an exclusive database lock before command
 *   processing. Prevents conflicts entirely but introduces contention.
 *
 * @psalm-api
 */
final readonly class LockingStrategy
{
    private function __construct(private ?PessimisticLockProvider $lockProvider) {}

    public static function optimistic(): self
    {
        return new self(null);
    }

    public static function pessimistic(PessimisticLockProvider $lockProvider): self
    {
        return new self($lockProvider);
    }

    public function isPessimistic(): bool
    {
        return $this->lockProvider !== null;
    }

    /**
     * Execute callback within the appropriate locking context.
     *
     * Optimistic: calls callback directly (no lock).
     * Pessimistic: acquires exclusive lock, then calls callback.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function withLock(PersistenceId $id, Closure $callback): mixed
    {
        if ($this->lockProvider !== null) {
            return $this->lockProvider->withLock($id, $callback);
        }

        return $callback();
    }
}
