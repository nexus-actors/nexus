<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Outbox;

use Override;

/**
 * @psalm-api
 *
 * In-memory unit of work. `begin()` is a no-op because there is no real
 * transaction boundary; the surrounding application code is the boundary.
 * `commit()` delegates to `Outbox::flush()`; `rollback()` delegates to
 * `Outbox::discard()`.
 */
final readonly class InMemoryUnitOfWork implements UnitOfWork
{
    public function __construct(private Outbox $outbox) {}

    #[Override]
    public function begin(): void
    {
        // No-op — in-memory UoW has no transactional boundary to open. Persistent
        // implementations open the database transaction here.
    }

    #[Override]
    public function commit(): void
    {
        $this->outbox->flush();
    }

    #[Override]
    public function rollback(): void
    {
        $this->outbox->discard();
    }

    #[Override]
    public function outbox(): Outbox
    {
        return $this->outbox;
    }
}
