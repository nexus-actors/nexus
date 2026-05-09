<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Snapshot;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Override;

/**
 * @psalm-api
 *
 * In-memory implementation. TESTS-ONLY (and single-process Fiber-only).
 * Stores only the latest snapshot per stream — `save()` overwrites the
 * existing slot. `delete()` clears the slot when its sequence number
 * is at or below `$upToSequenceNr`.
 */
final class InMemorySnapshotStore implements SnapshotStore
{
    /** @var array<string, Snapshot> */
    private array $snapshots = [];

    #[Override]
    public function save(Snapshot $snapshot): void
    {
        $this->snapshots[$snapshot->streamId->toString()] = $snapshot;
    }

    /** @return Option<Snapshot> */
    #[Override]
    public function load(AggregateStreamId $streamId): Option
    {
        return Option::fromNullable($this->snapshots[$streamId->toString()] ?? null);
    }

    #[Override]
    public function delete(AggregateStreamId $streamId, int $upToSequenceNr): void
    {
        $key = $streamId->toString();

        if (!isset($this->snapshots[$key])) {
            return;
        }

        if ($this->snapshots[$key]->sequenceNr <= $upToSequenceNr) {
            unset($this->snapshots[$key]);
        }
    }
}
