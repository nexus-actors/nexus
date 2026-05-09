<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Snapshot;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Dispatched before SnapshotStore::save() persists a snapshot.
 */
final readonly class BeforeSnapshotSave
{
    public function __construct(public AggregateStreamId $streamId, public Snapshot $snapshot) {}
}
