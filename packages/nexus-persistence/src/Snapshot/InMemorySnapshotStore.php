<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Snapshot;

use Monadial\Nexus\Persistence\PersistenceId;
use Override;

/** @psalm-api */
final class InMemorySnapshotStore implements SnapshotStore
{
    /** @var array<string, list<SnapshotEnvelope>> */
    private array $snapshots = [];

    #[Override]
    public function save(PersistenceId $id, SnapshotEnvelope $snapshot): void
    {
        $key = $id->toString();

        if (!isset($this->snapshots[$key])) {
            $this->snapshots[$key] = [];
        }

        $this->snapshots[$key][] = $snapshot;
    }

    #[Override]
    public function load(PersistenceId $id): ?SnapshotEnvelope
    {
        $key = $id->toString();

        if (!isset($this->snapshots[$key]) || $this->snapshots[$key] === []) {
            return null;
        }

        // Return the latest snapshot (last in the list, which has the highest sequenceNr)
        return $this->snapshots[$key][array_key_last($this->snapshots[$key])];
    }

    #[Override]
    public function delete(PersistenceId $id, int $maxSequenceNr): void
    {
        $key = $id->toString();

        if (!isset($this->snapshots[$key])) {
            return;
        }

        $this->snapshots[$key] = array_values(
            array_filter(
                $this->snapshots[$key],
                static fn(SnapshotEnvelope $s): bool => $s->sequenceNr > $maxSequenceNr,
            ),
        );
    }
}
