<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\State;

use Monadial\Nexus\Persistence\PersistenceId;

/** @psalm-api */
interface DurableStateStore
{
    public function get(PersistenceId $id): ?DurableStateEnvelope;

    public function upsert(PersistenceId $id, DurableStateEnvelope $state): void;

    public function delete(PersistenceId $id): void;
}
