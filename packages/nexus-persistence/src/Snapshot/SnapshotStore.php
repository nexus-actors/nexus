<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Snapshot;

use Monadial\Nexus\Persistence\PersistenceId;

/** @psalm-api */
interface SnapshotStore
{
    public function save(PersistenceId $id, SnapshotEnvelope $snapshot): void;

    public function load(PersistenceId $id): ?SnapshotEnvelope;

    public function delete(PersistenceId $id, int $maxSequenceNr): void;
}
