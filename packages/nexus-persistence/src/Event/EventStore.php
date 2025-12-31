<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Event;

use Monadial\Nexus\Persistence\PersistenceId;

interface EventStore
{
    public function persist(PersistenceId $id, EventEnvelope ...$events): void;

    /** @return iterable<EventEnvelope> */
    public function load(PersistenceId $id, int $fromSequenceNr = 0, int $toSequenceNr = PHP_INT_MAX): iterable;

    public function deleteUpTo(PersistenceId $id, int $toSequenceNr): void;

    public function highestSequenceNr(PersistenceId $id): int;
}
