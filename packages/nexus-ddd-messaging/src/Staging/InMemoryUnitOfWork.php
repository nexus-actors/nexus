<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Staging;

use Override;

/**
 * In-memory unit of work. `begin()` is a no-op because there is no real
 * transaction boundary; the surrounding application code is the boundary.
 * `commit()` delegates to `MessageStaging::flush()`; `rollback()` delegates
 * to `MessageStaging::discard()`.
 */
final readonly class InMemoryUnitOfWork implements UnitOfWork
{
    public function __construct(private MessageStaging $staging) {}

    #[Override]
    public function begin(): void {}

    #[Override]
    public function commit(): void
    {
        $this->staging->flush();
    }

    #[Override]
    public function rollback(): void
    {
        $this->staging->discard();
    }

    #[Override]
    public function staging(): MessageStaging
    {
        return $this->staging;
    }
}
