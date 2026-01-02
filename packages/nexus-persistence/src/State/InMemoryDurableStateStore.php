<?php
declare(strict_types=1);

namespace Monadial\Nexus\Persistence\State;

use Monadial\Nexus\Persistence\PersistenceId;

final class InMemoryDurableStateStore implements DurableStateStore
{
    /** @var array<string, DurableStateEnvelope> */
    private array $states = [];

    public function get(PersistenceId $id): ?DurableStateEnvelope
    {
        return $this->states[$id->toString()] ?? null;
    }

    public function upsert(PersistenceId $id, DurableStateEnvelope $state): void
    {
        $this->states[$id->toString()] = $state;
    }

    public function delete(PersistenceId $id): void
    {
        unset($this->states[$id->toString()]);
    }
}
