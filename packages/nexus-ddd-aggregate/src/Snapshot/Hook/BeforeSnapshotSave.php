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
final readonly class BeforeSnapshotSave extends SnapshotHookEvent
{
    public function __construct(AggregateStreamId $streamId, public Snapshot $snapshot)
    {
        parent::__construct($streamId);
    }
}
