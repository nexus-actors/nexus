<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Snapshot;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;

/**
 * @psalm-api
 *
 * Snapshot store contract. DDD-owned, standalone. Snapshots are
 * derived projections of the event stream; the store may be lossy
 * or eventually-consistent. Per v6 §25.6.4 snapshot writes happen
 * AFTER the corresponding events commit, in a separate transaction.
 *
 * `load()` returns `Option<Snapshot>` — None when no snapshot exists
 * or the latest is unreadable. The Option contract reflects this
 * package's no-null rule.
 */
interface SnapshotStore
{
    public function save(Snapshot $snapshot): void;

    /** @return Option<Snapshot> */
    public function load(AggregateStreamId $streamId): Option;

    public function delete(AggregateStreamId $streamId, int $upToSequenceNr): void;
}
