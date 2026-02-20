<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\State;

use Monadial\Nexus\Persistence\PersistenceId;
use Override;

/** @psalm-api */
final class InMemoryDurableStateStore implements DurableStateStore
{
    /** @var array<string, DurableStateEnvelope> */
    private array $states = [];

    #[Override]
    public function get(PersistenceId $id): ?DurableStateEnvelope
    {
        return $this->states[$id->toString()] ?? null;
    }

    #[Override]
    public function upsert(PersistenceId $id, DurableStateEnvelope $state): void
    {
        $this->states[$id->toString()] = $state;
    }

    #[Override]
    public function delete(PersistenceId $id): void
    {
        unset($this->states[$id->toString()]);
    }
}
